<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Services\BillingWorkflowPolicy;
use App\Modules\Billing\Services\SiatInvoiceService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\ProductWorkflowPolicy;
use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Http\Requests\ConvertQuotationRequest;
use App\Modules\Sales\Http\Requests\StoreSaleDocumentRequest;
use App\Modules\Sales\Http\Requests\VoidSaleRequest;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Services\CommercialPolicy;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    private const CACHE_SECONDS = 30;

    public function index(Request $request): Response
    {
        $workflow = app(SalesWorkflowPolicy::class);
        $sales = Sale::query()
            ->select([
                'id',
                'branch_id',
                'user_id',
                'sale_type_id',
                'currency_id',
                'receipt_number',
                'document_type',
                'customer_name',
                'sold_at',
                'total',
                'balance_due',
                'status',
                'requires_delivery',
            ])
            ->with(['branch:id,name', 'user:id,name', 'saleType:id,name', 'currency:id,name,code,symbol'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')->toString()))
            ->latest('sold_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $request->only('per_page'),
            'workflow' => $workflow->summary(),
            'documentPolicy' => app(SalesDocumentPolicy::class)->summary(),
        ]);
    }

    public function create(Request $request): Response
    {
        $workflow = app(SalesWorkflowPolicy::class);
        $documentPolicy = app(SalesDocumentPolicy::class);
        $documentType = $request->string('type')->isNotEmpty()
            ? $request->string('type')->toString()
            : ($workflow->quotationMode() === 'required' ? 'quotation' : 'sale_note');

        if (! $workflow->allowsDocumentType($documentType)) {
            $documentType = $workflow->allowsDocumentType('sale_note') ? 'sale_note' : 'quotation';
        }

        abort_unless($workflow->allowsDocumentType($documentType), 403);

        return Inertia::render('Sales/Form', [
            'documentType' => $documentType,
            'workflow' => $workflow->summary(),
            'documentPolicy' => $documentPolicy->summary(),
            'commercialPolicy' => app(CommercialPolicy::class)->summary(),
            'productPolicy' => app(ProductWorkflowPolicy::class)->summary(),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name', 'address', 'phone', 'secondary_phone', 'point_of_sale_name']),
            'saleTypes' => UiCatalogCache::saleTypes(),
            'currencies' => UiCatalogCache::currencies(),
            'advanceOptions' => UiCatalogCache::advanceOptions(),
            'paymentMethods' => Inertia::defer(fn () => $this->allowedPaymentMethods(), 'sales-form-catalogs'),
            'units' => UiCatalogCache::productUnits(),
            'categories' => UiCatalogCache::productCategories(),
            'products' => Inertia::defer(fn () => UiCatalogCache::activeProductsWithThickness(), 'sales-form-catalogs'),
            'coils' => Inertia::defer(fn () => $this->availableCoils($request), 'sales-form-catalogs'),
            'customers' => Inertia::defer(fn () => $workflow->customerHidden() ? [] : UiCatalogCache::recentCustomers(), 'sales-form-catalogs'),
            'sequencePreviews' => Inertia::defer(fn () => $this->sequencePreviews($request), 'sales-form-catalogs'),
            'quotations' => Inertia::defer(fn () => $documentType === 'sale_note'
                ? $this->convertibleQuotations($request)
                : [], 'sales-form-catalogs'),
        ]);
    }

    public function store(StoreSaleDocumentRequest $request): RedirectResponse
    {
        $sale = DB::transaction(function () use ($request) {
            $currency = Currency::query()->findOrFail($request->integer('currency_id'));
            $customer = $request->filled('customer_id')
                ? Customer::query()->findOrFail($request->integer('customer_id'))
                : null;
            $advanceMode = $request->input('advance_mode', 'none');
            $advanceOption = $advanceMode === 'percentage' && $request->filled('advance_option_id')
                ? AdvanceOption::query()->findOrFail($request->integer('advance_option_id'))
                : null;

            $commercialPolicy = app(CommercialPolicy::class);
            $canOverridePrices = $request->user()->can('sales.prices.override');
            $validatedItems = collect($request->validated('items'));
            $products = Product::query()
                ->with(['unit:id,symbol', 'productCategory:id,name'])
                ->whereIn('id', $validatedItems->pluck('product_id')->unique()->values())
                ->get(['id', 'product_category_id', 'product_unit_id', 'name', 'sale_price', 'allowed_units', 'attributes', 'custom_attributes', 'inventory_tracking_mode'])
                ->keyBy('id');

            $items = $validatedItems->map(function (array $item) use ($commercialPolicy, $canOverridePrices, $products, $request, $customer) {
                $product = $products->get($item['product_id']);

                $item['display_quantity'] = $item['display_quantity'] ?? 1;
                $item['display_unit_label'] = $item['display_unit_label'] ?? $product?->unit?->symbol ?? $item['unit_label'];
                $item['item_attributes'] = $this->saleItemAttributes($product, $item['item_attributes'] ?? []);
                $item['calculation_mode'] = $item['calculation_mode'] ?? 'direct';
                $item['unit_price'] = $canOverridePrices
                    ? $item['unit_price']
                    : ($product ? $commercialPolicy->priceFor($product, $request->integer('branch_id'), $customer?->id) : 0);
                $lineGross = (float) $item['meters'] * (float) $item['unit_price'];
                $discount = (float) $item['discount_amount'];

                if ($discount > 0 && ! $commercialPolicy->canApplyDiscount($request->user())) {
                    throw ValidationException::withMessages([
                        'items' => 'La politica comercial actual no permite aplicar descuentos con este usuario.',
                    ]);
                }

                if ($discount > 0 && $commercialPolicy->discountExceedsLimit($lineGross, $discount)) {
                    throw ValidationException::withMessages([
                        'items' => 'El descuento supera el limite configurado para este perfil de negocio.',
                    ]);
                }

                $lineTotal = $lineGross - $discount;
                $item['total'] = max(round($lineTotal, 2), 0);

                return $item;
            });
            $this->ensureProductsEnabledForBranch($items->pluck('product_id')->all(), $request->integer('branch_id'));
            $this->ensureStockPolicyAllowsSale($items->all(), $products, $request->integer('branch_id'), $request->user(), $commercialPolicy);

            $subtotal = round($items->sum(fn ($item) => (float) $item['meters'] * (float) $item['unit_price']), 2);
            $discountTotal = round($items->sum(fn ($item) => (float) $item['discount_amount']), 2);
            $total = round($items->sum('total'), 2);
            [$advancePercentage, $advanceAmount] = $this->advanceValues($advanceOption, $total, (float) $request->input('advance_amount_input', 0), $advanceMode);
            $commercialPolicy->assertCreditAllowed($customer, $total);

            $sourceQuotation = $request->filled('source_quotation_id')
                ? Sale::query()
                    ->with('items')
                    ->whereKey($request->integer('source_quotation_id'))
                    ->lockForUpdate()
                    ->first()
                : null;

            $sale = Sale::query()->create([
                ...$request->safe()->except(['items', 'source_quotation_id', 'advance_mode', 'advance_amount_input', 'pos_payment_method_id', 'pos_payment_amount', 'pos_payment_reference']),
                'terms' => filled($request->validated('terms')) ? $request->validated('terms') : app(SalesDocumentPolicy::class)->defaultTermsFor($request->string('document_type')->toString()),
                'receipt_number' => $request->filled('receipt_number')
                    ? $request->validated('receipt_number')
                    : $this->nextReceiptNumber($request->integer('branch_id'), $request->string('document_type')->toString()),
                'customer_name' => $customer?->name ?? $request->validated('customer_name'),
                'customer_document' => $customer?->document_number ?? $request->validated('customer_document'),
                'customer_contact' => $customer?->phone ?? $request->validated('customer_contact'),
                'user_id' => $request->user()->id,
                'exchange_rate_to_bob' => $currency->exchange_rate_to_bob,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'advance_percentage' => $advancePercentage,
                'advance_amount' => $advanceAmount,
                'balance_due' => $total,
                'total' => $total,
                'sold_at' => now(),
                'status' => $request->string('document_type')->toString() === 'quotation' ? 'quoted' : 'issued',
                'requires_delivery' => $request->string('document_type')->toString() === 'sale_note' && $request->boolean('requires_delivery'),
            ]);

            $sale->items()->createMany($items->all());
            $sale->load('items.product:id,inventory_tracking_mode');

            if ($sale->document_type === 'sale_note') {
                event(new SaleNoteIssued(
                    saleId: (int) $sale->id,
                    userId: (int) $request->user()->id,
                    sourceQuotationId: $sourceQuotation?->id,
                    posPayment: $request->filled('pos_payment_method_id') && $request->filled('pos_payment_amount')
                        ? [
                            'payment_method_id' => $request->integer('pos_payment_method_id'),
                            'amount' => (float) $request->input('pos_payment_amount'),
                            'reference' => $request->string('pos_payment_reference')->toString() ?: null,
                        ]
                        : null,
                ));
            }

            return $sale;
        });

        $this->issueFiscalInvoiceIfRequired($sale, $request->user()->id, afterQuotationConversion: false);

        return redirect()->route('sales.show', $sale)->with('success', 'Documento generado correctamente.');
    }

    public function show(Sale $sale): Response
    {
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $sale->branch_id), 403);

        $sale->load([
            'branch:id,name,address,phone,secondary_phone,point_of_sale_name',
            'branch.setting:id,branch_id,logo_path',
            'user:id,name',
            'saleType:id,name',
            'currency:id,name,code,symbol,exchange_rate_to_bob',
            'advanceOption:id,name,type,percentage,amount',
            'items.product:id,name,sku,inventory_tracking_mode,base_unit,product_unit_id',
            'items.product.unit:id,name,symbol',
            'items.coil:id,barcode,lot_number,available_meters,status',
            'items.deliveryItems:id,sale_item_id,meters,display_quantity,display_unit_label',
            'payments:id,sale_id,payment_method_id,amount,paid_at',
            'payments.method:id,name',
            'siatInvoices:id,sale_id,invoice_number,cuf,status,reception_code,total_amount,issued_at',
        ]);
        $template = $this->templateFor($sale);

        return Inertia::render('Sales/Show', [
            'sale' => $sale,
            'template' => $template,
            'documentPolicy' => app(SalesDocumentPolicy::class)->summary(),
            'billingPolicy' => app(BillingWorkflowPolicy::class)->summary(),
            'paymentMethods' => Inertia::defer(fn () => $this->allowedPaymentMethods('collections'), 'sales-show-actions'),
            'conversionReadiness' => Inertia::defer(fn () => $this->conversionReadiness($sale), 'sales-show-actions'),
        ]);
    }

    public function convert(ConvertQuotationRequest $request, Sale $sale): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $sale->branch_id), 403);

        $newSale = DB::transaction(function () use ($request, $sale) {
            $quotation = Sale::query()
                ->with(['items.product:id,inventory_tracking_mode'])
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($quotation->document_type !== 'quotation' || $quotation->status !== 'quoted') {
                throw ValidationException::withMessages([
                    'quotation' => 'Solo se pueden convertir cotizaciones vigentes.',
                ]);
            }

            $autoSelectedCoils = [];
            $usedMetersByCoil = [];
            $coilProductIds = $quotation->items
                ->filter(fn (SaleItem $item) => $item->product->inventory_tracking_mode === Product::TRACKING_COIL && ! $item->product_coil_id)
                ->pluck('product_id')
                ->unique()
                ->values()
                ->all();
            $coilsByProduct = $this->availableCoilsByProduct((int) $quotation->branch_id, $coilProductIds);
            $reservedByCoil = $this->reservedMetersByCoil($coilsByProduct->flatten(1)->pluck('id')->values()->all());

            foreach ($quotation->items as $index => $item) {
                if ($item->product->inventory_tracking_mode !== Product::TRACKING_COIL) {
                    continue;
                }

                if ($item->product_coil_id) {
                    $usedMetersByCoil[$item->product_coil_id] = ($usedMetersByCoil[$item->product_coil_id] ?? 0) + (float) $item->meters;

                    continue;
                }

                $coil = $this->pickAvailableCoilForItem($item, $coilsByProduct, $reservedByCoil, $usedMetersByCoil);

                if (! $coil) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_coil_id" => 'No hay un lote o unidad fisica disponible con cantidad suficiente en la sucursal del documento.',
                    ]);
                }

                $autoSelectedCoils[$item->id] = $coil->id;
                $usedMetersByCoil[$coil->id] = ($usedMetersByCoil[$coil->id] ?? 0) + (float) $item->meters;
            }

            $newSale = Sale::query()->create([
                'branch_id' => $quotation->branch_id,
                'user_id' => $request->user()->id,
                'sale_type_id' => $quotation->sale_type_id,
                'currency_id' => $quotation->currency_id,
                'customer_id' => $quotation->customer_id,
                'advance_option_id' => $quotation->advance_option_id,
                'receipt_number' => $request->filled('receipt_number')
                    ? $request->validated('receipt_number')
                    : $this->nextReceiptNumber($quotation->branch_id, 'sale_note'),
                'document_type' => 'sale_note',
                'customer_name' => $quotation->customer_name,
                'customer_document' => $quotation->customer_document,
                'customer_contact' => $quotation->customer_contact,
                'sold_at' => now(),
                'exchange_rate_to_bob' => $quotation->exchange_rate_to_bob,
                'subtotal' => $quotation->subtotal,
                'discount_total' => $quotation->discount_total,
                'advance_percentage' => $quotation->advance_percentage,
                'advance_amount' => $quotation->advance_amount,
                'balance_due' => $quotation->total,
                'total' => $quotation->total,
                'status' => 'issued',
                'requires_delivery' => $request->boolean('requires_delivery'),
                'terms' => filled($quotation->terms) ? $quotation->terms : app(SalesDocumentPolicy::class)->defaultTermsFor('sale_note'),
                'internal_notes' => trim('Generada desde cotizacion '.$quotation->receipt_number),
            ]);

            $newSale->items()->createMany($quotation->items->map(fn (SaleItem $item) => [
                'product_id' => $item->product_id,
                'product_coil_id' => $item->product_coil_id ?: ($autoSelectedCoils[$item->id] ?? null),
                'description' => $item->description,
                'unit_label' => $item->unit_label,
                'display_quantity' => $item->display_quantity,
                'display_unit_label' => $item->display_unit_label,
                'item_attributes' => $item->item_attributes,
                'calculation_mode' => $item->calculation_mode,
                'meters' => $item->meters,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'total' => $item->total,
            ])->all());
            $newSale->load('items.product:id,inventory_tracking_mode');

            event(new SaleNoteIssued(
                saleId: (int) $newSale->id,
                userId: (int) $request->user()->id,
                sourceQuotationId: (int) $quotation->id,
            ));

            return $newSale;
        });

        $this->issueFiscalInvoiceIfRequired($newSale, $request->user()->id, afterQuotationConversion: true);

        return redirect()->route('sales.show', $newSale)->with('success', 'Cotizacion convertida en nota de venta.');
    }

    private function issueFiscalInvoiceIfRequired(Sale $sale, int $userId, bool $afterQuotationConversion): void
    {
        $billing = app(BillingWorkflowPolicy::class);
        $shouldIssue = $afterQuotationConversion
            ? $billing->shouldAutoIssueAfterQuotationConversion($sale)
            : $billing->shouldAutoIssueAfterSaleCreated($sale);

        if (! $shouldIssue) {
            return;
        }

        try {
            app(SiatInvoiceService::class)->issueFromSale($sale, $userId, $billing->allowTemporaryReceipt());
        } catch (\Throwable $exception) {
            if ($billing->blockSaleIfInvoiceFails()) {
                throw $exception;
            }

            report($exception);
        }
    }

    public function void(VoidSaleRequest $request, Sale $sale, SaleInventoryService $inventory): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $sale->branch_id), 403);

        DB::transaction(function () use ($request, $sale, $inventory) {
            $sale = Sale::query()
                ->with([
                    'items.deliveryItems:id,sale_item_id',
                    'items.product:id,inventory_tracking_mode',
                ])
                ->lockForUpdate()
                ->findOrFail($sale->id);
            $internalNotes = trim(implode("\n", array_filter([
                $sale->internal_notes,
                'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString(),
            ])));

            if ($sale->document_type === 'sale_note') {
                $inventory->returnForVoidedSale($sale, $request->user()->id);
            }

            $sale->update([
                'status' => 'void',
                'balance_due' => 0,
                'internal_notes' => $internalNotes,
            ]);
        });

        return redirect()->route('sales.index')->with('success', 'Documento anulado correctamente.');
    }

    private function conversionReadiness(Sale $sale): array
    {
        if ($sale->document_type !== 'quotation' || $sale->status !== 'quoted') {
            return [
                'can_convert' => false,
                'issues' => [],
                'items' => [],
            ];
        }

        $issues = [];
        $usedMetersByCoil = [];
        $coilItems = $sale->items->filter(fn (SaleItem $item) => $item->product?->inventory_tracking_mode === Product::TRACKING_COIL);
        $selectedCoilIds = $coilItems->pluck('product_coil_id')->filter()->unique()->values()->all();
        $coilsByProduct = $this->availableCoilsByProduct(
            (int) $sale->branch_id,
            $coilItems
                ->filter(fn (SaleItem $item) => ! $item->product_coil_id || ! $item->coil)
                ->pluck('product_id')
                ->unique()
                ->values()
                ->all(),
        );
        $reservedByCoil = $this->reservedMetersByCoil(array_values(array_unique([
            ...$selectedCoilIds,
            ...$coilsByProduct->flatten(1)->pluck('id')->values()->all(),
        ])));
        $reservedForQuotationByCoil = $this->reservedMetersForQuotationByCoil((int) $sale->id, $selectedCoilIds);
        $productIds = $sale->items->pluck('product_id')->filter()->unique()->values()->all();
        $stocksByProduct = ProductBranchStock::query()
            ->where('branch_id', $sale->branch_id)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'available_meters', 'reserved_meters'])
            ->keyBy('product_id');
        $reservedForQuotationByProduct = $this->reservedMetersForQuotationByProduct((int) $sale->id, $productIds);

        $items = $sale->items->map(function (SaleItem $item, int $index) use (&$issues, &$usedMetersByCoil, $coilsByProduct, $reservedByCoil, $reservedForQuotationByCoil, $stocksByProduct, $reservedForQuotationByProduct) {
            $required = (float) $item->meters;
            $product = $item->product;
            $unit = $this->readinessUnit($item);
            $available = 0.0;
            $reservedByOthers = 0.0;
            $free = 0.0;
            $message = null;
            $action = null;

            if (! $product) {
                $message = 'El item no tiene producto asociado.';
                $action = [
                    'label' => 'Ir a productos',
                    'url' => route('inventory.products.index'),
                ];
            } elseif ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                if (! $item->product_coil_id || ! $item->coil) {
                    $coil = $this->pickAvailableCoilForItem($item, $coilsByProduct, $reservedByCoil, $usedMetersByCoil);

                    if ($coil) {
                        $available = (float) $coil->available_meters;
                        $reservedByOthers = (float) ($reservedByCoil[$coil->id] ?? 0);
                        $free = max($available - $reservedByOthers - ($usedMetersByCoil[$coil->id] ?? 0), 0);
                        $usedMetersByCoil[$coil->id] = ($usedMetersByCoil[$coil->id] ?? 0) + $required;
                    } else {
                        $message = 'No hay un lote o unidad fisica disponible con cantidad suficiente en la sucursal del documento.';
                        $action = [
                            'label' => 'Ir a lotes/unidades',
                            'url' => route('inventory.coils.index'),
                        ];
                    }
                } elseif ($item->coil->status !== 'available') {
                    $message = 'El lote o unidad fisica seleccionada no esta disponible.';
                    $action = [
                        'label' => 'Revisar lotes/unidades',
                        'url' => route('inventory.coils.index'),
                    ];
                } else {
                    $available = (float) $item->coil->available_meters;
                    $reservedForQuotation = (float) ($reservedForQuotationByCoil[$item->product_coil_id] ?? 0);
                    $reserved = (float) ($reservedByCoil[$item->product_coil_id] ?? 0);
                    $reservedByOthers = max($reserved - $reservedForQuotation, 0);
                    $free = max($available - $reservedByOthers - ($usedMetersByCoil[$item->product_coil_id] ?? 0), 0);
                    $usedMetersByCoil[$item->product_coil_id] = ($usedMetersByCoil[$item->product_coil_id] ?? 0) + $required;
                }
            } else {
                $stock = $stocksByProduct->get($item->product_id);
                $available = (float) ($stock?->available_meters ?? 0);
                $reserved = (float) ($stock?->reserved_meters ?? 0);
                $reservedForQuotation = (float) ($reservedForQuotationByProduct[$item->product_id] ?? 0);
                $reservedByOthers = max($reserved - $reservedForQuotation, 0);
                $free = max($available - $reservedByOthers, 0);
            }

            if (! $message && $free < $required) {
                $missing = round($required - $free, 3);
                $message = 'Faltan '.$this->formatReadinessQuantity($missing, $unit).' libres para convertir este item.';
                $action = $product->inventory_tracking_mode === Product::TRACKING_COIL
                    ? [
                        'label' => 'Ir a lotes/unidades',
                        'url' => route('inventory.coils.index'),
                    ]
                    : [
                        'label' => 'Ver inventario',
                        'url' => route('inventory.stock.index'),
                    ];
            }

            if ($message) {
                $issues[] = 'Item '.($index + 1).": {$message}";
            }

            return [
                'item_id' => $item->id,
                'description' => $item->description,
                'required_meters' => round($required, 3),
                'available_meters' => round($available, 3),
                'reserved_by_others_meters' => round($reservedByOthers, 3),
                'free_meters' => round($free, 3),
                'required_label' => $this->formatReadinessQuantity($required, $unit),
                'available_label' => $this->formatReadinessQuantity($available, $unit),
                'free_label' => $this->formatReadinessQuantity($free, $unit),
                'can_convert' => $message === null,
                'message' => $message,
                'action' => $action,
            ];
        })->values()->all();

        return [
            'can_convert' => count($issues) === 0,
            'issues' => $issues,
            'items' => $items,
        ];
    }

    private function readinessUnit(SaleItem $item): string
    {
        return ($item->calculation_mode ?? 'direct') === 'direct'
            ? ($item->display_unit_label ?: $item->unit_label ?: 'unidad')
            : 'm';
    }

    private function availableCoilsByProduct(int $branchId, array $productIds)
    {
        if ($productIds === []) {
            return collect();
        }

        return ProductCoil::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->where('status', 'available')
            ->orderBy('available_meters')
            ->get(['id', 'branch_id', 'product_id', 'available_meters', 'status'])
            ->groupBy('product_id');
    }

    private function reservedMetersByCoil(array $coilIds)
    {
        if ($coilIds === []) {
            return collect();
        }

        return InventoryReservation::query()
            ->whereIn('product_coil_id', $coilIds)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->selectRaw('product_coil_id, SUM(meters) as reserved_meters')
            ->groupBy('product_coil_id')
            ->pluck('reserved_meters', 'product_coil_id');
    }

    private function reservedMetersForQuotationByCoil(int $saleId, array $coilIds)
    {
        if ($coilIds === []) {
            return collect();
        }

        return InventoryReservation::query()
            ->where('sale_id', $saleId)
            ->whereIn('product_coil_id', $coilIds)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->selectRaw('product_coil_id, SUM(meters) as reserved_meters')
            ->groupBy('product_coil_id')
            ->pluck('reserved_meters', 'product_coil_id');
    }

    private function reservedMetersForQuotationByProduct(int $saleId, array $productIds)
    {
        if ($productIds === []) {
            return collect();
        }

        return InventoryReservation::query()
            ->where('sale_id', $saleId)
            ->whereIn('product_id', $productIds)
            ->whereNull('product_coil_id')
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->selectRaw('product_id, SUM(meters) as reserved_meters')
            ->groupBy('product_id')
            ->pluck('reserved_meters', 'product_id');
    }

    private function pickAvailableCoilForItem(SaleItem $item, $coilsByProduct, $reservedByCoil, array $usedMetersByCoil = []): ?ProductCoil
    {
        $required = (float) $item->meters;

        return ($coilsByProduct->get($item->product_id) ?? collect())
            ->first(function (ProductCoil $coil) use ($required, $reservedByCoil, $usedMetersByCoil) {
                $reserved = (float) ($reservedByCoil[$coil->id] ?? 0);
                $alreadyPlanned = (float) ($usedMetersByCoil[$coil->id] ?? 0);

                return ((float) $coil->available_meters - $reserved - $alreadyPlanned) >= $required;
            });
    }

    private function formatReadinessQuantity(float $quantity, string $unit): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

        return "{$formatted} {$unit}";
    }

    private function sequencePreviews(Request $request): array
    {
        return DocumentSequence::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('is_active', true)
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($sequences) => $sequences
                ->mapWithKeys(fn (DocumentSequence $sequence) => [$sequence->document_type => $sequence->preview()])
                ->all())
            ->all();
    }

    private function availableCoils(Request $request)
    {
        return Cache::remember('sales-available-coils:v2:'.SystemCacheInvalidator::operationalVersion().":{$request->user()->id}", now()->addSeconds(self::CACHE_SECONDS), fn () => ProductCoil::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('status', 'available')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']));
    }

    private function convertibleQuotations(Request $request)
    {
        return Sale::query()
            ->with([
                'branch:id,name',
                'currency:id,name,code,symbol,exchange_rate_to_bob',
                'saleType:id,name',
                'advanceOption:id,name,type,percentage,amount',
                'items.product:id,name,sku,inventory_tracking_mode,product_category_id',
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'quotation')
            ->where('status', 'quoted')
            ->latest('sold_at')
            ->limit(100)
            ->get([
                'id',
                'branch_id',
                'sale_type_id',
                'currency_id',
                'customer_id',
                'advance_option_id',
                'advance_amount',
                'receipt_number',
                'customer_name',
                'customer_document',
                'customer_contact',
                'subtotal',
                'total',
                'terms',
                'internal_notes',
                'sold_at',
            ]);
    }

    private function nextReceiptNumber(int $branchId, string $documentType): string
    {
        $sequence = DocumentSequence::query()
            ->where('branch_id', $branchId)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $sequence = DocumentSequence::query()->create([
                'branch_id' => $branchId,
                'document_type' => $documentType,
                'name' => $documentType === 'quotation' ? 'Cotizaciones' : 'Notas de venta',
                'prefix' => $documentType === 'quotation' ? 'COT-' : 'NV-',
                'next_number' => 1,
                'padding' => 6,
                'is_active' => true,
            ]);
            $sequence = DocumentSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        do {
            $receiptNumber = $sequence->preview();
            $sequence->update(['next_number' => $sequence->next_number + 1]);
            $sequence->refresh();
        } while (Sale::query()->where('receipt_number', $receiptNumber)->exists());

        return $receiptNumber;
    }

    private function advanceValues(?AdvanceOption $advanceOption, float $total, float $manualAmount, string $advanceMode = 'none'): array
    {
        if ($advanceMode === 'amount') {
            return [0, round(min(max($manualAmount, 0), $total), 2)];
        }

        if (! $advanceOption) {
            return [0, 0];
        }

        $percentage = (float) $advanceOption->percentage;

        return [$percentage, round($total * ($percentage / 100), 2)];
    }

    private function templateFor(Sale $sale): array
    {
        $version = Cache::get('receipt-template-version', 1);
        $documentPolicy = app(SalesDocumentPolicy::class);
        $profileColumns = $documentPolicy->visibleTemplateColumns();
        $profileColumnSignature = md5(implode('|', $profileColumns));
        $cacheKey = 'receipt-template:v'.$version.':'.SystemCacheInvalidator::operationalVersion().":{$profileColumnSignature}:{$sale->branch_id}:{$sale->document_type}";

        return Cache::remember($cacheKey, now()->addSeconds(self::CACHE_SECONDS), function () use ($sale, $profileColumns) {
            $template = ReceiptTemplate::query()
                ->where('is_active', true)
                ->where(function ($query) use ($sale) {
                    $query->where('document_type', $sale->document_type)
                        ->orWhere('document_type', 'both');
                })
                ->where(function ($query) use ($sale) {
                    $query->where('branch_id', $sale->branch_id)
                        ->orWhereNull('branch_id');
                })
                ->orderByRaw('branch_id IS NULL')
                ->orderByDesc('is_default')
                ->latest('id')
                ->first();

            if (! $template) {
                return [
                    'paper_type' => 'letter',
                    'thermal_width_mm' => null,
                    'use_branding' => true,
                    'layout' => $this->layoutWithProfileColumns(ReceiptTemplate::defaultLayout(), $profileColumns),
                ];
            }

            return [
                'paper_type' => $template->paper_type,
                'thermal_width_mm' => $template->thermal_width_mm,
                'use_branding' => $template->use_branding,
                'layout' => $this->layoutWithProfileColumns(array_replace_recursive(ReceiptTemplate::defaultLayout(), $template->layout ?? []), $profileColumns),
            ];
        });
    }

    private function layoutWithProfileColumns(array $layout, array $profileColumns): array
    {
        if ($profileColumns === []) {
            return $layout;
        }

        $allowed = array_flip($profileColumns);
        $orders = collect($profileColumns)
            ->flip()
            ->map(fn (int $index) => $index + 1);
        $layout['item_columns'] = collect($layout['item_columns'] ?? ReceiptTemplate::defaultLayout()['item_columns'])
            ->map(function (array $column) use ($allowed) {
                $column['show'] = isset($allowed[$column['key']]);

                return $column;
            })
            ->values()
            ->all();

        foreach ($layout['item_columns'] as $index => $column) {
            if ($orders->has($column['key'])) {
                $layout['item_columns'][$index]['order'] = (int) $orders->get($column['key']);
            }

            $layout['fields'][$column['key']] = (bool) $column['show'];
        }

        foreach ($profileColumns as $columnKey) {
            $layout['fields'][$columnKey] = true;

            if (! collect($layout['item_columns'])->contains('key', $columnKey)) {
                $layout['item_columns'][] = [
                    'key' => $columnKey,
                    'label' => '',
                    'show' => true,
                    'align' => in_array($columnKey, ['item_quantity', 'item_base', 'item_price', 'item_subtotal'], true) ? 'right' : 'left',
                    'order' => (int) $orders->get($columnKey, 99),
                ];
            }
        }

        return $layout;
    }

    private function allowedPaymentMethods(string $flow = 'sales')
    {
        $policy = app(SalesDocumentPolicy::class);

        return UiCatalogCache::activePaymentMethods(['id', 'name', 'code', 'requires_reference'])
            ->filter(fn ($method) => $policy->isPaymentMethodAllowed($method->code, $flow))
            ->values();
    }

    private function saleItemAttributes(?Product $product, array $payloadAttributes): array
    {
        $payloadByCode = collect($payloadAttributes)->keyBy('code');

        return collect($product?->custom_attributes ?? [])
            ->map(function (array $definition) use ($payloadByCode) {
                $code = $definition['code'] ?? '';
                $payload = $payloadByCode->get($code, []);

                return [
                    'code' => $code,
                    'name' => $definition['name'] ?? $code,
                    'value' => data_get($payload, 'value', $definition['value'] ?? ''),
                    'unit' => $definition['unit'] ?? '',
                ];
            })
            ->filter(fn ($attribute) => filled($attribute['code'] ?? null))
            ->unique('code')
            ->values()
            ->all();
    }

    private function ensureProductsEnabledForBranch(array $productIds, int $branchId): void
    {
        $productIds = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();
        $enabledCount = ProductBranchStock::query()
            ->where('branch_id', $branchId)
            ->where('is_enabled', true)
            ->whereIn('product_id', $productIds)
            ->distinct('product_id')
            ->count('product_id');

        if ($enabledCount !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'Uno o mas productos no estan habilitados para la sucursal seleccionada.',
            ]);
        }
    }

    private function ensureStockPolicyAllowsSale(array $items, $products, int $branchId, $user, CommercialPolicy $policy): void
    {
        $requiredByProduct = collect($items)
            ->filter(function ($item) use ($products) {
                $product = $products->get((int) $item['product_id']);

                return $product?->inventory_tracking_mode !== Product::TRACKING_COIL;
            })
            ->groupBy('product_id')
            ->map(fn ($group) => round((float) $group->sum('meters'), 3));

        if ($requiredByProduct->isEmpty()) {
            return;
        }

        $stocks = ProductBranchStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $requiredByProduct->keys())
            ->get(['product_id', 'available_meters', 'reserved_meters'])
            ->keyBy('product_id');

        foreach ($requiredByProduct as $productId => $required) {
            $stock = $stocks->get((int) $productId);
            $available = $stock ? (float) $stock->available_meters - (float) $stock->reserved_meters : 0.0;

            if ($available >= $required) {
                continue;
            }

            $product = $products->get((int) $productId);
            $categoryName = $product?->productCategory?->name;

            if (! $policy->canSellNegativeStock($user, $categoryName)) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        'Stock insuficiente para %s. Disponible: %s, requerido: %s. La politica comercial no permite vender con stock negativo.',
                        $product?->name ?? 'producto',
                        number_format($available, 3, '.', ''),
                        number_format($required, 3, '.', ''),
                    ),
                ]);
            }
        }
    }
}
