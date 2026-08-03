<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\ProductWorkflowPolicy;
use App\Modules\Inventory\Support\ProductCodeGenerator;
use App\Modules\SystemSuperadmin\Models\ProductVariant;
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
            'barcode' => [app(ProductWorkflowPolicy::class)->barcodeRequired() ? 'required' : 'nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->ignore($productId)],
            'inventory_tracking_mode' => ['required', Rule::in([Product::TRACKING_GLOBAL, Product::TRACKING_COIL])],
            'base_unit' => ['required', 'string', 'max:24'],
            'item_type' => ['required', 'string', Rule::in(app(ProductWorkflowPolicy::class)->allowedItemTypes())],
            'service_type_id' => ['nullable', 'integer', 'exists:service_types,id'],
            'is_sellable' => ['required', 'boolean'],
            'is_purchasable' => ['required', 'boolean'],
            'is_inventory_item' => ['required', 'boolean'],
            'is_consumable' => ['required', 'boolean'],
            'is_prepared' => ['required', 'boolean'],
            'is_digital' => ['required', 'boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'preparation_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'attributes' => ['nullable', 'array'],
            'catalog_settings' => ['nullable', 'array'],
            'requires_lot' => ['required', 'boolean'],
            'requires_expiration_date' => ['required', 'boolean'],
            'is_rentable' => ['required', 'boolean'],
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
            'images' => ['nullable', 'array'],
            'images.*.id' => ['nullable', 'integer', 'exists:product_images,id'],
            'images.*.url' => ['nullable', 'url', 'max:2048'],
            'images.*.path' => ['nullable', 'string', 'max:512'],
            'images.*.alt_text' => ['nullable', 'string', 'max:180'],
            'images.*.is_primary' => ['nullable', 'boolean'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'variants.*.sku' => ['nullable', 'string', 'max:160'],
            'variants.*.barcode' => ['nullable', 'string', 'max:160'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:180'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'variants.*.sale_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBranchScope($validator);
            $this->validateFlexibleCatalog($validator);
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
            'item_type' => $this->normalizedItemType(),
            'service_type_id' => $this->normalizedItemType() === 'service' ? $this->input('service_type_id') : null,
            'is_sellable' => $this->boolean('is_sellable', true),
            'is_purchasable' => $this->boolean('is_purchasable', true),
            'is_inventory_item' => $this->boolean('is_inventory_item', $this->normalizedItemType() !== 'service' && $this->normalizedItemType() !== 'digital'),
            'is_consumable' => $this->boolean('is_consumable', $this->normalizedItemType() === 'internal_supply'),
            'is_prepared' => $this->boolean('is_prepared', in_array($this->normalizedItemType(), ['prepared_product', 'finished_product'], true)),
            'is_digital' => $this->boolean('is_digital', $this->normalizedItemType() === 'digital'),
            'duration_minutes' => $this->filled('duration_minutes') ? $this->integer('duration_minutes') : null,
            'preparation_minutes' => $this->filled('preparation_minutes') ? $this->integer('preparation_minutes') : null,
            'attributes' => $this->normalizedAttributes(),
            'catalog_settings' => $this->normalizedCatalogSettings(),
            'requires_lot' => $this->boolean('requires_lot', $this->normalizedItemType() === 'rental'),
            'requires_expiration_date' => $this->boolean('requires_expiration_date', false),
            'is_rentable' => $this->boolean('is_rentable', $this->normalizedItemType() === 'rental'),
            'custom_attributes' => $this->normalizedCustomAttributes(),
            'unit_conversions' => app(ProductWorkflowPolicy::class)->unitEquivalencesEnabled() ? $this->normalizedUnitConversions($unit?->id) : [],
            'allowed_units' => app(ProductWorkflowPolicy::class)->unitEquivalencesEnabled() ? $this->normalizedAllowedUnits($unit?->symbol) : array_values(array_filter([$unit?->symbol])),
            'images' => $this->normalizedImages(),
            'variants' => $this->normalizedVariants(),
        ]);
    }

    private function normalizedItemType(): string
    {
        $allowed = app(ProductWorkflowPolicy::class)->allowedItemTypes();
        $requested = (string) ($this->input('item_type') ?: 'physical');

        return $this->filled('item_type') ? $requested : ($allowed[0] ?? 'physical');
    }

    private function normalizedAttributes(): array
    {
        return collect($this->input('attributes', []))
            ->mapWithKeys(fn ($value, $key) => [Str::slug((string) $key, '_') => is_string($value) ? trim($value) : $value])
            ->all();
    }

    private function normalizedCatalogSettings(): array
    {
        return collect($this->input('catalog_settings', []))
            ->filter(fn ($value, $key) => is_string($key) && preg_match('/^[a-zA-Z0-9_.-]+$/', $key))
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

    private function normalizedImages(): array
    {
        if (! app(ProductWorkflowPolicy::class)->imagesEnabled()) {
            return [];
        }

        return collect($this->input('images', []))
            ->filter(fn ($row) => is_array($row) && (filled($row['url'] ?? null) || filled($row['path'] ?? null)))
            ->take(app(ProductWorkflowPolicy::class)->galleryEnabled() ? 12 : 1)
            ->values()
            ->map(fn (array $row, int $index) => [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'url' => is_string($row['url'] ?? null) ? trim($row['url']) : null,
                'path' => is_string($row['path'] ?? null) ? trim($row['path']) : null,
                'alt_text' => is_string($row['alt_text'] ?? null) ? trim($row['alt_text']) : null,
                'is_primary' => $index === 0 || filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
            ])
            ->all();
    }

    private function normalizedVariants(): array
    {
        if (! app(ProductWorkflowPolicy::class)->variantsEnabled()) {
            return [];
        }

        return collect($this->input('variants', []))
            ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
            ->map(fn (array $row) => [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'sku' => is_string($row['sku'] ?? null) ? trim($row['sku']) : null,
                'barcode' => is_string($row['barcode'] ?? null) ? trim($row['barcode']) : null,
                'name' => trim((string) $row['name']),
                'attributes' => collect($row['attributes'] ?? [])
                    ->mapWithKeys(fn ($value, $key) => [Str::slug((string) $key, '_') => is_string($value) ? trim($value) : $value])
                    ->all(),
                'cost_price' => $row['cost_price'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ])
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

    private function validateFlexibleCatalog(Validator $validator): void
    {
        $policy = app(ProductWorkflowPolicy::class);

        if (! $policy->imagesEnabled() && collect($this->input('images', []))->filter(fn ($row) => filled($row['url'] ?? null) || filled($row['path'] ?? null))->isNotEmpty()) {
            $validator->errors()->add('images', 'Las imagenes de producto estan desactivadas para este perfil de negocio.');
        }

        if (! $policy->variantsEnabled() && collect($this->input('variants', []))->filter(fn ($row) => filled($row['name'] ?? null))->isNotEmpty()) {
            $validator->errors()->add('variants', 'Las variantes de producto estan desactivadas para este perfil de negocio.');
        }

        if ($this->input('item_type') === 'service' && ! $policy->allowServiceItems()) {
            $validator->errors()->add('item_type', 'Este perfil no permite crear servicios dentro del catalogo de productos.');
        }

        if ($this->input('item_type') !== 'service' && filled($this->input('service_type_id'))) {
            $validator->errors()->add('service_type_id', 'El tipo de servicio solo aplica a items configurados como servicio.');
        }

        if ($this->input('item_type') === 'rental' && ! $policy->rentalsEnabled()) {
            $validator->errors()->add('item_type', 'Este perfil no permite productos alquilables.');
        }

        if ($this->boolean('requires_lot') && ! $policy->lotsEnabled()) {
            $validator->errors()->add('requires_lot', 'El perfil no tiene activado el rastreo por lote o unidad fisica.');
        }

        if ($this->boolean('requires_expiration_date') && ! $policy->expirationDatesEnabled()) {
            $validator->errors()->add('requires_expiration_date', 'El perfil no tiene activadas fechas de vencimiento.');
        }

        if ($this->boolean('is_rentable') && ! $policy->rentalsEnabled()) {
            $validator->errors()->add('is_rentable', 'El perfil no tiene activados alquileres.');
        }

        if ($this->input('item_type') === 'service' && $this->boolean('is_inventory_item')) {
            $validator->errors()->add('is_inventory_item', 'Un servicio no debe manejar stock directo. Si usa materiales, registra esos materiales como insumos.');
        }

        if ($this->boolean('requires_expiration_date') && ! $this->boolean('requires_lot')) {
            $validator->errors()->add('requires_expiration_date', 'Para controlar vencimiento tambien debe controlarse lote o unidad fisica.');
        }

        $variantSkus = collect($this->input('variants', []))->pluck('sku')->filter()->map(fn ($value) => trim((string) $value));
        if ($variantSkus->count() !== $variantSkus->unique()->count()) {
            $validator->errors()->add('variants', 'No puede repetir el mismo SKU en variantes del producto.');
        }

        $variantBarcodes = collect($this->input('variants', []))->pluck('barcode')->filter()->map(fn ($value) => trim((string) $value));
        if ($variantBarcodes->count() !== $variantBarcodes->unique()->count()) {
            $validator->errors()->add('variants', 'No puede repetir el mismo barcode en variantes del producto.');
        }

        $currentVariantIds = collect($this->input('variants', []))->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($variantSkus->isNotEmpty() && ProductVariant::query()->whereIn('sku', $variantSkus)->whereNotIn('id', $currentVariantIds ?: [-1])->exists()) {
            $validator->errors()->add('variants', 'Uno de los SKU de variante ya existe en otro producto.');
        }

        if ($variantBarcodes->isNotEmpty() && ProductVariant::query()->whereIn('barcode', $variantBarcodes)->whereNotIn('id', $currentVariantIds ?: [-1])->exists()) {
            $validator->errors()->add('variants', 'Uno de los barcode de variante ya existe en otro producto.');
        }
    }
}
