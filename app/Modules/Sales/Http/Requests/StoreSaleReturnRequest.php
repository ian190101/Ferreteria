<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.returns.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'return_number' => ['required', 'string', 'max:80', 'unique:sale_returns,return_number'],
            'returned_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sale = Sale::query()->find($this->integer('sale_id'));

            if (! $sale || $sale->document_type !== 'sale_note' || $sale->status === 'void') {
                $validator->errors()->add('sale_id', 'Solo se pueden devolver notas de venta vigentes.');

                return;
            }

            if ($message = BranchAccess::validate($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('sale_id', $message);

                return;
            }

            $metersByItem = [];
            $itemIds = collect($this->input('items', []))
                ->pluck('sale_item_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            $saleItems = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->whereIn('id', $itemIds)
                ->get(['id', 'meters', 'product_coil_id'])
                ->keyBy('id');
            $quantity = app(InventoryQuantityService::class);

            foreach ($this->input('items', []) as $index => $item) {
                $itemId = (int) ($item['sale_item_id'] ?? 0);
                $saleItem = $saleItems->get($itemId);
                if ($saleItem?->product_coil_id) {
                    try {
                        if (! is_numeric($item['meters'] ?? null)) {
                            throw new \InvalidArgumentException();
                        }
                        $metersByItem[$itemId] = $quantity->addExact(
                            (string) ($metersByItem[$itemId] ?? '0.000'), (string) $item['meters']);
                    } catch (\InvalidArgumentException) {
                        $validator->errors()->add("items.{$index}.meters", 'Ingrese un decimal válido sin notación exponencial.');
                        $metersByItem[$itemId] = (string) ($metersByItem[$itemId] ?? '0.000');
                    }
                } else {
                    $metersByItem[$itemId] = ($metersByItem[$itemId] ?? 0) + round((float) ($item['meters'] ?? 0), 3);
                }

                if (! $saleItems->has($itemId)) {
                    $validator->errors()->add("items.{$index}.sale_item_id", 'El item no pertenece a la nota seleccionada.');
                }
            }

            $returnedByItem = SaleReturnItem::query()
                ->whereIn('sale_item_id', array_keys($metersByItem))
                ->selectRaw('sale_item_id, SUM(meters) as returned_meters')
                ->groupBy('sale_item_id')
                ->pluck('returned_meters', 'sale_item_id');

            foreach ($metersByItem as $itemId => $meters) {
                $saleItem = $saleItems->get($itemId);

                if (! $saleItem) {
                    continue;
                }

                if ($saleItem->product_coil_id) {
                    $availableToReturn = $quantity->subtractExact((string) $saleItem->meters,
                        (string) ($returnedByItem[$itemId] ?? '0.000'));
                    $exceeded = $quantity->compareExact((string) $meters, $availableToReturn) > 0;
                } else {
                    $returned = (float) ($returnedByItem[$itemId] ?? 0);
                    $availableToReturn = round((float) $saleItem->meters - $returned, 3);
                    $exceeded = $meters > $availableToReturn;
                }

                if ($exceeded) {
                    $validator->errors()->add('items', 'La devolucion supera el metraje disponible de uno o mas items.');
                }
            }
        });
    }
}
