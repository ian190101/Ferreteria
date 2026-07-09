<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'order_number' => ['required', 'string', 'max:80', Rule::unique('purchase_orders', 'order_number')->whereNull('deleted_at')],
            'ordered_at' => ['required', 'date'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'status' => ['required', 'in:'.PurchaseOrder::STATUS_DRAFT.','.PurchaseOrder::STATUS_APPROVED],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.kilograms' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.meters' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0', 'max:999999999999.9999'],
            'items.*.lot_number' => ['nullable', 'string', 'max:80'],
            'items.*.coil_barcode' => ['nullable', 'string', 'max:80', 'distinct', Rule::unique('product_coils', 'barcode')->whereNull('deleted_at'), Rule::unique('purchase_order_items', 'coil_barcode')],
            'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            $items = collect($this->input('items', []));
            $products = Product::query()
                ->with('thickness:id')
                ->whereIn('id', $items->pluck('product_id')->filter()->unique()->values())
                ->get(['id', 'thickness_id', 'inventory_tracking_mode'])
                ->keyBy('id');

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);

                if (! $product) {
                    continue;
                }

                if (blank($item['meters'] ?? null) && blank($item['kilograms'] ?? null)) {
                    $validator->errors()->add("items.{$index}.meters", 'Debes ingresar metros o peso.');
                }

                if (blank($item['meters'] ?? null) && filled($item['kilograms'] ?? null) && ! $product->thickness) {
                    $validator->errors()->add("items.{$index}.kilograms", 'El producto necesita espesor para convertir peso a metros.');
                }

                if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                    if (blank($item['lot_number'] ?? null)) {
                        $validator->errors()->add("items.{$index}.lot_number", 'El rastreo individual requiere numero de lote para poder recibirse.');
                    }

                    if (blank($item['coil_barcode'] ?? null)) {
                        $validator->errors()->add("items.{$index}.coil_barcode", 'El rastreo individual requiere barcode unico para poder recibirse.');
                    }
                }
            }
        });
    }
}
