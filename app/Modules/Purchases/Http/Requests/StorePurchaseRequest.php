<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
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
            'document_number' => ['required', 'string', 'max:80'],
            'purchase_date' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,received'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.kilograms' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.meters' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.calculation_mode' => ['nullable', 'in:direct,length,weight'],
            'items.*.item_attributes' => ['nullable', 'array'],
            'items.*.item_attributes.*.code' => ['required_with:items.*.item_attributes', 'string', 'max:80'],
            'items.*.item_attributes.*.name' => ['required_with:items.*.item_attributes', 'string', 'max:120'],
            'items.*.item_attributes.*.value' => ['nullable', 'string', 'max:120'],
            'items.*.item_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0', 'max:999999999999.9999'],
            'items.*.lot_number' => ['nullable', 'string', 'max:80'],
            'items.*.coil_barcode' => ['nullable', 'string', 'max:80', 'distinct', 'unique:product_coils,barcode'],
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

            foreach ($this->input('items', []) as $index => $item) {
                $product = Product::query()->with('thickness')->find($item['product_id'] ?? null);

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
                        $validator->errors()->add("items.{$index}.lot_number", 'La bobina requiere numero de lote.');
                    }

                    if (blank($item['coil_barcode'] ?? null)) {
                        $validator->errors()->add("items.{$index}.coil_barcode", 'La bobina requiere barcode unico.');
                    }
                }
            }
        });
    }
}
