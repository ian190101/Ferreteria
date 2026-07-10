<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Cash\Support\CashSessionGuard;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.manage') ?? false;
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
                    $item['meters'] = round((float) $item['display_quantity'] * $this->unitFactorToBase($product, $item['display_unit_label'] ?? $item['unit_label'] ?? null), 3);
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
            'document_type' => ['required', Rule::in(['quotation', 'sale_note'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sale_type_id' => ['required', 'integer', 'exists:sale_types,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'advance_option_id' => ['nullable', 'integer', 'exists:advance_options,id'],
            'advance_amount_input' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'source_quotation_id' => ['nullable', 'integer', 'exists:sales,id'],
            'receipt_number' => ['nullable', 'string', 'max:80', 'unique:sales,receipt_number'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_document' => ['nullable', 'string', 'max:80'],
            'customer_contact' => ['nullable', 'string', 'max:40'],
            'sold_at' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'requires_delivery' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_label' => ['required', 'string', 'max:16'],
            'items.*.display_quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.display_unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.item_attributes' => ['nullable', 'array'],
            'items.*.calculation_mode' => ['nullable', Rule::in(['direct', 'length', 'weight'])],
            'items.*.item_attributes.*.code' => ['required_with:items.*.item_attributes', 'string', 'max:80'],
            'items.*.item_attributes.*.name' => ['required_with:items.*.item_attributes', 'string', 'max:120'],
            'items.*.item_attributes.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean'])],
            'items.*.item_attributes.*.value' => ['nullable', 'string', 'max:120'],
            'items.*.item_attributes.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.meters' => ['required', 'numeric', 'gt:0', 'max:999999999999.999'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:999999999999.9999'],
            'items.*.discount_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.display_quantity.gt' => 'La cantidad del item debe ser mayor que 0. Si el calculo es Peso a metros, ingresa el peso y el sistema calculara la cantidad.',
            'items.*.meters.required' => 'Cada item debe tener una cantidad calculada valida.',
            'items.*.meters.gt' => 'La cantidad calculada del item debe ser mayor que 0.',
            'items.*.unit_price.required' => 'Cada item debe tener precio.',
            'items.*.unit_price.gte' => 'El precio del item no puede ser negativo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'document_type' => 'tipo de documento',
            'branch_id' => 'sucursal',
            'sale_type_id' => 'tipo de venta',
            'currency_id' => 'moneda',
            'customer_id' => 'cliente',
            'requires_delivery' => 'tipo de entrega',
            'items' => 'items',
            'items.*.product_id' => 'producto del item',
            'items.*.description' => 'descripcion del item',
            'items.*.display_quantity' => 'cantidad del item',
            'items.*.display_unit_label' => 'unidad del item',
            'items.*.calculation_mode' => 'tipo de calculo del item',
            'items.*.meters' => 'cantidad calculada del item',
            'items.*.unit_price' => 'precio del item',
            'items.*.discount_amount' => 'descuento del item',
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
            $productIds = $items->pluck('product_id')->filter()->unique()->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'base_unit', 'allowed_units', 'inventory_tracking_mode'])
                ->keyBy('id');

            $sourceQuotation = $this->filled('source_quotation_id')
                ? Sale::query()->find($this->integer('source_quotation_id'))
                : null;

            if ($sourceQuotation) {
                if ($sourceQuotation->document_type !== 'quotation' || $sourceQuotation->status !== 'quoted') {
                    $validator->errors()->add('source_quotation_id', 'Solo se puede crear una nota desde una cotizacion vigente.');
                }

                if ((int) $sourceQuotation->branch_id !== $this->integer('branch_id')) {
                    $validator->errors()->add('source_quotation_id', 'La cotizacion seleccionada pertenece a otra sucursal.');
                }
            }

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);

                if (! $product) {
                    continue;
                }

                $unit = $item['display_unit_label'] ?? $item['unit_label'] ?? $product->base_unit;
                $allowedUnits = $product->allowed_units ?: [$product->base_unit];

                if (! in_array($unit, $allowedUnits, true)) {
                    $validator->errors()->add("items.{$index}.display_unit_label", 'La unidad seleccionada no esta habilitada para este producto.');
                }
            }

            if ($this->input('document_type') !== 'sale_note') {
                return;
            }

            if (CashSessionGuard::requiresOpenSession($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', CashSessionGuard::message());

                return;
            }

            $globalMetersByProduct = [];
            $coilMetersById = [];

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);

                if (! $product) {
                    continue;
                }

                $meters = round((float) ($item['meters'] ?? 0), 3);

                if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                    $coilId = $item['product_coil_id'] ?? null;

                    if (! $coilId) {
                        $validator->errors()->add("items.{$index}.product_coil_id", 'El lote o unidad fisica es obligatorio para productos con rastreo individual.');

                        continue;
                    }

                    $coilMetersById[$coilId] = ($coilMetersById[$coilId] ?? 0) + $meters;

                    continue;
                }

                if (filled($item['product_coil_id'] ?? null)) {
                    $validator->errors()->add("items.{$index}.product_coil_id", 'Los productos con rastreo global no deben seleccionar lote o unidad fisica individual.');
                }

                $globalMetersByProduct[$product->id] = ($globalMetersByProduct[$product->id] ?? 0) + $meters;
            }

            if ($globalMetersByProduct !== []) {
                $stocks = ProductBranchStock::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->whereIn('product_id', array_keys($globalMetersByProduct))
                    ->get(['product_id', 'available_meters', 'reserved_meters'])
                    ->keyBy('product_id');

                foreach ($globalMetersByProduct as $productId => $meters) {
                    $stock = $stocks->get($productId);
                    $available = $stock ? (float) $stock->available_meters - (float) $stock->reserved_meters : 0;

                    if ($available < $meters) {
                        $validator->errors()->add('items', 'La sucursal no tiene stock global libre suficiente para uno o mas productos.');
                    }
                }
            }

            if ($coilMetersById !== []) {
                $coils = ProductCoil::query()
                    ->where('branch_id', $this->integer('branch_id'))
                    ->where('status', 'available')
                    ->whereIn('id', array_keys($coilMetersById))
                    ->get(['id', 'available_meters'])
                    ->keyBy('id');

                $reservedByCoil = InventoryReservation::query()
                    ->whereIn('product_coil_id', array_keys($coilMetersById))
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->selectRaw('product_coil_id, SUM(meters) as reserved_meters')
                    ->groupBy('product_coil_id')
                    ->pluck('reserved_meters', 'product_coil_id');

                foreach ($coilMetersById as $coilId => $meters) {
                    $coil = $coils->get($coilId);

                    if (! $coil) {
                        $validator->errors()->add('items', 'Un lote o unidad fisica seleccionada no esta disponible en la sucursal.');

                        continue;
                    }

                    $reserved = (float) ($reservedByCoil[$coil->id] ?? 0);

                    if (((float) $coil->available_meters - $reserved) < $meters) {
                        $validator->errors()->add('items', 'Un lote o unidad fisica seleccionada no tiene cantidad suficiente.');
                    }
                }
            }
        });
    }
}
