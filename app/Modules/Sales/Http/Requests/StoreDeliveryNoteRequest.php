<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\DeliveryDriver;
use App\Modules\Sales\Models\DeliveryTruck;
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
            'delivery_driver_id' => ['nullable', 'integer', 'exists:delivery_drivers,id'],
            'delivery_truck_id' => ['nullable', 'integer', 'exists:delivery_trucks,id'],
            'manual_driver' => ['nullable', 'boolean'],
            'manual_truck' => ['nullable', 'boolean'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_document' => ['nullable', 'string', 'max:80'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.meters' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'manual_driver' => $this->input('delivery_driver_id') === 'manual' || $this->boolean('manual_driver'),
            'manual_truck' => $this->input('delivery_truck_id') === 'manual' || $this->boolean('manual_truck'),
            'delivery_driver_id' => $this->input('delivery_driver_id') === 'manual' ? null : $this->input('delivery_driver_id'),
            'delivery_truck_id' => $this->input('delivery_truck_id') === 'manual' ? null : $this->input('delivery_truck_id'),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sale = Sale::query()->find($this->integer('sale_id'));

            if (! $sale || $sale->document_type !== 'sale_note' || $sale->status === 'void') {
                $validator->errors()->add('sale_id', 'Solo se pueden despachar notas de venta vigentes.');

                return;
            }

            if (! $sale->requires_delivery) {
                $validator->errors()->add('sale_id', 'Esta nota de venta fue registrada como entrega inmediata y no requiere despacho.');

                return;
            }

            if ($message = BranchAccess::validate($this->user(), (int) $sale->branch_id)) {
                $validator->errors()->add('sale_id', $message);

                return;
            }

            $this->validateDriverAndTruck($validator, $sale);

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
                $saleItem = $saleItems->get($itemId);
                $metersByItem[$itemId] = ($metersByItem[$itemId] ?? 0) + ($saleItem ? $this->quantityToBase($saleItem, $item) : 0);

                if (blank($item['quantity'] ?? null) && blank($item['meters'] ?? null)) {
                    $validator->errors()->add("items.{$index}.quantity", 'Ingrese la cantidad a despachar.');
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

    private function validateDriverAndTruck($validator, Sale $sale): void
    {
        if ($this->filled('delivery_driver_id')) {
            $driver = DeliveryDriver::query()->find($this->integer('delivery_driver_id'));

            if (! $driver || ! $driver->is_active || ($driver->branch_id && (int) $driver->branch_id !== (int) $sale->branch_id)) {
                $validator->errors()->add('delivery_driver_id', 'El conductor no esta activo o no corresponde a la sucursal de la nota.');
            }
        }

        if ($this->filled('delivery_truck_id')) {
            $truck = DeliveryTruck::query()->find($this->integer('delivery_truck_id'));

            if (! $truck || ! $truck->is_active || ($truck->branch_id && (int) $truck->branch_id !== (int) $sale->branch_id)) {
                $validator->errors()->add('delivery_truck_id', 'El camion no esta activo o no corresponde a la sucursal de la nota.');
            }
        }
    }

    private function quantityToBase(SaleItem $saleItem, array $item): float
    {
        if (filled($item['meters'] ?? null)) {
            return round((float) $item['meters'], 3);
        }

        $quantity = (float) ($item['quantity'] ?? 0);
        $displayQuantity = (float) $saleItem->display_quantity;

        if ($displayQuantity <= 0) {
            return round($quantity, 3);
        }

        return round($quantity * ((float) $saleItem->meters / $displayQuantity), 3);
    }
}
