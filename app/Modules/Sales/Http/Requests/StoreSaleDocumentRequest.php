<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Cash\Support\CashSessionGuard;
use App\Modules\Finance\Support\Decimal;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\ProductWorkflowPolicy;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Pos\Services\PosWorkflowPolicy;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\DeliveryWorkflowPolicy;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
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
        \Illuminate\Support\Facades\Validator::make($this->all(), [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.calculation_mode' => ['nullable', 'string'],
            'items.*.display_unit_label' => ['nullable', 'string'],
            'items.*.unit_label' => ['nullable', 'string'],
            'items.*.meters' => ['nullable', 'numeric'],
            'items.*.display_quantity' => ['nullable', 'numeric'],
            'items.*.base_quantity' => ['nullable', 'numeric'],
            'items.*.length' => ['nullable', 'numeric'],
            'items.*.entered_weight' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/D', 'numeric', 'max:999999999999.999'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
        ])->validate();

        \Illuminate\Support\Facades\Validator::make($this->all(), [
            'items.*.meters' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'items.*.display_quantity' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'items.*.base_quantity' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'items.*.length' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'items.*.unit_price' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'items.*.discount_amount' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'pos_payment_amount' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
            'advance_amount_input' => ['nullable', 'regex:/^-?\d+(?:\.\d+)?$/D'],
        ])->validate();

        $products = Product::query()
            ->with(['unitConversions.unit:id,symbol', 'thickness'])
            ->whereIn('id', collect($this->input('items', []))->pluck('product_id')->filter()->unique()->values())
            ->get(['id', 'base_unit', 'thickness_id', 'is_inventory_item', 'requires_lot'])
            ->keyBy('id');

        $items = collect($this->input('items', []))
            ->map(function (array $item) use ($products): array {
                $mode = $item['calculation_mode'] ?? 'direct';
                $product = $products->get($item['product_id'] ?? null);
                $policy = app(LotTrackingPolicy::class);
                $managed = $product && $policy->required($product, $policy->activeConfiguration());
                $exact = app(InventoryQuantityService::class);

                if ($managed && $mode === 'weight' && filled($item['entered_weight'] ?? null)) {
                    try {
                        $kg = $exact->multiplyExact((string) $item['entered_weight'], ($item['weight_unit'] ?? 'kg') === 'ton' ? '1000' : '1');
                        $item['meters'] = $exact->metersFromWeightExact($kg, $product->thickness?->kg_per_meter, $product->thickness?->kg_to_meter_factor);
                        $item['display_quantity'] = $item['meters'];
                    } catch (\InvalidArgumentException $exception) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['items' => $exception->getMessage()]);
                    }
                }

                if ($managed && $mode === 'length' && filled($item['base_quantity'] ?? $item['length'] ?? null)) {
                    $item['meters'] = $exact->multiplyExact((string) ($item['display_quantity'] ?? '0'), (string) ($item['base_quantity'] ?? $item['length']));
                }

                if ($mode === 'weight' && blank($item['meters'] ?? null)) {
                    $quantity = $this->positiveDecimal($item['base_quantity'] ?? null)
                        ?? $this->positiveDecimal($item['display_quantity'] ?? null);
                    $item['meters'] = $quantity ? Decimal::round($quantity, 3) : null;
                }

                if ($mode === 'length' && blank($item['meters'] ?? null)) {
                    $quantity = $this->positiveDecimal($item['display_quantity'] ?? null);
                    $length = $this->positiveDecimal($item['base_quantity'] ?? null)
                        ?? $this->positiveDecimal($item['length'] ?? null);

                    if ($quantity && $length) {
                        $item['meters'] = Decimal::round(bcmul($quantity, $length, 12), 3);
                    }
                }

                if ($mode === 'direct' && filled($item['display_quantity'] ?? null)) {
                    $quantity = $this->positiveDecimal($item['display_quantity']);
                    if ($quantity) {
                        $item['meters'] = $exact->multiplyExact($quantity, $this->unitFactorToBaseExact($product, $item['display_unit_label'] ?? $item['unit_label'] ?? null));
                    }
                }

                if (blank($item['display_quantity'] ?? null) && filled($item['meters'] ?? null)) {
                    $item['display_quantity'] = $item['meters'];
                }

                return $item;
            })
            ->all();

        $this->merge(['items' => $items]);
    }

    private function unitFactorToBase(?Product $product, ?string $unitSymbol): float
    {
        return (float) $this->unitFactorToBaseExact($product, $unitSymbol);
    }

    private function unitFactorToBaseExact(?Product $product, ?string $unitSymbol): string
    {
        if (! $product || blank($unitSymbol) || $unitSymbol === $product->base_unit) {
            return '1';
        }

        $conversion = $product->unitConversions
            ->first(fn ($row) => $row->is_active && $row->unit?->symbol === $unitSymbol);
        $policy = app(LotTrackingPolicy::class);
        if (! $conversion && $policy->required($product, $policy->activeConfiguration())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'La unidad seleccionada no tiene una conversión activa a la unidad base del producto.']);
        }

        return $conversion ? (string) $conversion->factor_to_base : '1';
    }

    private function positiveDecimal(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $decimal = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $decimal)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['items' => 'La cantidad debe tener formato decimal válido.']);
        }

        return bccomp($decimal, '0', 12) > 0 ? $decimal : null;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['quotation', 'sale_note'])],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sale_type_id' => ['required', 'integer', 'exists:sale_types,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'advance_mode' => ['nullable', Rule::in(['none', 'percentage', 'amount'])],
            'advance_option_id' => ['nullable', 'integer', 'exists:advance_options,id'],
            'advance_amount_input' => [
                Rule::requiredIf(fn () => $this->input('advance_mode') === 'amount'),
                'nullable',
                'numeric',
                'min:0.01',
                'max:999999999999.99',
            ],
            'source_quotation_id' => ['nullable', 'integer', 'exists:sales,id'],
            'receipt_number' => ['nullable', 'string', 'max:80', 'unique:sales,receipt_number'],
            'customer_name' => [
                Rule::requiredIf(fn () => app(SalesWorkflowPolicy::class)->customerRequired() && ! $this->filled('customer_id')),
                'nullable',
                'string',
                'max:255',
            ],
            'customer_document' => ['nullable', 'string', 'max:80'],
            'customer_contact' => ['nullable', 'string', 'max:40'],
            'sold_at' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'requires_delivery' => ['nullable', 'boolean'],
            'from_pos' => ['nullable', 'boolean'],
            'pos_payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'pos_payment_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99'],
            'pos_payment_reference' => ['nullable', 'string', 'max:120'],
            'pos_context' => ['nullable', 'array'],
            'pos_context.mode' => ['nullable', 'string', 'max:80'],
            'pos_context.channel' => ['nullable', 'string', 'max:80'],
            'pos_context.resource' => ['nullable', 'string', 'max:120'],
            'pos_context.assigned' => ['nullable', 'string', 'max:120'],
            'pos_context.reference' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_coil_id' => ['nullable', 'integer', 'exists:product_coils,id'],
            'items.*.lot_id' => ['nullable', 'integer', 'exists:product_coils,id'],
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
            'pos_payment_amount.gt' => 'El monto cobrado en POS debe ser mayor que 0.',
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
            'advance_mode' => 'tipo de anticipo',
            'advance_option_id' => 'porcentaje de anticipo',
            'advance_amount_input' => 'monto de anticipo',
            'requires_delivery' => 'tipo de entrega',
            'from_pos' => 'origen POS',
            'pos_payment_method_id' => 'metodo de pago POS',
            'pos_payment_amount' => 'monto cobrado POS',
            'pos_payment_reference' => 'referencia POS',
            'pos_context.resource' => 'recurso POS',
            'pos_context.assigned' => 'responsable POS',
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
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($message = BranchAccess::validate($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', $message);

                return;
            }

            $workflow = app(SalesWorkflowPolicy::class);
            $documentType = (string) $this->input('document_type');

            if (! $workflow->allowsDocumentType($documentType)) {
                $validator->errors()->add('document_type', $workflow->documentTypeError($documentType));

                return;
            }

            if ($documentType === 'sale_note' && $workflow->requiresSourceQuotationForSaleNote() && ! $this->filled('source_quotation_id')) {
                $validator->errors()->add('source_quotation_id', 'La configuracion actual exige crear la nota de venta desde una cotizacion.');
            }

            if ($workflow->customerRequired() && ! $this->filled('customer_id') && blank($this->input('customer_name'))) {
                $validator->errors()->add('customer_name', 'La configuracion actual exige seleccionar o registrar un cliente.');
            }

            if ($workflow->customerHidden() && ($this->filled('customer_id') || filled($this->input('customer_name')) || filled($this->input('customer_document')) || filled($this->input('customer_contact')))) {
                $validator->errors()->add('customer_name', 'La configuracion actual oculta clientes para este flujo de venta.');
            }

            $deliveryPolicy = app(DeliveryWorkflowPolicy::class);

            if ($documentType === 'sale_note' && $deliveryPolicy->required() && ! $this->boolean('requires_delivery')) {
                $validator->errors()->add('requires_delivery', 'La configuracion actual exige que las notas de venta pasen por despacho.');
            }

            if ($this->filled('pos_payment_amount') && ! $this->filled('pos_payment_method_id')) {
                $validator->errors()->add('pos_payment_method_id', 'Selecciona el metodo de pago para finalizar desde POS.');
            }

            $fromPos = $this->boolean('from_pos') || $this->filled('pos_payment_method_id') || $this->filled('pos_payment_amount');
            $posPolicy = app(PosWorkflowPolicy::class);
            $productPolicy = app(ProductWorkflowPolicy::class);

            if ($fromPos) {
                if (! ActiveBusinessProfile::enabled('pos') && ! ActiveBusinessProfile::capable('uses_pos')) {
                    $validator->errors()->add('from_pos', 'El perfil de negocio actual no tiene habilitado el POS.');
                }

                if ($documentType !== 'sale_note') {
                    $validator->errors()->add('document_type', 'El POS solo puede generar notas de venta o tickets internos.');
                }

                if ($posPolicy->requiresResource() && blank(data_get($this->input('pos_context', []), 'resource'))) {
                    $validator->errors()->add('pos_context.resource', 'Este modo POS requiere seleccionar mesa, recurso o ambiente.');
                }

                if ($posPolicy->requiresAssignedPerson() && blank(data_get($this->input('pos_context', []), 'assigned'))) {
                    $validator->errors()->add('pos_context.assigned', 'Este modo POS requiere registrar el responsable asignado.');
                }

                $posTotal = $this->requestedDocumentTotal();
                $posPayment = Decimal::round((string) $this->input('pos_payment_amount', 0), 2);

                if ($this->filled('pos_payment_amount') && ! $posPolicy->allowsPartialBalance()
                    && bccomp($posPayment, $posTotal, 2) !== 0) {
                    $validator->errors()->add('pos_payment_amount', 'Este perfil POS exige cobrar el total completo en un solo cierre.');
                }

                if ($this->filled('pos_payment_amount') && bccomp($posPayment, $posTotal, 2) > 0) {
                    $validator->errors()->add('pos_payment_amount', 'El cobro POS no puede superar el total del documento.');
                }
            }

            if ($this->filled('pos_payment_method_id')) {
                $method = PaymentMethod::query()->find($this->integer('pos_payment_method_id'));

                if (! $method || ! $method->is_active) {
                    $validator->errors()->add('pos_payment_method_id', 'El metodo de pago POS no esta activo.');
                } elseif (! app(SalesDocumentPolicy::class)->isPaymentMethodAllowed($method->code, 'pos')) {
                    $validator->errors()->add('pos_payment_method_id', 'El perfil de negocio actual no permite usar este metodo de pago en ventas.');
                } elseif ($method->requires_reference && blank($this->input('pos_payment_reference'))) {
                    $validator->errors()->add('pos_payment_reference', 'La referencia es obligatoria para este metodo de pago.');
                }

                if ($documentType !== 'sale_note') {
                    $validator->errors()->add('pos_payment_method_id', 'El cobro POS solo aplica a notas de venta.');
                }
            }

            $items = collect($this->input('items', []));
            $productIds = $items->pluck('product_id')->filter()->unique()->values();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->get(['id', 'base_unit', 'allowed_units', 'inventory_tracking_mode', 'item_type', 'is_inventory_item', 'requires_lot', 'is_sellable', 'is_active'])
                ->keyBy('id');

            $this->validateManagedLots($validator, $items, $products);

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

            $advanceMode = $this->input('advance_mode', 'none');

            if ($advanceMode === 'percentage') {
                if (! $this->filled('advance_option_id')) {
                    $validator->errors()->add('advance_option_id', 'Selecciona un porcentaje de anticipo preconfigurado.');
                } else {
                    $advanceOption = AdvanceOption::query()->find($this->integer('advance_option_id'));

                    if (! $advanceOption || $advanceOption->type !== AdvanceOption::TYPE_PERCENTAGE || ! $advanceOption->is_active) {
                        $validator->errors()->add('advance_option_id', 'El anticipo seleccionado debe ser un porcentaje activo.');
                    }
                }
            }

            if ($advanceMode === 'amount' && $this->filled('advance_option_id')) {
                $validator->errors()->add('advance_option_id', 'Para anticipo por monto no se debe seleccionar una opcion preconfigurada.');
            }

            if ($advanceMode === 'none' && ($this->filled('advance_option_id') || (float) $this->input('advance_amount_input', 0) > 0)) {
                $validator->errors()->add('advance_mode', 'Si no hay anticipo, no debe existir porcentaje ni monto de anticipo.');
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

                if (! in_array($product->item_type ?: 'physical', $productPolicy->allowedItemTypes(), true)) {
                    $validator->errors()->add("items.{$index}.product_id", 'Este tipo de item no esta habilitado para ventas en el perfil actual.');
                }

                if (($product->item_type ?: 'physical') === 'service' && ! $productPolicy->allowServiceItems()) {
                    $validator->errors()->add("items.{$index}.product_id", 'El perfil actual no permite vender servicios.');
                }

                if (! $product->is_active || ! $product->is_sellable) {
                    $validator->errors()->add("items.{$index}.product_id", 'Solo se pueden vender items activos y vendibles.');
                }

                if ($fromPos) {
                    if (! in_array($product->item_type ?: 'physical', $posPolicy->allowedItemTypes(), true)) {
                        $validator->errors()->add("items.{$index}.product_id", 'Este tipo de item no esta permitido en el POS del perfil actual.');
                    }
                }

                if (! $product->is_inventory_item) {
                    if (filled($item['product_coil_id'] ?? null)) {
                        $validator->errors()->add("items.{$index}.product_coil_id", 'Los servicios o items sin inventario no deben seleccionar lote o unidad fisica.');
                    }
                }
            }

            if ($this->input('document_type') !== 'sale_note') {
                return;
            }

            if (CashSessionGuard::requiresOpenSession($this->user(), $this->integer('branch_id'))) {
                $validator->errors()->add('branch_id', CashSessionGuard::message());

                return;
            }

            if ($workflow->shouldDiscountInventoryOnDelivery()) {
                if (! $this->boolean('requires_delivery')) {
                    $validator->errors()->add('requires_delivery', 'La configuracion actual descuenta inventario al despachar, por eso esta nota debe marcarse como requiere despacho.');
                }

                return;
            }

            if (! $workflow->shouldDiscountInventoryOnSaleNote() && ! $workflow->shouldDiscountInventoryOnPayment()) {
                return;
            }

            $globalMetersByProduct = [];
            $coilMetersById = [];

            foreach ($items as $index => $item) {
                $product = $products->get($item['product_id'] ?? null);

                if (! $product) {
                    continue;
                }

                if (! $product->is_inventory_item) {
                    continue;
                }

                $lotPolicy = app(LotTrackingPolicy::class);
                if ($lotPolicy->required($product, $lotPolicy->activeConfiguration())) {
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

            if ($globalMetersByProduct !== [] && ! $workflow->allowsNegativeStock()) {
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

            if ($coilMetersById !== [] && ! $workflow->allowsNegativeStock()) {
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

    private function requestedDocumentTotal(): string
    {
        return collect($this->input('items', []))->reduce(function (string $total, array $item): string {
            $gross = bcmul((string) ($item['meters'] ?? '0'), (string) ($item['unit_price'] ?? '0'), 8);
            $net = bcsub($gross, (string) ($item['discount_amount'] ?? '0'), 8);

            return bcadd($total, Decimal::round(bccomp($net, '0', 8) < 0 ? '0' : $net, 2), 2);
        }, '0.00');
    }

    private function validateManagedLots($validator, $items, $products): void
    {
        $policy = app(LotTrackingPolicy::class);
        $configuration = $policy->activeConfiguration();
        $quantities = app(InventoryQuantityService::class);
        $totals = [];
        $lotIds = [];
        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id'] ?? null);
            if (! $product) {
                continue;
            }
            $managed = $policy->required($product, $configuration);
            $lotId = $item['lot_id'] ?? null;
            if (! $managed) {
                if ($lotId !== null) {
                    $validator->errors()->add("items.{$index}.lot_id", 'El producto no tiene control de lotes activo.');
                }
                continue;
            }
            if ($lotId === null || ($item['product_coil_id'] ?? $lotId) != $lotId) {
                $validator->errors()->add("items.{$index}.lot_id", 'Selecciona una identidad de lote válida.');
                continue;
            }
            try {
                $amount = $quantities->exact((string) ($item['meters'] ?? '0'));
                if ($quantities->compareExact($amount, '0') <= 0) {
                    throw new \InvalidArgumentException('Cantidad inválida.');
                }
                $totals[$lotId] = $quantities->addExact($totals[$lotId] ?? '0.000', $amount);
                $lotIds[$lotId] = [(int) $product->id, $index];
            } catch (\InvalidArgumentException) {
                $validator->errors()->add("items.{$index}.meters", 'Cantidad decimal inválida.');
            }
        }

        if ($lotIds === []) {
            return;
        }
        $lots = ProductCoil::query()->whereIn('id', array_keys($lotIds))->get()->keyBy('id');
        $reserved = InventoryReservation::query()->whereIn('product_coil_id', array_keys($lotIds))
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->selectRaw('product_coil_id, SUM(remaining_meters) AS total')
            ->groupBy('product_coil_id')->pluck('total', 'product_coil_id');
        foreach ($lotIds as $lotId => [$productId, $index]) {
            $lot = $lots->get($lotId);
            if (! $lot || $lot->product_id !== $productId
                || $lot->branch_id !== $this->integer('branch_id')
                || $lot->isVoided() || $lot->trashed() || $lot->status !== 'available') {
                $validator->errors()->add("items.{$index}.lot_id", 'El lote no está activo para este producto y sucursal.');
                continue;
            }
            $free = $quantities->subtractExact((string) $lot->available_meters, (string) ($reserved[$lotId] ?? '0.000'));
            if ($quantities->compareExact($free, $totals[$lotId]) < 0) {
                $validator->errors()->add("items.{$index}.lot_id", 'El lote no tiene saldo libre suficiente.');
            }
        }
    }
}
