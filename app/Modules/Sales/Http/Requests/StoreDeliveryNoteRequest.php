<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.deliveries.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'delivery_number' => ['required', 'string', 'max:80', 'unique:delivery_notes,delivery_number'],
            'delivered_at' => ['nullable', 'date'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_document' => ['nullable', 'string', 'max:80'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
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
                $validator->errors()->add('sale_id', 'Solo se pueden despachar notas de venta vigentes.');

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
            $deliveredByItem = DeliveryNoteItem::query()
                ->whereIn('sale_item_id', array_keys($metersByItem))
                ->selectRaw('sale_item_id, SUM(meters) as delivered_meters')
                ->groupBy('sale_item_id')
                ->pluck('delivered_meters', 'sale_item_id');

            foreach ($metersByItem as $itemId => $meters) {
                $saleItem = $saleItems->get($itemId);

                if (! $saleItem) {
                    continue;
                }

                $returned = (float) ($returnedByItem[$itemId] ?? 0);
                $delivered = (float) ($deliveredByItem[$itemId] ?? 0);
                $availableToDeliver = max(round((float) $saleItem->meters - $returned - $delivered, 3), 0);

                if ($meters > $availableToDeliver) {
                    $validator->errors()->add('items', 'El despacho supera el metraje pendiente de uno o mas items.');
                }
            }
        });
    }
}
