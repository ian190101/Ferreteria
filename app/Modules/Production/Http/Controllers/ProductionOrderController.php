<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Production\Http\Requests\StoreProductionOrderRequest;
use App\Modules\Production\Models\ProductionOrder;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductionOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = ProductionOrder::query()
            ->with(['branch:id,name', 'user:id,name', 'inputProduct:id,name,sku', 'inputCoil:id,barcode,lot_number', 'outputProduct:id,name,sku', 'outputCoil:id,barcode,lot_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('order_number', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('produced_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Production/Index', [
            'orders' => $orders,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => UiCatalogCache::activeProducts(),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'filters' => $request->only(['branch_id', 'search', 'per_page']),
        ]);
    }

    public function store(StoreProductionOrderRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $inputProduct = Product::query()->findOrFail($request->integer('input_product_id'));
            $outputProduct = Product::query()->findOrFail($request->integer('output_product_id'));
            $inputMeters = round((float) $request->input('input_meters'), 3);
            $outputMeters = round((float) $request->input('output_meters'), 3);
            $outputCoil = null;

            $order = ProductionOrder::query()->create([
                ...$request->safe()->except(['output_coil_barcode', 'output_lot_number']),
                'user_id' => $request->user()->id,
                'produced_at' => now(),
                'waste_meters' => round((float) $request->input('waste_meters', 0), 3),
                'status' => ProductionOrder::STATUS_COMPLETED,
            ]);

            $this->consumeInput($order, $inputProduct, $request->integer('input_product_coil_id') ?: null, $inputMeters, $request->user()->id);

            if ($outputProduct->inventory_tracking_mode === Product::TRACKING_COIL) {
                $outputCoil = ProductCoil::query()->create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $outputProduct->id,
                    'barcode' => $request->string('output_coil_barcode')->toString(),
                    'lot_number' => $request->string('output_lot_number')->toString(),
                    'initial_meters' => $outputMeters,
                    'available_meters' => $outputMeters,
                    'status' => 'available',
                ]);

                $order->update(['output_product_coil_id' => $outputCoil->id]);
                $this->movement($order, $outputProduct->id, $outputCoil->id, $request->user()->id, $outputMeters, 0, $outputMeters, 'production_output_coil');
            } else {
                $stock = ProductBranchStock::query()->firstOrCreate([
                    'branch_id' => $order->branch_id,
                    'product_id' => $outputProduct->id,
                ], [
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                ]);

                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $before = (float) $stock->available_meters;
                $after = round($before + $outputMeters, 3);
                $stock->update(['available_meters' => $after]);

                $this->movement($order, $outputProduct->id, null, $request->user()->id, $outputMeters, $before, $after, 'production_output_global');
            }
        });

        return redirect()->route('production.index')->with('success', 'Orden de produccion registrada correctamente.');
    }

    private function consumeInput(ProductionOrder $order, Product $product, ?int $coilId, float $meters, int $userId): void
    {
        if ($product->inventory_tracking_mode === Product::TRACKING_COIL) {
            $coil = ProductCoil::query()->lockForUpdate()->findOrFail($coilId);
            $before = (float) $coil->available_meters;
            $after = round($before - $meters, 3);

            $coil->update([
                'available_meters' => $after,
                'status' => $after <= 0 ? 'depleted' : 'available',
            ]);

            $this->movement($order, $product->id, $coil->id, $userId, -$meters, $before, $after, 'production_input_coil');

            return;
        }

        $stock = ProductBranchStock::query()
            ->where('branch_id', $order->branch_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();

        $before = (float) $stock->available_meters;
        $after = round($before - $meters, 3);
        $stock->update(['available_meters' => $after]);

        $this->movement($order, $product->id, null, $userId, -$meters, $before, $after, 'production_input_global');
    }

    private function movement(ProductionOrder $order, int $productId, ?int $coilId, int $userId, float $delta, float $before, float $after, string $type): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $order->branch_id,
            'product_id' => $productId,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $order::class,
            'source_id' => $order->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => 'Movimiento por produccion',
            'created_at' => now(),
        ]);
    }
}
