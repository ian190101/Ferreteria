<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockOverviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $globalStocks = ProductBranchStock::query()
            ->with(['branch:id,name', 'product:id,name,sku,barcode,base_unit,inventory_tracking_mode,product_unit_id', 'product.unit:id,name,symbol'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'product_branch_stocks.branch_id'))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->whereHas('product', fn ($productQuery) => $productQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%"));
            })
            ->orderByDesc('available_meters')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $coilStocks = ProductCoil::query()
            ->with(['branch:id,name', 'product:id,name,sku,barcode,base_unit,inventory_tracking_mode,product_unit_id', 'product.unit:id,name,symbol'])
            ->select([
                DB::raw('MIN(id) as id'),
                'branch_id',
                'product_id',
                DB::raw('SUM(available_meters) as available_meters'),
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'product_coils.branch_id'))
            ->where('status', 'available')
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->whereHas('product', fn ($productQuery) => $productQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%"));
            })
            ->groupBy('branch_id', 'product_id')
            ->orderByDesc(DB::raw('SUM(available_meters)'))
            ->limit(200)
            ->get()
            ->map(function (ProductCoil $stock) use ($request) {
                $reserved = (float) InventoryReservation::query()
                    ->where('branch_id', $stock->branch_id)
                    ->where('product_id', $stock->product_id)
                    ->whereNotNull('product_coil_id')
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'inventory_reservations.branch_id'))
                    ->sum('meters');

                return [
                    'id' => 'coil-'.$stock->branch_id.'-'.$stock->product_id,
                    'branch_id' => $stock->branch_id,
                    'product_id' => $stock->product_id,
                    'available_meters' => (float) $stock->available_meters,
                    'reserved_meters' => $reserved,
                    'source' => 'coils',
                    'branch' => $stock->branch,
                    'product' => $stock->product,
                ];
            });

        $coilSummary = ProductCoil::query()
            ->with(['branch:id,name', 'product:id,name,sku,barcode,base_unit,product_unit_id', 'product.unit:id,name,symbol'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'product_coils.branch_id'))
            ->where('status', 'available')
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->latest('id')
            ->limit(80)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters', 'status']);

        return Inertia::render('Inventory/Stock/Index', [
            'globalStocks' => $globalStocks,
            'coilStocks' => $coilStocks,
            'coilSummary' => $coilSummary,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'filters' => $request->only(['branch_id', 'search', 'per_page']),
        ]);
    }
}
