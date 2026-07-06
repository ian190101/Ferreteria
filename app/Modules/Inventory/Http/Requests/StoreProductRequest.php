<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductCategoryAttribute;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.products.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'thickness_id' => ['nullable', 'integer', 'exists:thicknesses,id'],
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:80'],
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'barcode' => ['required', 'string', 'max:80', 'unique:products,barcode'],
            'inventory_tracking_mode' => ['required', Rule::in([Product::TRACKING_GLOBAL, Product::TRACKING_COIL])],
            'base_unit' => ['required', 'string', 'max:24'],
            'attributes' => ['nullable', 'array'],
            'default_width' => ['nullable', 'numeric', 'gt:0', 'max:99999999.9999'],
            'minimum_stock_meters' => ['required', 'numeric', 'min:0', 'max:999999999999.999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateCategoryAttributes($validator);
        });
    }

    protected function prepareForValidation(): void
    {
        $category = ProductCategory::query()
            ->with('defaultUnit:id,name,symbol')
            ->find($this->integer('product_category_id'));
        $unit = ProductUnit::query()->find($this->integer('product_unit_id') ?: ($category?->default_unit_id ?? 0));

        $this->merge([
            'sku' => $this->filled('sku') ? $this->input('sku') : ProductCodeGenerator::sku($this->input('name')),
            'barcode' => $this->filled('barcode') ? $this->input('barcode') : ProductCodeGenerator::barcode(),
            'category' => $category?->name ?? ($this->filled('category') ? $this->input('category') : 'Ferreteria general'),
            'base_unit' => $unit?->symbol ?? ($this->filled('base_unit') ? $this->input('base_unit') : 'unidad'),
            'product_unit_id' => $unit?->id ?? $this->input('product_unit_id'),
            'attributes' => $this->normalizedAttributes(),
        ]);
    }

    private function validateCategoryAttributes(Validator $validator): void
    {
        $categoryId = $this->integer('product_category_id');

        if (! $categoryId) {
            return;
        }

        $definitions = ProductCategoryAttribute::query()
            ->where('product_category_id', $categoryId)
            ->where('is_active', true)
            ->get(['code', 'name', 'type', 'options', 'is_required']);
        $values = $this->input('attributes', []);

        foreach ($definitions as $definition) {
            $value = $values[$definition->code] ?? null;

            if ($definition->is_required && blank($value) && $value !== false && $value !== 0 && $value !== '0') {
                $validator->errors()->add("attributes.{$definition->code}", "La caracteristica {$definition->name} es obligatoria.");
                continue;
            }

            if (blank($value) && $value !== false && $value !== 0 && $value !== '0') {
                continue;
            }

            if ($definition->type === ProductCategoryAttribute::TYPE_NUMBER && ! is_numeric($value)) {
                $validator->errors()->add("attributes.{$definition->code}", "La caracteristica {$definition->name} debe ser numerica.");
            }

            if ($definition->type === ProductCategoryAttribute::TYPE_SELECT && ! in_array($value, $definition->options ?? [], true)) {
                $validator->errors()->add("attributes.{$definition->code}", "La caracteristica {$definition->name} no tiene una opcion valida.");
            }
        }
    }

    private function normalizedAttributes(): array
    {
        return collect($this->input('attributes', []))
            ->mapWithKeys(fn ($value, $key) => [Str::slug((string) $key, '_') => is_string($value) ? trim($value) : $value])
            ->all();
    }
}
