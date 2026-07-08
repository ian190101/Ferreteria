<?php

namespace App\Modules\Purchases\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
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

    public function create(): Response
    {
        return Inertia::render('Purchases/Orders/Form', [
            'branches' => UiCatalogCache::activeBranchesForUser(request()->user()),
            'suppliers' => UiCatalogCache::activeSuppliers(),
            'products' => UiCatalogCache::activeProductsWithThickness(),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $order = DB::transaction(function () use ($request) {
            $payloadItems = collect($request->validated('items'));
            $products = Product::query()
                ->with('thickness')
                ->whereIn('id', $payloadItems->pluck('product_id')->unique()->values())
                ->get(['id', 'thickness_id', 'name'])
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
                'total_amount' => $items->sum('line_total'),
                'status' => $status,
                'notes' => $request->string('notes')->toString() ?: null,
                'approved_at' => $status === PurchaseOrder::STATUS_APPROVED ? now() : null,
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
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
            'order' => $order->load(['branch:id,name', 'supplier:id,name', 'items.product:id,name,sku,inventory_tracking_mode']),
        ]);
    }

    public function storeReceipt(StorePurchaseOrderReceiptRequest $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes aprobadas o parcialmente recibidas pueden recibir mercaderia.']);
        }

        $purchase = DB::transaction(fn () => $this->createReceipt($order, $request->validated(), $request->user()));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Recepcion registrada y compra creada correctamente.');
    }

    public function convert(Request $request, PurchaseOrder $order): RedirectResponse
    {
        abort_unless($request->user()?->can('purchases.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $order->branch_id), 403);

        if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIAL_RECEIVED], true)) {
            throw ValidationException::withMessages(['order' => 'Solo las ordenes aprobadas o parcialmente recibidas pueden convertirse en compra.']);
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

        $purchase = DB::transaction(fn () => $this->createReceipt($order, $payload, $request->user()));

        return redirect()->route('purchases.show', $purchase)->with('success', 'Orden convertida en compra recibida correctamente.');
    }

    private function createReceipt(PurchaseOrder $order, array $payload, User $user): Purchase
    {
        $order = PurchaseOrder::query()->with('items.product')->lockForUpdate()->findOrFail($order->id);
        $payloadItems = collect($payload['items'] ?? [])
            ->filter(fn (array $item) => (float) ($item['meters'] ?? 0) > 0)
            ->values();

        $orderItems = PurchaseOrderItem::query()
            ->whereIn('id', $payloadItems->pluck('purchase_order_item_id'))
            ->with('product')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $normalizedItems = $payloadItems->map(function (array $payloadItem) use ($order, $orderItems) {
            $orderItem = $orderItems->get($payloadItem['purchase_order_item_id']);

            if (! $orderItem || $orderItem->purchase_order_id !== $order->id) {
                throw ValidationException::withMessages(['items' => 'El item recibido no pertenece a esta orden de compra.']);
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
            ->map(fn ($rows) => round((float) $rows->sum('meters'), 3));

        $totalAmount = $normalizedItems->sum('line_total');
        $receiptNumber = 'REC-'.$order->order_number.'-'.now()->format('YmdHis');

        $purchase = Purchase::query()->create([
            'branch_id' => $order->branch_id,
            'supplier_id' => $order->supplier_id,
            'user_id' => $user->id,
            'document_number' => $receiptNumber,
            'purchase_date' => $payload['received_at'],
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
            'received_at' => $payload['received_at'],
            'total_amount' => $totalAmount,
            'notes' => $payload['notes'] ?? null,
        ]);

        foreach ($normalizedItems as $item) {
            $orderItem = $item['order_item'];
            $coil = null;

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
                $before = (float) $stock->available_meters;
                $after = round($before + (float) $item['meters'], 3);
                $stock->update(['available_meters' => $after]);
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

        $allReceived = $order->items->every(fn (PurchaseOrderItem $item) => ((float) $item->received_meters + (float) ($receivedMetersByItem[$item->id] ?? 0)) >= (float) $item->meters);
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
        $kilograms = $this->normalizeKilograms($item);
        $meters = filled($item['meters'] ?? null) ? (float) $item['meters'] : null;
        $kgPerMeter = $product->thickness?->kg_per_meter;
        $conversionFactor = $kgPerMeter ? round(1 / (float) $kgPerMeter, 6) : $product->thickness?->kg_to_meter_factor;

        if (! $meters && $kilograms && $kgPerMeter) {
            $meters = round($kilograms / (float) $kgPerMeter, 3);
        } elseif (! $meters && $kilograms && $conversionFactor) {
            $meters = round($kilograms * (float) $conversionFactor, 3);
        }

        $item['meters'] = $meters;
        $item['kilograms'] = $kilograms;
        $item['conversion_factor'] = $conversionFactor;
        $item['description'] = $item['description'] ?: $product->name;
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
