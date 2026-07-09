<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.products.manage') ?? false;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'thickness_id' => ['nullable', 'integer', 'exists:thicknesses,id'],
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:80'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['required', 'string', 'max:80', Rule::unique('products', 'barcode')->ignore($productId)],
            'inventory_tracking_mode' => ['required', Rule::in([Product::TRACKING_GLOBAL, Product::TRACKING_COIL])],
            'base_unit' => ['required', 'string', 'max:24'],
            'attributes' => ['nullable', 'array'],
            'custom_attributes' => ['nullable', 'array'],
            'custom_attributes.*.code' => ['nullable', 'string', 'max:80'],
            'custom_attributes.*.name' => ['required_with:custom_attributes', 'string', 'max:120'],
            'custom_attributes.*.value' => ['nullable', 'string', 'max:120'],
            'custom_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'default_width' => ['nullable', 'numeric', 'gt:0', 'max:99999999.9999'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'minimum_stock_meters' => ['required', 'numeric', 'min:0', 'max:999999999999.999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $productId = $this->route('product')?->id;
        $category = ProductCategory::query()
            ->with('defaultUnit:id,name,symbol')
            ->find($this->integer('product_category_id'));
        $unit = ProductUnit::query()->find($this->integer('product_unit_id') ?: ($category?->default_unit_id ?? 0));

        $this->merge([
            'sku' => $this->filled('sku') ? $this->input('sku') : ProductCodeGenerator::sku($this->input('name'), $productId),
            'barcode' => $this->filled('barcode') ? $this->input('barcode') : ProductCodeGenerator::barcode($productId),
            'category' => $category?->name ?? ($this->filled('category') ? $this->input('category') : 'Ferreteria general'),
            'base_unit' => $unit?->symbol ?? ($this->filled('base_unit') ? $this->input('base_unit') : 'unidad'),
            'product_unit_id' => $unit?->id ?? $this->input('product_unit_id'),
            'attributes' => $this->normalizedAttributes(),
            'custom_attributes' => $this->normalizedCustomAttributes(),
        ]);
    }

    private function normalizedAttributes(): array
    {
        return collect($this->input('attributes', []))
            ->mapWithKeys(fn ($value, $key) => [Str::slug((string) $key, '_') => is_string($value) ? trim($value) : $value])
            ->all();
    }

    private function normalizedCustomAttributes(): array
    {
        return collect($this->input('custom_attributes', []))
            ->filter(fn ($attribute) => is_array($attribute) && filled($attribute['name'] ?? null))
            ->map(function (array $attribute) {
                $name = trim((string) $attribute['name']);
                $code = filled($attribute['code'] ?? null)
                    ? Str::slug((string) $attribute['code'], '_')
                    : Str::slug($name, '_');

                return [
                    'code' => $code,
                    'name' => $name,
                    'value' => is_string($attribute['value'] ?? null) ? trim($attribute['value']) : ($attribute['value'] ?? ''),
                    'unit' => is_string($attribute['unit'] ?? null) ? trim($attribute['unit']) : '',
                ];
            })
            ->unique('code')
            ->values()
            ->all();
    }
}
