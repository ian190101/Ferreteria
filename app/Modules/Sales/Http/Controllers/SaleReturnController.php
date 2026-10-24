<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Sales\Http\Requests\StoreSaleReturnRequest;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $returns = SaleReturn::query()
            ->with([
                'sale:id,receipt_number,customer_name,status,total',
                'branch:id,name',
                'user:id,name',
                'items.product:id,name,sku',
                'items.coil:id,barcode,lot_number',
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('returned_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('returned_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($nested) use ($search) {
                    $nested->where('return_number', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                            ->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->latest('returned_at')
            ->paginate(max(1, min(100, $request->integer('per_page', 15))))
            ->withQueryString();

        $sales = Sale::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'sale_note')
            ->where('status', '!=', 'void')
            ->latest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'receipt_number', 'customer_name', 'status']);

        return Inertia::render('Sales/Returns/Index', [
            'returns' => $returns,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'sales' => $sales,
            'saleItems' => $this->returnableSaleItems($request),
            'filters' => $request->only(['branch_id', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StoreSaleReturnRequest $request, InventoryQuantityService $quantities): RedirectResponse
    {
        DB::transaction(function () use ($request, $quantities) {
            $lotPolicy = app(\App\Modules\Inventory\Services\LotTrackingPolicy::class);
            $lotPolicy->lockRule();
            $lotConfiguration = $lotPolicy->persistedConfiguration();
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));

            $saleReturn = SaleReturn::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'return_number' => $request->validated('return_number'),
                'returned_at' => now(),
                'total_amount' => 0,
                'reason' => $request->validated('reason'),
                'notes' => $request->validated('notes'),
            ]);

            $totalAmount = '0.00';
            $validatedItems = collect($request->validated('items'));
            $metersByItem = $validatedItems
                ->groupBy('sale_item_id')
                ->map(fn ($rows) => $rows->reduce(fn (string $sum, array $row) =>
                    bcadd($sum, (string) $row['meters'], 3), '0.000'));
            $saleItems = SaleItem::query()
                ->with(['product:id,name,inventory_tracking_mode,is_inventory_item,requires_lot', 'coil'])
                ->where('sale_id', $sale->id)
                ->whereIn('id', $metersByItem->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $returnedByItem = SaleReturnItem::query()
                ->whereIn('sale_item_id', $metersByItem->keys())
                ->selectRaw('sale_item_id, SUM(meters) as returned_meters')
                ->groupBy('sale_item_id')
                ->pluck('returned_meters', 'sale_item_id');

            $plan = $saleItems->filter(fn (SaleItem $item) => $item->coil?->lot_code !== null)
                ->map(fn (SaleItem $item) => [
                    'product_id' => (int) $item->product_id,
                    'branch_id' => (int) $sale->branch_id,
                    'lot_id' => (int) $item->product_coil_id,
                ])->all();
            if ($plan !== []) {
                app(\App\Modules\Inventory\Services\LotInventoryService::class)->lockBatch($plan);
            }

            foreach ($validatedItems as $itemPayload) {
                $saleItem = $saleItems->get($itemPayload['sale_item_id']);
                if ($saleItem->coil?->lot_code !== null) {
                    $metersExact = $quantities->exact((string) $itemPayload['meters']);
                    $remainingExact = $quantities->subtractExact((string) $saleItem->meters,
                        (string) ($returnedByItem[$saleItem->id] ?? '0.000'));
                    if ($quantities->compareExact($metersExact, $remainingExact) > 0) {
                        throw ValidationException::withMessages(['items' => 'La devolución supera la cantidad pendiente del lote.']);
                    }
                    $discountExact = \App\Modules\Finance\Support\Decimal::round(
                        bcdiv(bcmul((string) $saleItem->discount_amount, $metersExact, 8),
                            (string) $saleItem->meters, 8), 2);
                    $lineTotal = \App\Modules\Finance\Support\Decimal::round(bcsub(
                        bcmul((string) $saleItem->unit_price, $metersExact, 8), $discountExact, 8), 2);
                    $returnItem = $saleReturn->items()->create([
                        'sale_item_id' => $saleItem->id,
                        'product_id' => $saleItem->product_id,
                        'product_coil_id' => $saleItem->product_coil_id,
                        'lot_code_snapshot' => $saleItem->lot_code_snapshot,
                        'meters' => $metersExact,
                        'unit_price' => $saleItem->unit_price,
                        'discount_amount' => $discountExact,
                        'total' => $lineTotal,
                    ]);
                    $this->restoreManagedInventory($sale, $saleItem, $returnItem, $metersExact,
                        (int) $request->user()->id, $quantities);
                    $returnedByItem[$saleItem->id] = $quantities->addExact(
                        (string) ($returnedByItem[$saleItem->id] ?? '0.000'), $metersExact);
                    $totalAmount = bcadd($totalAmount, $lineTotal, 2);
                    continue;
                }
                $meters = round((float) $itemPayload['meters'], 3);
                $remainingMeters = max(round((float) $saleItem->meters - (float) ($returnedByItem[$saleItem->id] ?? 0), 3), 0);

                if ($meters > $remainingMeters) {
                    throw ValidationException::withMessages([
                        'items' => 'La devolucion supera el metraje disponible del item vendido.',
                    ]);
                }

                $discountAmount = $this->proportionalDiscount($saleItem, $meters);
                $total = max(round(((float) $saleItem->unit_price * $meters) - $discountAmount, 2), 0);

                $saleReturnItem = $saleReturn->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'product_coil_id' => $saleItem->product_coil_id,
                    'meters' => $meters,
                    'unit_price' => $saleItem->unit_price,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                ]);

                $this->restoreInventory($sale, $saleReturn, $saleItem, $saleReturnItem, $request->user()->id, $quantities);
                $returnedByItem[$saleItem->id] = round(
                    (float) ($returnedByItem[$saleItem->id] ?? 0) + $meters,
                    3
                );
                $totalAmount = bcadd($totalAmount, (string) $total, 2);
            }

            $saleReturn->update(['total_amount' => $totalAmount]);
            $sale->update([
                'internal_notes' => trim(implode("\n", array_filter([
                    $sale->internal_notes,
                    "Devolucion {$saleReturn->return_number}: {$saleReturn->reason}",
                ]))),
            ]);
        });

        return redirect()->route('sales.returns.index')->with('success', 'Devolucion registrada correctamente.');
    }

    private function returnableSaleItems(Request $request)
    {
        $items = SaleItem::query()
            ->with([
                'sale:id,branch_id,receipt_number,customer_name,status,document_type',
                'product:id,name,sku,inventory_tracking_mode',
                'coil:id,barcode,lot_number',
                'lot:id,lot_code',
            ])
            ->withSum('returnItems as returned_meters_sum', 'meters')
            ->whereHas('sale', fn ($query) => $query
                ->when(true, fn ($saleQuery) => BranchAccess::apply($saleQuery, $request->user()))
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void'))
            ->latest('id')
            ->limit(300)
            ->get();
        $returnable = app(\App\Modules\Sales\Services\SaleInventoryService::class)->returnableMetersByItem(
            $items->filter(fn (SaleItem $item) => $item->lot?->lot_code !== null)->pluck('id')->all());

        return $items->map(function (SaleItem $item) use ($returnable) {
                if ($item->lot?->lot_code !== null) {
                    $quantity = app(InventoryQuantityService::class);
                    $returnedMeters = $quantity->exact((string) ($item->returned_meters_sum ?? '0'));
                    $remainingMeters = $quantity->exact((string) ($returnable[$item->id] ?? '0.000'));
                } else {
                    $returnedMeters = (float) $item->returned_meters_sum;
                    $remainingMeters = max(round((float) $item->meters - $returnedMeters, 3), 0);
                }

                return [
                    'id' => $item->id,
                    'sale_id' => $item->sale_id,
                    'branch_id' => $item->sale?->branch_id,
                    'product_id' => $item->product_id,
                    'product_coil_id' => $item->product_coil_id,
                    'description' => $item->description,
                    'meters' => $item->meters,
                    'returned_meters' => $returnedMeters,
                    'remaining_meters' => $remainingMeters,
                    'unit_price' => $item->unit_price,
                    'sale' => $item->sale,
                    'product' => $item->product,
                    'coil' => $item->coil,
                    'lot' => $item->lot,
                    'lot_code_snapshot' => $item->lot_code_snapshot,
                ];
            })
            ->filter(fn (array $item) => is_string($item['remaining_meters'])
                ? bccomp($item['remaining_meters'], '0', 3) > 0
                : $item['remaining_meters'] > 0)
            ->values();
    }

    private function proportionalDiscount(SaleItem $saleItem, float $meters): float
    {
        if ((float) $saleItem->meters <= 0) {
            return 0;
        }

        return round(((float) $saleItem->discount_amount / (float) $saleItem->meters) * $meters, 2);
    }

    private function restoreManagedInventory(Sale $sale, SaleItem $saleItem, SaleReturnItem $returnItem,
        string $amount, int $userId, InventoryQuantityService $quantities): void
    {
        $deliveryIds = $saleItem->deliveryItems()->pluck('id');
        $movements = InventoryMovement::query()->where('product_coil_id', $saleItem->product_coil_id)
            ->where(function ($query) use ($saleItem, $deliveryIds): void {
                $query->where(fn ($q) => $q->where('source_type', SaleItem::class)
                    ->where('source_id', $saleItem->id)->where('type', 'sale_stock_out'))
                    ->orWhere(fn ($q) => $q->where('source_type', \App\Modules\Sales\Models\DeliveryNoteItem::class)
                        ->whereIn('source_id', $deliveryIds)->where('type', 'delivery_stock_out'));
            })->orderBy('id')->lockForUpdate()->get();
        $remaining = $amount;
        foreach ($movements as $movement) {
            $restored = InventoryMovement::query()->where('reverses_movement_id', $movement->id)
                ->where('meters_delta', '>', 0)->orderBy('id')->lockForUpdate()->get(['meters_delta'])
                ->reduce(fn (string $sum, InventoryMovement $entry) => $quantities->addExact($sum, (string) $entry->meters_delta), '0.000');
            $free = $quantities->subtractExact(ltrim((string) $movement->meters_delta, '-'), $restored);
            if ($quantities->compareExact($free, '0') <= 0) {
                continue;
            }
            $take = $quantities->compareExact($remaining, $free) < 0 ? $remaining : $free;
            app(\App\Modules\Inventory\Services\LotInventoryService::class)->apply(
                (int) $saleItem->product_id, (int) $sale->branch_id, (int) $saleItem->product_coil_id,
                $take, 'sale_return', $returnItem,
                'return:'.$returnItem->id.':'.$movement->id, $userId, true, (int) $movement->id);
            $remaining = $quantities->subtractExact($remaining, $take);
            if ($quantities->compareExact($remaining, '0') === 0) {
                break;
            }
        }
        if ($quantities->compareExact($remaining, '0') > 0) {
            throw ValidationException::withMessages(['items' => 'La devolución supera la salida física aún no restituida. No se puede devolver mercancía pendiente de salida.']);
        }
    }

    private function restoreInventory(Sale $sale, SaleReturn $saleReturn, SaleItem $saleItem, SaleReturnItem $returnItem, int $userId, InventoryQuantityService $quantities): void
    {
        if ($saleItem->product_coil_id) {
            $coil = ProductCoil::query()->lockForUpdate()->findOrFail($saleItem->product_coil_id);
            $before = (float) $coil->available_meters;
            $after = round($before + (float) $returnItem->meters, 3);

            $coil->update([
                'available_meters' => $after,
                'status' => 'available',
            ]);

            $this->movement($sale, $saleReturn, $returnItem, $userId, $before, $after, $coil->id);

            return;
        }

        $stock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $sale->branch_id,
            'product_id' => $saleItem->product_id,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
        ]);
        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        [$before, $after] = $quantities->increase($stock, (float) $returnItem->meters);
        $this->movement($sale, $saleReturn, $returnItem, $userId, $before, $after, null);
    }

    private function movement(Sale $sale, SaleReturn $saleReturn, SaleReturnItem $returnItem, int $userId, float $before, float $after, ?int $coilId): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $sale->branch_id,
            'product_id' => $returnItem->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => SaleReturnItem::class,
            'source_id' => $returnItem->id,
            'type' => 'sale_return',
            'meters_delta' => (float) $returnItem->meters,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => "Devolucion {$saleReturn->return_number}",
            'created_at' => $saleReturn->returned_at,
        ]);
    }
}
