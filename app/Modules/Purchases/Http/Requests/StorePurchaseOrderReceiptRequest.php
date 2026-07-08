<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.meters' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.kilograms' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.coil_barcode' => ['nullable', 'string', 'max:80', 'distinct', Rule::unique('product_coils', 'barcode')->whereNull('deleted_at')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasQuantity = false;
            $items = collect($this->input('items', []));
            $orderItems = PurchaseOrderItem::query()
                ->with('product:id,inventory_tracking_mode')
                ->whereIn('id', $items->pluck('purchase_order_item_id')->filter()->unique()->values())
                ->get(['id', 'product_id', 'meters', 'received_meters'])
                ->keyBy('id');

            foreach ($items as $index => $item) {
                $meters = (float) ($item['meters'] ?? 0);

                if ($meters <= 0) {
                    continue;
                }

                $hasQuantity = true;
                $orderItem = $orderItems->get($item['purchase_order_item_id'] ?? null);

                if (! $orderItem) {
                    continue;
                }

                $pending = round((float) $orderItem->meters - (float) $orderItem->received_meters, 3);

                if ($meters > $pending) {
                    $validator->errors()->add("items.{$index}.meters", 'La cantidad recibida supera el saldo pendiente de la orden.');
                }

                if ($orderItem->product->inventory_tracking_mode === Product::TRACKING_COIL && blank($item['coil_barcode'] ?? null)) {
                    $validator->errors()->add("items.{$index}.coil_barcode", 'La recepcion por bobina requiere barcode unico.');
                }
            }

            if (! $hasQuantity) {
                $validator->errors()->add('items', 'Debes recibir al menos un item con metros mayores a cero.');
            }
        });
    }
}
