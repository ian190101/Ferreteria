<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('items'))) {
            $this->merge(['items' => array_map(function ($item) {
                if (is_array($item) && is_string($item['new_lot_code'] ?? null)) {
                    $item['new_lot_code'] = strtoupper(trim($item['new_lot_code']));
                }
                return $item;
            }, $this->input('items'))]);
        }
    }
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.meters' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/D', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.kilograms' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/D', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.coil_barcode' => ['nullable', 'string', 'max:80', 'distinct', Rule::unique('product_coils', 'barcode')->whereNull('deleted_at')],
            'items.*.lot_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'items.*.new_lot_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/D', 'regex:/[A-Z0-9]/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $hasQuantity = false;
            $items = collect($this->input('items', []));
            $orderItems = PurchaseOrderItem::query()
                ->with('product.thickness')
                ->whereIn('id', $items->pluck('purchase_order_item_id')->filter()->unique()->values())
                ->get(['id', 'product_id', 'meters', 'received_meters'])
                ->keyBy('id');

            foreach ($items as $index => $item) {
                $orderItem = $orderItems->get($item['purchase_order_item_id'] ?? null);
                $policy = app(\App\Modules\Inventory\Services\LotTrackingPolicy::class);
                $managed = $orderItem && $policy->required($orderItem->product, $policy->activeConfiguration());
                $meters = (string) ($item['meters'] ?? '0');
                if ($managed) {
                    try {
                        $meters = app(\App\Modules\Purchases\Services\PurchaseReceiptService::class)->orderQuantity($orderItem, $item);
                    } catch (\InvalidArgumentException $exception) {
                        $validator->errors()->add("items.{$index}.meters", $exception->getMessage());
                        continue;
                    }
                }
                if (! preg_match('/^\d+(?:\.\d+)?$/D', $meters) || bccomp($meters, '0', 3) <= 0) {
                    continue;
                }

                $hasQuantity = true;

                if (! $orderItem) {
                    continue;
                }

                if ($managed) {
                    $hasLot = filled($item['lot_id'] ?? null);
                    $hasNewLot = filled($item['new_lot_code'] ?? null);
                    if ($hasLot === $hasNewLot) {
                        $validator->errors()->add("items.{$index}.lot_id", 'Elige un lote existente o indica un código nuevo.');
                    }
                    $quantities = app(\App\Modules\Inventory\Services\InventoryQuantityService::class);
                    try {
                        if ($quantities->compareExact($meters,
                            $quantities->subtractExact((string) $orderItem->meters, (string) $orderItem->received_meters)) > 0) {
                            $validator->errors()->add("items.{$index}.meters", 'La cantidad recibida supera el saldo pendiente.');
                        }
                    } catch (\InvalidArgumentException) {
                        $validator->errors()->add("items.{$index}.meters", 'Cantidad decimal inválida.');
                    }
                    continue;
                }
                if (filled($item['lot_id'] ?? null) || filled($item['new_lot_code'] ?? null)) {
                    $validator->errors()->add("items.{$index}.lot_id", 'El producto no tiene control de lotes activo.');
                }

                $pending = round((float) $orderItem->meters - (float) $orderItem->received_meters, 3);

                if ((float) $meters > $pending) {
                    $validator->errors()->add("items.{$index}.meters", 'La cantidad recibida supera el saldo pendiente de la orden.');
                }

                if ($orderItem->product->inventory_tracking_mode === Product::TRACKING_COIL && blank($item['coil_barcode'] ?? null)) {
                    $validator->errors()->add("items.{$index}.coil_barcode", 'La recepcion con rastreo individual requiere barcode unico.');
                }
            }

            if (! $hasQuantity) {
                $validator->errors()->add('items', 'Debes recibir al menos un item con metros mayores a cero.');
            }
        });
    }
}
