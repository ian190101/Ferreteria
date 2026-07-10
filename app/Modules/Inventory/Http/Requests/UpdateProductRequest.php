<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Support\ProductCodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'custom_attributes.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean'])],
            'custom_attributes.*.value' => ['nullable', 'max:120'],
            'custom_attributes.*.has_unit' => ['nullable', 'boolean'],
            'custom_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'allowed_units' => ['nullable', 'array'],
            'allowed_units.*' => ['string', 'max:24'],
            'unit_conversions' => ['nullable', 'array'],
            'unit_conversions.*.product_unit_id' => ['required_with:unit_conversions', 'integer', 'exists:product_units,id'],
            'unit_conversions.*.factor_to_base' => ['required_with:unit_conversions', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'unit_conversions.*.is_active' => ['nullable', 'boolean'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'minimum_stock_meters' => ['required', 'numeric', 'min:0', 'max:999999999999.999'],
            'is_active' => ['required', 'boolean'],
            'branch_scope' => ['required', Rule::in(['global', 'specific'])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBranchScope($validator);
        });
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
            'unit_conversions' => $this->normalizedUnitConversions($unit?->id),
            'allowed_units' => $this->normalizedAllowedUnits($unit?->symbol),
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
                    'type' => in_array(($attribute['type'] ?? 'text'), ['text', 'number', 'boolean'], true) ? $attribute['type'] : 'text',
                    'value' => $this->normalizedCustomAttributeValue($attribute),
                    'has_unit' => filter_var($attribute['has_unit'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'unit' => filter_var($attribute['has_unit'] ?? false, FILTER_VALIDATE_BOOLEAN) && is_string($attribute['unit'] ?? null) ? trim($attribute['unit']) : '',
                ];
            })
            ->unique('code')
            ->values()
            ->all();
    }

    private function normalizedCustomAttributeValue(array $attribute): string
    {
        if (($attribute['type'] ?? 'text') === 'boolean') {
            if (($attribute['value'] ?? '') === '') {
                return '';
            }

            return filter_var($attribute['value'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return is_string($attribute['value'] ?? null) ? trim($attribute['value']) : (string) ($attribute['value'] ?? '');
    }

    private function normalizedAllowedUnits(?string $baseSymbol): array
    {
        $conversionUnitSymbols = ProductUnit::query()
            ->whereIn('id', collect($this->input('unit_conversions', []))->pluck('product_unit_id')->filter()->unique()->values())
            ->pluck('symbol');

        $symbols = ProductUnit::query()
            ->whereIn('symbol', collect($this->input('allowed_units', []))->merge($conversionUnitSymbols)->push($baseSymbol)->filter()->unique()->values())
            ->pluck('symbol')
            ->push($baseSymbol)
            ->filter()
            ->unique()
            ->values();

        return $symbols->all();
    }

    private function normalizedUnitConversions(?int $baseUnitId): array
    {
        return collect($this->input('unit_conversions', []))
            ->filter(fn ($row) => is_array($row) && filled($row['product_unit_id'] ?? null))
            ->map(fn (array $row) => [
                'product_unit_id' => (int) $row['product_unit_id'],
                'factor_to_base' => (float) ($row['factor_to_base'] ?? 1),
                'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ])
            ->reject(fn (array $row) => $baseUnitId && $row['product_unit_id'] === $baseUnitId)
            ->unique('product_unit_id')
            ->values()
            ->all();
    }

    private function validateBranchScope(Validator $validator): void
    {
        $branchIds = collect($this->input('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($this->input('branch_scope') === 'specific' && $branchIds->isEmpty()) {
            $validator->errors()->add('branch_ids', 'Seleccione al menos una sucursal para este producto.');
        }

        if ($this->user()?->isSuperAdministrator()) {
            return;
        }

        $allowed = collect($this->user()?->accessibleBranchIds() ?? []);
        $unauthorized = $branchIds->diff($allowed);

        if ($unauthorized->isNotEmpty()) {
            $validator->errors()->add('branch_ids', 'Solo puede asignar el producto a sus sucursales permitidas.');
        }
    }
}
