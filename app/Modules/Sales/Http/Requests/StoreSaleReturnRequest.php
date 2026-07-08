<?php

namespace App\Modules\Sales\Http\Requests;

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
                ->get(['id', 'meters'])
                ->keyBy('id');

            foreach ($this->input('items', []) as $index => $item) {
                $itemId = (int) ($item['sale_item_id'] ?? 0);
                $metersByItem[$itemId] = ($metersByItem[$itemId] ?? 0) + round((float) ($item['meters'] ?? 0), 3);

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

                $returned = (float) ($returnedByItem[$itemId] ?? 0);
                $availableToReturn = round((float) $saleItem->meters - $returned, 3);

                if ($meters > $availableToReturn) {
                    $validator->errors()->add('items', 'La devolucion supera el metraje disponible de uno o mas items.');
                }
            }
        });
    }
}
