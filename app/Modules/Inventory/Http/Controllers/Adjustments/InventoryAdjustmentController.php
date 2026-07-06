<?php

namespace App\Modules\Inventory\Http\Controllers\Adjustments;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\Adjustments\StoreInventoryAdjustmentRequest;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        $adjustments = InventoryAdjustment::query()
            ->with(['branch:id,name', 'product:id,name,sku,inventory_tracking_mode', 'coil:id,barcode,lot_number', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('search'), fn ($query) => $query->where('adjustment_number', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('adjusted_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Adjustments/Index', [
            'adjustments' => $adjustments,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => UiCatalogCache::activeProducts(),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'filters' => $request->only(['branch_id', 'type', 'search', 'per_page']),
        ]);
    }

    public function store(StoreInventoryAdjustmentRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $product = Product::query()->findOrFail($request->integer('product_id'));
            $meters = round((float) $request->input('meters'), 3);
            $delta = $request->input('type') === InventoryAdjustment::TYPE_DECREASE ? -$meters : $meters;

            [$before, $after, $coilId] = $product->inventory_tracking_mode === Product::TRACKING_COIL
                ? $this->adjustCoil($request->integer('product_coil_id'), $delta)
                : $this->adjustGlobal($request->integer('branch_id'), $product->id, $delta);

            $adjustment = InventoryAdjustment::query()->create([
                'branch_id' => $request->integer('branch_id'),
                'product_id' => $product->id,
                'product_coil_id' => $coilId,
                'user_id' => $request->user()->id,
                'adjustment_number' => $request->string('adjustment_number')->toString(),
                'type' => $request->string('type')->toString(),
                'meters_delta' => $delta,
                'meters_before' => $before,
                'meters_after' => $after,
                'reason' => $request->string('reason')->toString(),
                'adjusted_at' => now(),
                'notes' => $request->string('notes')->toString() ?: null,
            ]);

            InventoryMovement::query()->create([
                'branch_id' => $adjustment->branch_id,
                'product_id' => $adjustment->product_id,
                'product_coil_id' => $adjustment->product_coil_id,
                'user_id' => $request->user()->id,
                'source_type' => InventoryAdjustment::class,
                'source_id' => $adjustment->id,
                'type' => 'inventory_adjustment',
                'meters_delta' => $delta,
                'meters_before' => $before,
                'meters_after' => $after,
                'reason' => $adjustment->reason,
                'created_at' => $adjustment->adjusted_at,
            ]);
        });

        return redirect()->route('inventory.adjustments.index')->with('success', 'Ajuste de inventario registrado correctamente.');
    }

    private function adjustCoil(int $coilId, float $delta): array
    {
        $coil = ProductCoil::query()->lockForUpdate()->findOrFail($coilId);
        $before = (float) $coil->available_meters;
        $after = round($before + $delta, 3);

        $coil->update([
            'available_meters' => $after,
            'status' => $after <= 0 ? 'depleted' : 'available',
        ]);

        return [$before, $after, $coil->id];
    }

    private function adjustGlobal(int $branchId, int $productId, float $delta): array
    {
        $stock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $branchId,
            'product_id' => $productId,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
        ]);

        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = (float) $stock->available_meters;
        $after = round($before + $delta, 3);
        $stock->update(['available_meters' => $after]);

        return [$before, $after, null];
    }
}
