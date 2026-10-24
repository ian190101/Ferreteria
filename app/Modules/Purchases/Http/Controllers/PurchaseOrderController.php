<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Purchases\Http\Requests\StorePurchaseOrderReceiptRequest;
use App\Modules\Purchases\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchaseOrderReceipt;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = PurchaseOrder::query()
            ->with(['branch:id,name', 'supplier:id,name', 'user:id,name', 'convertedPurchase:id,document_number'])
            ->withSum('items as ordered_meters', 'meters')
            ->withSum('items as received_meters', 'received_meters')
            ->withCount('items')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('ordered_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Purchases/Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'per_page']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Purchases/Orders/Form', [
            'branches' => UiCatalogCache::activeBranchesForUser(request()->user()),
            'suppliers' => UiCatalogCache::activeSuppliers(),
            'products' => UiCatalogCache::activeProductsWithThicknessForUser($request->user()),
            'lotTrackingEnabled' => app(\App\Modules\Inventory\Services\LotTrackingPolicy::class)->enabled(
                app(\App\Modules\Inventory\Services\LotTrackingPolicy::class)->activeConfiguration()),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $order = DB::transaction(function () use ($request) {
            app(\App\Modules\Inventory\Services\LotTrackingPolicy::class)->lockRule();
            $payloadItems = collect($request->validated('items'));
            $products = Product::query()
                ->with('thickness')
                ->whereIn('id', $payloadItems->pluck('product_id')->unique()->values())
                ->get(['id', 'thickness_id', 'name', 'is_inventory_item', 'requires_lot'])
                ->keyBy('id');
            $items = $payloadItems->map(fn (array $item) => $this->normalizeItem($item, $products));
            $status = $request->string('status')->toString();

            $order = PurchaseOrder::query()->create([
                'branch_id' => $request->integer('branch_id'),
                'supplier_id' => $request->input('supplier_id'),
                'user_id' => $request->user()->id,
                'approved_by' => $status === PurchaseOrder::STATUS_APPROVED ? $request->user()->id : null,
                'order_number' => $request->string('order_number')->toString(),
                'ordered_at' => $request->date('ordered_at'),
                'expected_at' => $request->date('expected_at'),
                'total_amount' => $items->reduce(fn (string $sum, array $item) => bcadd($sum, (string) $item['line_total'], 2), '0.00'),
                'status' => $status,
                'notes' => $request->string('notes')->toString() ?: null,
                'approved_at' => $status === PurchaseOrder::STATUS_APPROVED ? now() : null,
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'product_coil_id' => app(\App\Modules\Inventory\Support\LotPayloadMapper::class)->toDatabase($item)['product_coil_id'],
                    'coil_barcode' => $item['coil_barcode'] ?? null,
                    'kilograms' => $item['kilograms'],
                    'meters' => $item['meters'],
                    'unit_cost' => $item['unit_cost'],
                    'conversion_factor' => $item['conversion_factor'],
                    'lot_number' => $item['lot_number'] ?? null,
                    'description' => $item['description'],
                ]);
            }

            return $order;
        });

        return redirect()->route('purchases.orders.index')->with('success', "Orden {$order->order_number} registrada correctamente.");
    }

    public function approve(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($request->user()?->can('purchases.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes en borrador pueden aprobarse.']);
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', 'Orden aprobada correctamente.');
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($request->user()?->can('purchases.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if ($order->status === PurchaseOrder::STATUS_CONVERTED) {
            throw ValidationException::withMessages(['order' => 'No se puede cancelar una orden convertida en compra.']);
        }

        $order->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return back()->with('success', 'Orden cancelada correctamente.');
    }

    public function receive(PurchaseOrder $order): Response
    {
        abort_unless(request()->user()?->can('purchases.manage'), 403);
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $order->branch_id), 403);

        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes aprobadas o parcialmente recibidas pueden recibir mercaderia.']);
        }

        return Inertia::render('Purchases/Orders/Receive', [
            'order' => $order->load(['branch:id,name', 'supplier:id,name', 'items.product:id,name,sku,inventory_tracking_mode,requires_lot,base_unit']),
            'lotTrackingEnabled' => app(\App\Modules\Inventory\Services\LotTrackingPolicy::class)->enabled(
                app(\App\Modules\Inventory\Services\LotTrackingPolicy::class)->activeConfiguration()),
        ]);
    }

    public function storeReceipt(StorePurchaseOrderReceiptRequest $request, PurchaseOrder $order, InventoryQuantityService $quantities): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes aprobadas o parcialmente recibidas pueden recibir mercaderia.']);
        }

        $purchase = DB::transaction(fn () => $this->createReceipt($order, $request->validated(), $request->user(), $quantities));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Recepcion registrada y compra creada correctamente.');
    }

    public function convert(Request $request, PurchaseOrder $order, InventoryQuantityService $quantities): RedirectResponse
    {
        abort_unless($request->user()?->can('purchases.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes aprobadas o parcialmente recibidas pueden convertirse en compra.']);
        }
        $lotPolicy = app(\App\Modules\Inventory\Services\LotTrackingPolicy::class);
        if ($order->items()->with('product')->get()->contains(fn (PurchaseOrderItem $item) =>
            $lotPolicy->required($item->product, $lotPolicy->activeConfiguration()))) {
            throw ValidationException::withMessages(['order' => 'La recepción de productos gestionados requiere elegir un lote por cada cantidad recibida.']);
        }

        $payload = [
            'received_at' => now()->toDateString(),
            'notes' => null,
            'items' => $order->items()->get()->map(fn (PurchaseOrderItem $item) => [
                'purchase_order_item_id' => $item->id,
                'meters' => round((float) $item->meters - (float) $item->received_meters, 3),
                'kilograms' => $item->kilograms,
                'coil_barcode' => $item->coil_barcode,
            ])->filter(fn (array $item) => $item['meters'] > 0)->values()->all(),
        ];

        if ($payload['items'] === []) {
            throw ValidationException::withMessages(['order' => 'La orden no tiene saldo pendiente por recibir.']);
        }

        $purchase = DB::transaction(fn () => $this->createReceipt($order, $payload, $request->user(), $quantities));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Orden convertida en compra recibida correctamente.');
    }

    private function createReceipt(PurchaseOrder $order, array $payload, User $user, InventoryQuantityService $quantities): Purchase
    {
        $lotPolicy = app(\App\Modules\Inventory\Services\LotTrackingPolicy::class);
        $lotPolicy->lockRule();
        $lotConfiguration = $lotPolicy->persistedConfiguration();
        $order = PurchaseOrder::query()->with('items.product')->lockForUpdate()->findOrFail($order->id);
        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'La orden ya no admite recepciones.']);
        }
        $payloadItems = collect($payload['items'] ?? [])
            ->filter(fn (array $item) => collect(['meters', 'kilograms'])->contains(fn (string $field) =>
                preg_match('/^\d+(?:\.\d+)?$/D', (string) ($item[$field] ?? ''))
                && bccomp((string) $item[$field], '0', 3) > 0))
            ->values();

        $orderItems = PurchaseOrderItem::query()
            ->whereIn('id', $payloadItems->pluck('purchase_order_item_id'))
            ->with('product.thickness')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $normalizedItems = $payloadItems->map(function (array $payloadItem) use ($order, $orderItems, $lotPolicy, $lotConfiguration, $quantities) {
            $orderItem = $orderItems->get($payloadItem['purchase_order_item_id']);

            if (! $orderItem || $orderItem->purchase_order_id !== $order->id) {
                throw ValidationException::withMessages(['items' => 'El item recibido no pertenece a esta orden de compra.']);
            }

            if ($lotPolicy->required($orderItem->product, $lotConfiguration)) {
                try {
                    $meters = app(\App\Modules\Purchases\Services\PurchaseReceiptService::class)->orderQuantity($orderItem, $payloadItem);
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['items' => $exception->getMessage()]);
                }
                $pending = $quantities->subtractExact((string) $orderItem->meters, (string) $orderItem->received_meters);
                if ($quantities->compareExact($meters, $pending) > 0) {
                    throw ValidationException::withMessages(['items' => 'La recepción supera el saldo pendiente de la orden.']);
                }

                return [
                    'order_item' => $orderItem,
                    'meters' => $meters,
                    'kilograms' => filled($payloadItem['kilograms'] ?? null) ? $quantities->multiplyExact((string) $payloadItem['kilograms'], ($payloadItem['weight_unit'] ?? 'kg') === 'ton' ? '1000' : '1') : null,
                    'coil_barcode' => $payloadItem['coil_barcode'] ?? null,
                    'lot_id' => $payloadItem['lot_id'] ?? null,
                    'new_lot_code' => $payloadItem['new_lot_code'] ?? null,
                    'line_total' => $quantities->multiplyExact($meters, (string) $orderItem->unit_cost, 2),
                ];
            }
            $meters = round((float) $payloadItem['meters'], 3);
            $pending = round((float) $orderItem->meters - (float) $orderItem->received_meters, 3);

            if ($meters > $pending) {
                throw ValidationException::withMessages(['items' => 'La recepcion supera el saldo pendiente de la orden.']);
            }

            if ($orderItem->product->inventory_tracking_mode === Product::TRACKING_COIL && blank($payloadItem['coil_barcode'] ?? $orderItem->coil_barcode)) {
                throw ValidationException::withMessages(['items' => 'La recepcion por bobina requiere barcode unico.']);
            }

            return [
                'order_item' => $orderItem,
                'meters' => $meters,
                'kilograms' => $this->normalizeKilograms($payloadItem),
                'coil_barcode' => $payloadItem['coil_barcode'] ?? $orderItem->coil_barcode,
                'line_total' => round($meters * (float) $orderItem->unit_cost, 2),
            ];
        });
        $receivedMetersByItem = $normalizedItems
            ->groupBy(fn (array $item) => $item['order_item']->id)
            ->map(fn ($rows) => $rows->reduce(fn (string $sum, array $item) => bcadd($sum, (string) $item['meters'], 3), '0.000'));
        foreach ($receivedMetersByItem as $orderItemId => $received) {
            $row = $orderItems->get($orderItemId);
            if ($quantities->compareExact($received, $quantities->subtractExact((string) $row->meters, (string) $row->received_meters)) > 0) {
                throw ValidationException::withMessages(['items' => 'La suma de los lotes recibidos supera la cantidad pendiente de la orden.']);
            }
        }

        $totalAmount = $normalizedItems->reduce(fn (string $sum, array $item) => bcadd($sum, (string) $item['line_total'], 2), '0.00');
        $receiptNumber = 'REC-'.mb_substr($order->order_number, 0, 45).'-'.now()->format('YmdHis').'-'.\Illuminate\Support\Str::lower((string) \Illuminate\Support\Str::ulid());

        $purchase = Purchase::query()->create([
            'branch_id' => $order->branch_id,
            'supplier_id' => $order->supplier_id,
            'user_id' => $user->id,
            'document_number' => $receiptNumber,
            'purchase_date' => now()->toDateString(),
            'total_amount' => $totalAmount,
            'paid_amount' => 0,
            'balance_due' => $totalAmount,
            'payment_status' => $totalAmount > 0 ? 'unpaid' : 'paid',
            'status' => 'received',
        ]);

        $receipt = PurchaseOrderReceipt::query()->create([
            'purchase_order_id' => $order->id,
            'purchase_id' => $purchase->id,
            'branch_id' => $order->branch_id,
            'supplier_id' => $order->supplier_id,
            'user_id' => $user->id,
            'receipt_number' => $receiptNumber,
            'received_at' => now()->toDateString(),
            'total_amount' => $totalAmount,
            'notes' => $payload['notes'] ?? null,
        ]);

        $plan = $normalizedItems->filter(fn (array $item) => $lotPolicy->required($item['order_item']->product, $lotConfiguration))
            ->map(fn (array $item) => [
                'product_id' => (int) $item['order_item']->product_id,
                'branch_id' => (int) $purchase->branch_id,
                'lot_id' => $item['lot_id'] ?? null,
            ])->all();
        if ($plan !== []) {
            app(\App\Modules\Inventory\Services\LotInventoryService::class)->lockBatch($plan, true);
        }

        foreach ($normalizedItems as $item) {
            $orderItem = $item['order_item'];
            $coil = null;

            if ($lotPolicy->required($orderItem->product, $lotConfiguration)) {
                $purchaseItem = $purchase->items()->create([
                    'product_id' => $orderItem->product_id,
                    'kilograms' => $item['kilograms'],
                    'meters' => $item['meters'],
                    'unit_cost' => $orderItem->unit_cost,
                    'conversion_factor' => $orderItem->conversion_factor,
                    'description' => $orderItem->description,
                ]);
                $lot = app(\App\Modules\Purchases\Services\PurchaseReceiptService::class)
                    ->receiveManaged($purchaseItem, $item, (int) $purchase->branch_id, (int) $user->id);
                $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_coil_id' => $lot->id,
                    'lot_code_snapshot' => $lot->lot_code,
                    'coil_barcode' => $lot->barcode,
                    'kilograms' => $item['kilograms'],
                    'meters' => $item['meters'],
                    'unit_cost' => $orderItem->unit_cost,
                    'line_total' => $item['line_total'],
                ]);
                $orderItem->received_meters = $quantities->addExact((string) $orderItem->received_meters, $item['meters']);
                $orderItem->save();
                continue;
            }

            if ($orderItem->product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $coil = ProductCoil::query()->create([
                    'branch_id' => $purchase->branch_id,
                    'product_id' => $orderItem->product_id,
                    'barcode' => $item['coil_barcode'],
                    'lot_number' => $orderItem->lot_number,
                    'initial_meters' => $item['meters'],
                    'available_meters' => $item['meters'],
                    'initial_kg' => $item['kilograms'],
                    'status' => 'available',
                ]);
            } else {
                $stock = ProductBranchStock::query()->firstOrCreate([
                    'branch_id' => $purchase->branch_id,
                    'product_id' => $orderItem->product_id,
                ], [
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                ]);

                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                [$before, $after] = $quantities->increase($stock, (float) $item['meters']);
                $this->movement($purchase, $orderItem->product_id, null, $user->id, $item['meters'], $before, $after, 'purchase_order_receipt_global');
            }

            $purchaseItem = $purchase->items()->create([
                'product_id' => $orderItem->product_id,
                'product_coil_id' => $coil?->id,
                'coil_barcode' => $item['coil_barcode'],
                'kilograms' => $item['kilograms'],
                'meters' => $item['meters'],
                'unit_cost' => $orderItem->unit_cost,
                'conversion_factor' => $orderItem->conversion_factor,
                'lot_number' => $orderItem->lot_number,
                'description' => $orderItem->description,
            ]);

            $receipt->items()->create([
                'purchase_order_item_id' => $orderItem->id,
                'product_id' => $orderItem->product_id,
                'product_coil_id' => $coil?->id,
                'coil_barcode' => $item['coil_barcode'],
                'kilograms' => $item['kilograms'],
                'meters' => $item['meters'],
                'unit_cost' => $orderItem->unit_cost,
                'line_total' => $item['line_total'],
            ]);

            if ($coil) {
                $this->movement($purchaseItem, $orderItem->product_id, $coil->id, $user->id, $item['meters'], 0, (float) $coil->available_meters, 'purchase_order_receipt_coil');
            }

            $orderItem->increment('received_meters', $item['meters']);
        }

        $allReceived = $order->items->every(fn (PurchaseOrderItem $item) =>
            bccomp(bcadd((string) $item->received_meters, (string) ($receivedMetersByItem[$item->id] ?? '0.000'), 3), (string) $item->meters, 3) >= 0);
        $order->update([
            'status' => $allReceived ? PurchaseOrder::STATUS_CONVERTED : PurchaseOrder::STATUS_PARTIAL_RECEIVED,
            'converted_purchase_id' => $allReceived ? $purchase->id : $order->converted_purchase_id,
            'converted_at' => $allReceived ? now() : $order->converted_at,
        ]);

        return $purchase;
    }

    private function normalizeItem(array $item, $products): array
    {
        $product = $products->get($item['product_id']);
        $lotPolicy = app(\App\Modules\Inventory\Services\LotTrackingPolicy::class);
        if ($lotPolicy->required($product, $lotPolicy->persistedConfiguration())) {
            $item['lot_number'] = filled($item['new_lot_code'] ?? null)
                ? app(\App\Modules\Inventory\Services\ProductLotService::class)->normalizeCode((string) $item['new_lot_code'])
                : \App\Modules\Inventory\Models\ProductCoil::query()->find($item['lot_id'] ?? null)?->lot_code;
            $quantity = app(InventoryQuantityService::class);
            $item['kilograms'] = filled($item['kilograms'] ?? null)
                ? $quantity->multiplyExact((string) $item['kilograms'], ($item['weight_unit'] ?? 'kg') === 'ton' ? '1000' : '1') : null;
            try {
                $item['meters'] = filled($item['meters'] ?? null)
                    ? $quantity->exact((string) $item['meters'])
                    : $quantity->metersFromWeightExact((string) $item['kilograms'],
                        $product->thickness?->kg_per_meter, $product->thickness?->kg_to_meter_factor);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['meters' => $exception->getMessage()]);
            }
            $item['conversion_factor'] = $product->thickness?->kg_to_meter_factor;
            $item['description'] = ($item['description'] ?? null) ?: $product->name;
            $item['line_total'] = $quantity->multiplyExact($item['meters'], (string) $item['unit_cost'], 2);

            return $item;
        }
        $kilograms = $this->normalizeKilograms($item);
        $meters = filled($item['meters'] ?? null) ? (float) $item['meters'] : null;
        $kgPerMeter = $product->thickness?->kg_per_meter;
        $conversionFactor = $kgPerMeter ? round(1 / (float) $kgPerMeter, 6) : $product->thickness?->kg_to_meter_factor;

        if (! $meters && $kilograms && $kgPerMeter) {
            $meters = round($kilograms / (float) $kgPerMeter, 3);
        } elseif (! $meters && $kilograms && $conversionFactor) {
            $meters = round($kilograms * (float) $conversionFactor, 3);
        }

        if (! $meters && filled($item['display_quantity'] ?? null) && (float) $item['display_quantity'] > 0) {
            $meters = (float) $item['display_quantity'];
        }

        $item['meters'] = $meters;
        $item['kilograms'] = $kilograms;
        $item['conversion_factor'] = $conversionFactor;
        $item['description'] = ($item['description'] ?? null) ?: $product->name;
        $item['line_total'] = round($meters * (float) $item['unit_cost'], 2);

        return $item;
    }

    private function normalizeKilograms(array $item): ?float
    {
        if (blank($item['kilograms'] ?? null)) {
            return null;
        }

        $kilograms = (float) $item['kilograms'];

        return ($item['weight_unit'] ?? 'kg') === 'ton'
            ? round($kilograms * 1000, 3)
            : $kilograms;
    }

    private function movement($source, int $productId, ?int $coilId, int $userId, float $delta, float $before, float $after, string $type): void
    {
        $branchId = $source instanceof Purchase
            ? $source->branch_id
            : $source->purchase()->value('branch_id');

        InventoryMovement::query()->create([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => 'Ingreso por orden de compra',
            'created_at' => now(),
        ]);
    }
}
