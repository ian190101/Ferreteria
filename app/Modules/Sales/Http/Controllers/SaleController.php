<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Http\Requests\ConvertQuotationRequest;
use App\Modules\Sales\Http\Requests\StoreSaleDocumentRequest;
use App\Modules\Sales\Http\Requests\VoidSaleRequest;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\ReceiptTemplate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request): Response
    {
        $sales = Sale::query()
            ->with(['branch:id,name', 'user:id,name', 'saleType:id,name', 'currency:id,name,code,symbol'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('document_type'), fn ($query) => $query->where('document_type', $request->string('document_type')->toString()))
            ->latest('sold_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $request->only('per_page'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Form', [
            'documentType' => $request->string('type', 'sale_note')->toString(),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name', 'address', 'phone', 'secondary_phone', 'point_of_sale_name']),
            'saleTypes' => UiCatalogCache::saleTypes(),
            'currencies' => UiCatalogCache::currencies(),
            'advanceOptions' => UiCatalogCache::advanceOptions(),
            'products' => UiCatalogCache::activeProductsWithThickness(),
            'coils' => $this->availableCoils($request),
            'customers' => UiCatalogCache::recentCustomers(),
            'sequencePreviews' => $this->sequencePreviews($request),
            'quotations' => $request->string('type', 'sale_note')->toString() === 'sale_note'
                ? $this->convertibleQuotations($request)
                : [],
        ]);
    }

    public function store(StoreSaleDocumentRequest $request): RedirectResponse
    {
        $sale = DB::transaction(function () use ($request) {
            $currency = Currency::query()->findOrFail($request->integer('currency_id'));
            $customer = $request->filled('customer_id')
                ? Customer::query()->findOrFail($request->integer('customer_id'))
                : null;
            $advanceOption = $request->filled('advance_option_id')
                ? AdvanceOption::query()->findOrFail($request->integer('advance_option_id'))
                : null;

            $canOverridePrices = $request->user()->can('sales.prices.override');
            $validatedItems = collect($request->validated('items'));
            $products = Product::query()
                ->with(['unit:id,symbol', 'productCategory.attributes.unit:id,symbol'])
                ->whereIn('id', $validatedItems->pluck('product_id')->unique()->values())
                ->get(['id', 'product_category_id', 'product_unit_id', 'name', 'sale_price', 'attributes'])
                ->keyBy('id');

            $items = $validatedItems->map(function (array $item) use ($canOverridePrices, $products) {
                $product = $products->get($item['product_id']);

                $item['display_quantity'] = $item['display_quantity'] ?? 1;
                $item['display_unit_label'] = $item['display_unit_label'] ?? $product?->unit?->symbol ?? $item['unit_label'];
                $item['item_attributes'] = $this->saleItemAttributes($product, $item['item_attributes'] ?? []);
                $item['calculation_mode'] = $item['calculation_mode'] ?? 'direct';
                $item['unit_price'] = $canOverridePrices ? $item['unit_price'] : (float) ($product?->sale_price ?? 0);
                $lineTotal = ((float) $item['meters'] * (float) $item['unit_price']) - (float) $item['discount_amount'];
                $item['total'] = max(round($lineTotal, 2), 0);

                return $item;
            });

            $subtotal = round($items->sum(fn ($item) => (float) $item['meters'] * (float) $item['unit_price']), 2);
            $discountTotal = round($items->sum(fn ($item) => (float) $item['discount_amount']), 2);
            $total = round($items->sum('total'), 2);
            $advancePercentage = $advanceOption ? (float) $advanceOption->percentage : 0;
            $advanceAmount = round($total * ($advancePercentage / 100), 2);

            $sourceQuotation = $request->filled('source_quotation_id')
                ? Sale::query()
                    ->with('items')
                    ->whereKey($request->integer('source_quotation_id'))
                    ->lockForUpdate()
                    ->first()
                : null;

            $sale = Sale::query()->create([
                ...$request->safe()->except(['items', 'source_quotation_id']),
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
            ]);

            $sale->items()->createMany($items->all());
            $sale->load('items.product:id,inventory_tracking_mode');

            if ($sale->document_type === 'sale_note') {
                $this->decrementInventoryForSale($sale, $request->user()->id);

                if ($sourceQuotation) {
                    $this->consumeReservationsForQuotation($sourceQuotation, $sale);
                    $sourceQuotation->update([
                        'status' => 'converted',
                        'internal_notes' => trim(implode("\n", array_filter([
                            $sourceQuotation->internal_notes,
                            'Convertida a nota de venta '.$sale->receipt_number,
                        ]))),
                    ]);
                }
            }

            return $sale;
        });

        return redirect()->route('sales.show', $sale)->with('success', 'Documento generado correctamente.');
    }

    public function show(Sale $sale): Response
    {
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $sale->branch_id), 403);

        $sale->load(['branch.setting', 'user:id,name', 'saleType', 'currency', 'advanceOption', 'items.product:id,name,sku,inventory_tracking_mode', 'items.coil:id,barcode,lot_number,available_meters,status', 'payments.method:id,name']);
        $template = $this->templateFor($sale);

        return Inertia::render('Sales/Show', [
            'sale' => $sale,
            'template' => $template,
            'paymentMethods' => UiCatalogCache::activePaymentMethods(),
            'conversionReadiness' => $this->conversionReadiness($sale),
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

            foreach ($quotation->items as $index => $item) {
                if ($item->product->inventory_tracking_mode !== Product::TRACKING_COIL) {
                    continue;
                }

                if ($item->product_coil_id) {
                    $usedMetersByCoil[$item->product_coil_id] = ($usedMetersByCoil[$item->product_coil_id] ?? 0) + (float) $item->meters;

                    continue;
                }

                $coil = $this->availableCoilForItem($quotation, $item, $usedMetersByCoil);

                if (! $coil) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_coil_id" => 'No hay una bobina disponible con metraje suficiente en la sucursal del documento.',
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
                'terms' => $quotation->terms,
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

            $this->consumeReservationsForQuotation($quotation, $newSale);
            $this->decrementInventoryForSale($newSale, $request->user()->id);

            $quotation->update([
                'status' => 'converted',
                'internal_notes' => trim(implode("\n", array_filter([
                    $quotation->internal_notes,
                    'Convertida a nota de venta '.$newSale->receipt_number,
                ]))),
            ]);

            return $newSale;
        });

        return redirect()->route('sales.show', $newSale)->with('success', 'Cotizacion convertida en nota de venta.');
    }

    public function void(VoidSaleRequest $request, Sale $sale): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $sale->branch_id), 403);

        DB::transaction(function () use ($request, $sale) {
            $sale = Sale::query()->with('items.product:id,inventory_tracking_mode')->lockForUpdate()->findOrFail($sale->id);
            $internalNotes = trim(implode("\n", array_filter([
                $sale->internal_notes,
                'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString(),
            ])));

            if ($sale->document_type === 'sale_note') {
                $this->returnInventoryForVoidedSale($sale, $request->user()->id);
            }

            $sale->update([
                'status' => 'void',
                'balance_due' => 0,
                'internal_notes' => $internalNotes,
            ]);
        });

        return redirect()->route('sales.index')->with('success', 'Documento anulado correctamente.');
    }

    private function decrementInventoryForSale(Sale $sale, int $userId): void
    {
        foreach ($sale->items as $item) {
            $product = $item->product;

            if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $this->decrementCoilStock($sale, $item, $userId);

                continue;
            }

            $this->decrementGlobalStock($sale, $item, $userId);
        }
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
        $items = $sale->items->map(function (SaleItem $item, int $index) use ($sale, &$issues, &$usedMetersByCoil) {
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
                    $coil = $this->availableCoilForItem($sale, $item, $usedMetersByCoil);

                    if ($coil) {
                        $available = (float) $coil->available_meters;
                        $reservedByOthers = (float) InventoryReservation::query()
                            ->where('product_coil_id', $coil->id)
                            ->where('status', InventoryReservation::STATUS_ACTIVE)
                            ->sum('meters');
                        $free = max($available - $reservedByOthers - ($usedMetersByCoil[$coil->id] ?? 0), 0);
                        $usedMetersByCoil[$coil->id] = ($usedMetersByCoil[$coil->id] ?? 0) + $required;
                    } else {
                        $message = 'No hay una bobina disponible con metraje suficiente en la sucursal del documento.';
                        $action = [
                            'label' => 'Ir a bobinas',
                            'url' => route('inventory.coils.index'),
                        ];
                    }
                } elseif ($item->coil->status !== 'available') {
                    $message = 'La bobina seleccionada no esta disponible.';
                    $action = [
                        'label' => 'Revisar bobinas',
                        'url' => route('inventory.coils.index'),
                    ];
                } else {
                    $available = (float) $item->coil->available_meters;
                    $reservedForQuotation = (float) InventoryReservation::query()
                        ->where('sale_id', $sale->id)
                        ->where('product_coil_id', $item->product_coil_id)
                        ->where('status', InventoryReservation::STATUS_ACTIVE)
                        ->sum('meters');
                    $reserved = (float) InventoryReservation::query()
                        ->where('product_coil_id', $item->product_coil_id)
                        ->where('status', InventoryReservation::STATUS_ACTIVE)
                        ->sum('meters');
                    $reservedByOthers = max($reserved - $reservedForQuotation, 0);
                    $free = max($available - $reservedByOthers - ($usedMetersByCoil[$item->product_coil_id] ?? 0), 0);
                    $usedMetersByCoil[$item->product_coil_id] = ($usedMetersByCoil[$item->product_coil_id] ?? 0) + $required;
                }
            } else {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $sale->branch_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                $available = (float) ($stock?->available_meters ?? 0);
                $reserved = (float) ($stock?->reserved_meters ?? 0);
                $reservedForQuotation = (float) InventoryReservation::query()
                    ->where('sale_id', $sale->id)
                    ->where('product_id', $item->product_id)
                    ->whereNull('product_coil_id')
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->sum('meters');
                $reservedByOthers = max($reserved - $reservedForQuotation, 0);
                $free = max($available - $reservedByOthers, 0);
            }

            if (! $message && $free < $required) {
                $missing = round($required - $free, 3);
                $message = 'Faltan '.$this->formatReadinessQuantity($missing, $unit).' libres para convertir este item.';
                $action = $product->inventory_tracking_mode === Product::TRACKING_COIL
                    ? [
                        'label' => 'Ir a bobinas',
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

    private function availableCoilForItem(Sale $sale, SaleItem $item, array $usedMetersByCoil = []): ?ProductCoil
    {
        $required = (float) $item->meters;

        return ProductCoil::query()
            ->where('branch_id', $sale->branch_id)
            ->where('product_id', $item->product_id)
            ->where('status', 'available')
            ->orderBy('available_meters')
            ->get()
            ->first(function (ProductCoil $coil) use ($required, $usedMetersByCoil) {
                $reserved = (float) InventoryReservation::query()
                    ->where('product_coil_id', $coil->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->sum('meters');
                $alreadyPlanned = (float) ($usedMetersByCoil[$coil->id] ?? 0);

                return ((float) $coil->available_meters - $reserved - $alreadyPlanned) >= $required;
            });
    }

    private function formatReadinessQuantity(float $quantity, string $unit): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');

        return "{$formatted} {$unit}";
    }

    private function consumeReservationsForQuotation(Sale $quotation, Sale $newSale): void
    {
        $reservations = InventoryReservation::query()
            ->where('sale_id', $quotation->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if (! $reservation->product_coil_id) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $reservation->branch_id)
                    ->where('product_id', $reservation->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $stock->update([
                        'reserved_meters' => max(round((float) $stock->reserved_meters - (float) $reservation->meters, 3), 0),
                    ]);
                }
            }

            $reservation->update([
                'status' => InventoryReservation::STATUS_CONSUMED,
                'consumed_sale_id' => $newSale->id,
                'consumed_at' => now(),
            ]);
        }
    }

    private function decrementGlobalStock(Sale $sale, SaleItem $item, int $userId): void
    {
        $stock = ProductBranchStock::query()
            ->where('branch_id', $sale->branch_id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            throw ValidationException::withMessages([
                'items' => 'La sucursal no tiene stock global suficiente para completar la venta.',
            ]);
        }

        $before = (float) $stock->available_meters;
        $after = round($before - (float) $item->meters, 3);

        $reserved = (float) $stock->reserved_meters;

        if ($after < 0 || $after < $reserved) {
            throw ValidationException::withMessages([
                'items' => 'La sucursal no tiene stock global libre suficiente para completar la venta.',
            ]);
        }

        $stock->update(['available_meters' => $after]);
        $this->movement($sale, $item, $userId, 'sale_stock_out', -((float) $item->meters), $before, $after, null);
    }

    private function decrementCoilStock(Sale $sale, SaleItem $item, int $userId): void
    {
        $coil = ProductCoil::query()->lockForUpdate()->findOrFail($item->product_coil_id);

        if ((int) $coil->branch_id !== (int) $sale->branch_id || (int) $coil->product_id !== (int) $item->product_id || $coil->status !== 'available') {
            throw ValidationException::withMessages([
                'items' => 'Una bobina seleccionada ya no esta disponible para la venta.',
            ]);
        }

        $before = (float) $coil->available_meters;
        $after = round($before - (float) $item->meters, 3);

        $reserved = (float) InventoryReservation::query()
            ->where('product_coil_id', $coil->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->sum('meters');

        if ($after < 0 || $after < $reserved) {
            throw ValidationException::withMessages([
                'items' => 'Una bobina seleccionada no tiene metraje libre suficiente.',
            ]);
        }

        $coil->update([
            'available_meters' => $after,
            'status' => $after <= 0 ? 'depleted' : 'available',
        ]);
        $this->movement($sale, $item, $userId, 'sale_stock_out', -((float) $item->meters), $before, $after, $coil->id);
    }

    private function returnInventoryForVoidedSale(Sale $sale, int $userId): void
    {
        foreach ($sale->items as $item) {
            $hadStockOut = InventoryMovement::query()
                ->where('source_type', SaleItem::class)
                ->where('source_id', $item->id)
                ->where('type', 'sale_stock_out')
                ->exists();
            $alreadyReturned = InventoryMovement::query()
                ->where('source_type', SaleItem::class)
                ->where('source_id', $item->id)
                ->where('type', 'sale_void_return')
                ->exists();

            if (! $hadStockOut || $alreadyReturned) {
                continue;
            }

            if ($item->product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $this->returnCoilStock($sale, $item, $userId);

                continue;
            }

            $this->returnGlobalStock($sale, $item, $userId);
        }
    }

    private function returnGlobalStock(Sale $sale, SaleItem $item, int $userId): void
    {
        $stock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $sale->branch_id,
            'product_id' => $item->product_id,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
        ]);
        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = (float) $stock->available_meters;
        $after = round($before + (float) $item->meters, 3);

        $stock->update(['available_meters' => $after]);
        $this->movement($sale, $item, $userId, 'sale_void_return', (float) $item->meters, $before, $after, null);
    }

    private function returnCoilStock(Sale $sale, SaleItem $item, int $userId): void
    {
        $coil = ProductCoil::query()->lockForUpdate()->findOrFail($item->product_coil_id);
        $before = (float) $coil->available_meters;
        $after = round($before + (float) $item->meters, 3);

        $coil->update([
            'available_meters' => $after,
            'status' => 'available',
        ]);
        $this->movement($sale, $item, $userId, 'sale_void_return', (float) $item->meters, $before, $after, $coil->id);
    }

    private function movement(Sale $sale, SaleItem $item, int $userId, string $type, float $delta, float $before, float $after, ?int $coilId): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $sale->branch_id,
            'product_id' => $item->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => SaleItem::class,
            'source_id' => $item->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => "Venta {$sale->receipt_number}",
            'created_at' => $sale->sold_at,
        ]);
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
        return ProductCoil::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('status', 'available')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']);
    }

    private function convertibleQuotations(Request $request)
    {
        return Sale::query()
            ->with([
                'branch:id,name',
                'currency:id,name,code,symbol,exchange_rate_to_bob',
                'saleType:id,name',
                'advanceOption:id,name,percentage',
                'items.product:id,name,sku,inventory_tracking_mode',
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

    private function templateFor(Sale $sale): array
    {
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
                'layout' => ReceiptTemplate::defaultLayout(),
            ];
        }

        return [
            'paper_type' => $template->paper_type,
            'thermal_width_mm' => $template->thermal_width_mm,
            'use_branding' => $template->use_branding,
            'layout' => array_replace_recursive(ReceiptTemplate::defaultLayout(), $template->layout ?? []),
        ];
    }

    private function saleItemAttributes(?Product $product, array $payloadAttributes): array
    {
        $payloadByCode = collect($payloadAttributes)->keyBy('code');

        return $product?->productCategory?->attributes
            ->map(function ($definition) use ($product, $payloadByCode) {
                $payload = $payloadByCode->get($definition->code, []);
                $defaultValue = data_get($product->attributes ?? [], $definition->code);
                $value = data_get($payload, 'value', $defaultValue);

                return [
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'value' => blank($value) ? '-' : (string) $value,
                    'unit' => $definition->unit?->symbol,
                ];
            })
            ->values()
            ->all() ?? [];
    }
}
