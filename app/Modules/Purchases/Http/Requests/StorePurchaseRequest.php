<?php

namespace App\Modules\Purchases\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $products = Product::query()
            ->with('unitConversions.unit:id,symbol')
            ->whereIn('id', collect($this->input('items', []))->pluck('product_id')->filter()->unique()->values())
            ->get(['id', 'base_unit'])
            ->keyBy('id');

        $items = collect($this->input('items', []))
            ->map(function (array $item) use ($products): array {
                if (($item['calculation_mode'] ?? null) === 'weight' && (blank($item['display_quantity'] ?? null) || (float) $item['display_quantity'] <= 0)) {
                    $item['display_quantity'] = filled($item['meters'] ?? null) && (float) $item['meters'] > 0
                        ? $item['meters']
                        : 1;
                }

                if (($item['calculation_mode'] ?? 'direct') === 'direct' && filled($item['display_quantity'] ?? null)) {
                    $product = $products->get($item['product_id'] ?? null);
                    $item['meters'] = round((float) $item['display_quantity'] * $this->unitFactorToBase($product, $item['display_unit_label'] ?? null), 3);
                }

                return $item;
            })
            ->all();

        $this->merge(['items' => $items]);
    }

    private function unitFactorToBase(?Product $product, ?string $unitSymbol): float
    {
        if (! $product || blank($unitSymbol) || $unitSymbol === $product->base_unit) {
            return 1;
        }

        $conversion = $product->unitConversions
            ->first(fn ($row) => $row->is_active && $row->unit?->symbol === $unitSymbol);

        return $conversion ? (float) $conversion->factor_to_base : 1;
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
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.new_product' => ['nullable', 'array'],
            'items.*.new_product.name' => ['nullable', 'string', 'max:255'],
            'items.*.new_product.product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'items.*.new_product.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.new_product.thickness_id' => ['nullable', 'integer', 'exists:thicknesses,id'],
            'items.*.new_product.sku' => ['nullable', 'string', 'max:80', Rule::unique('products', 'sku')->whereNull('deleted_at')],
            'items.*.new_product.barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->whereNull('deleted_at')],
            'items.*.new_product.inventory_tracking_mode' => ['nullable', Rule::in([Product::TRACKING_GLOBAL, Product::TRACKING_COIL])],
            'items.*.new_product.base_unit' => ['nullable', 'string', 'max:24'],
            'items.*.new_product.attributes' => ['nullable', 'array'],
            'items.*.new_product.custom_attributes' => ['nullable', 'array'],
            'items.*.new_product.custom_attributes.*.code' => ['nullable', 'string', 'max:80'],
            'items.*.new_product.custom_attributes.*.name' => ['required_with:items.*.new_product.custom_attributes', 'string', 'max:120'],
            'items.*.new_product.custom_attributes.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean'])],
            'items.*.new_product.custom_attributes.*.value' => ['nullable', 'max:120'],
            'items.*.new_product.custom_attributes.*.has_unit' => ['nullable', 'boolean'],
            'items.*.new_product.custom_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.new_product.allowed_units' => ['nullable', 'array'],
            'items.*.new_product.allowed_units.*' => ['string', 'max:24'],
            'items.*.new_product.unit_conversions' => ['nullable', 'array'],
            'items.*.new_product.unit_conversions.*.product_unit_id' => ['required_with:items.*.new_product.unit_conversions', 'integer', 'exists:product_units,id'],
            'items.*.new_product.unit_conversions.*.factor_to_base' => ['required_with:items.*.new_product.unit_conversions', 'numeric', 'gt:0', 'max:999999999999.999999'],
            'items.*.new_product.unit_conversions.*.is_active' => ['nullable', 'boolean'],
            'items.*.new_product.purchase_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.new_product.sale_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.new_product.minimum_stock_meters' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999'],
            'items.*.new_product.is_active' => ['nullable', 'boolean'],
            'items.*.new_product.branch_scope' => ['nullable', Rule::in(['global', 'specific'])],
            'items.*.new_product.branch_ids' => ['nullable', 'array'],
            'items.*.new_product.branch_ids.*' => ['integer', 'exists:branches,id'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.kilograms' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.meters' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.calculation_mode' => ['nullable', 'in:direct,length,weight'],
            'items.*.item_attributes' => ['nullable', 'array'],
            'items.*.item_attributes.*.code' => ['required_with:items.*.item_attributes', 'string', 'max:80'],
            'items.*.item_attributes.*.name' => ['required_with:items.*.item_attributes', 'string', 'max:120'],
            'items.*.item_attributes.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean'])],
            'items.*.item_attributes.*.value' => ['nullable', 'string', 'max:120'],
            'items.*.item_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.unit_cost' => ['required', 'numeric', 'gte:0', 'max:999999999999.9999'],
            'items.*.lot_number' => ['nullable', 'string', 'max:80'],
            'items.*.coil_barcode' => ['nullable', 'string', 'max:80', 'distinct', 'unique:product_coils,barcode'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.display_quantity.gt' => 'La cantidad del item debe ser mayor que 0. Si el calculo es Peso a metros, ingresa el peso y el sistema calculara la cantidad.',
            'items.*.kilograms.gt' => 'El peso del item debe ser mayor que 0.',
            'items.*.meters.gt' => 'La cantidad calculada del item debe ser mayor que 0.',
            'items.*.unit_cost.required' => 'Cada item debe tener costo.',
            'items.*.unit_cost.gte' => 'El costo del item no puede ser negativo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'branch_id' => 'sucursal',
            'supplier_id' => 'proveedor',
            'document_number' => 'numero de documento',
            'status' => 'estado',
            'items' => 'items',
            'items.*.product_id' => 'producto del item',
            'items.*.new_product.name' => 'nombre del producto nuevo',
            'items.*.new_product.product_category_id' => 'categoria del producto nuevo',
            'items.*.new_product.product_unit_id' => 'unidad del producto nuevo',
            'items.*.new_product.inventory_tracking_mode' => 'rastreo del producto nuevo',
            'items.*.weight_unit' => 'unidad de peso del item',
            'items.*.kilograms' => 'peso del item',
            'items.*.meters' => 'cantidad calculada del item',
            'items.*.display_quantity' => 'cantidad del item',
            'items.*.display_unit_label' => 'unidad del item',
            'items.*.calculation_mode' => 'tipo de calculo del item',
            'items.*.unit_cost' => 'costo del item',
            'items.*.lot_number' => 'lote del item',
            'items.*.coil_barcode' => 'barcode del lote o unidad',
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
                ->get(['id', 'thickness_id', 'base_unit', 'allowed_units', 'inventory_tracking_mode'])
                ->keyBy('id');

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);
                $newProduct = $item['new_product'] ?? [];
                $hasNewProduct = blank($item['product_id'] ?? null) && filled($newProduct['name'] ?? null);

                if (! $product && is_array($newProduct) && $newProduct !== [] && blank($newProduct['name'] ?? null)) {
                    $validator->errors()->add("items.{$index}.new_product.name", 'Ingresa el nombre del producto nuevo.');

                    continue;
                }

                if (! $product && ! $hasNewProduct) {
                    $validator->errors()->add("items.{$index}.product_id", 'Selecciona un producto existente o registra los datos del producto nuevo.');

                    continue;
                }

                if ($hasNewProduct) {
                    $category = ProductCategory::query()->find($newProduct['product_category_id'] ?? null);
                    $unit = ProductUnit::query()->find($newProduct['product_unit_id'] ?? ($category?->default_unit_id ?? null));

                    if (! $category) {
                        $validator->errors()->add("items.{$index}.new_product.product_category_id", 'Selecciona la categoria del producto nuevo.');
                    }

                    if (! $unit) {
                        $validator->errors()->add("items.{$index}.new_product.product_unit_id", 'Selecciona la unidad base del producto nuevo.');
                    }

                    $branchScope = $newProduct['branch_scope'] ?? 'specific';
                    $branchIds = collect($newProduct['branch_ids'] ?? [$this->integer('branch_id')])
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique()
                        ->values();

                    if ($branchScope === 'specific' && $branchIds->isEmpty()) {
                        $validator->errors()->add("items.{$index}.new_product.branch_ids", 'Selecciona al menos una sucursal para habilitar el producto nuevo.');
                    }

                    if (! $this->user()?->isSuperAdministrator()) {
                        $unauthorized = $branchIds->diff(collect($this->user()?->accessibleBranchIds() ?? []));

                        if ($unauthorized->isNotEmpty()) {
                            $validator->errors()->add("items.{$index}.new_product.branch_ids", 'Solo puedes habilitar el producto nuevo en tus sucursales permitidas.');
                        }
                    }

                    if (($newProduct['inventory_tracking_mode'] ?? $category?->default_tracking_mode) === Product::TRACKING_COIL) {
                        if (blank($item['lot_number'] ?? null)) {
                            $validator->errors()->add("items.{$index}.lot_number", 'El rastreo por lote/unidad requiere numero de lote.');
                        }

                        if (blank($item['coil_barcode'] ?? null)) {
                            $validator->errors()->add("items.{$index}.coil_barcode", 'El rastreo por lote/unidad requiere barcode unico.');
                        }
                    }

                    if (blank($item['meters'] ?? null) && blank($item['kilograms'] ?? null)) {
                        $validator->errors()->add("items.{$index}.meters", 'Debes ingresar cantidad o peso para el producto nuevo.');
                    }

                    if (blank($item['meters'] ?? null) && filled($item['kilograms'] ?? null) && blank($newProduct['thickness_id'] ?? null)) {
                        $validator->errors()->add("items.{$index}.new_product.thickness_id", 'Selecciona el espesor para convertir peso a metros en este producto nuevo.');
                    }

                    continue;
                }

                if (! $product) {
                    continue;
                }

                if (blank($item['meters'] ?? null) && blank($item['kilograms'] ?? null)) {
                    $validator->errors()->add("items.{$index}.meters", 'Debes ingresar metros o peso.');
                }

                $unit = $item['display_unit_label'] ?? $product->base_unit;
                $allowedUnits = $product->allowed_units ?: [$product->base_unit];

                if (! in_array($unit, $allowedUnits, true)) {
                    $validator->errors()->add("items.{$index}.display_unit_label", 'La unidad seleccionada no esta habilitada para este producto.');
                }

                if (blank($item['meters'] ?? null) && filled($item['kilograms'] ?? null) && ! $product->thickness) {
                    $validator->errors()->add("items.{$index}.kilograms", 'El producto necesita espesor para convertir peso a metros.');
                }

                if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                    if (blank($item['lot_number'] ?? null)) {
                        $validator->errors()->add("items.{$index}.lot_number", 'El rastreo individual requiere numero de lote.');
                    }

                    if (blank($item['coil_barcode'] ?? null)) {
                        $validator->errors()->add("items.{$index}.coil_barcode", 'El rastreo individual requiere barcode unico.');
                    }
                }
            }
        });
    }
}
