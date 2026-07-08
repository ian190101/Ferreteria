<?php

namespace App\Modules\Inventory\Http\Controllers\Movements;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class InventoryMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $movements = InventoryMovement::query()
            ->select([
                'id',
                'branch_id',
                'product_id',
                'product_coil_id',
                'user_id',
                'type',
                'meters_delta',
                'meters_before',
                'meters_after',
                'reason',
                'created_at',
            ])
            ->with(['branch:id,name', 'product:id,name,sku', 'coil:id,barcode,lot_number', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('product_coil_id'), fn ($query) => $query->where('product_coil_id', $request->integer('product_coil_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('to')))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'products' => Inertia::defer(fn () => UiCatalogCache::activeProducts(['id', 'name', 'sku']), 'kardex-catalogs'),
            'coils' => Inertia::defer(fn () => Cache::remember('kardex-coils:v2:'.SystemCacheInvalidator::operationalVersion().":{$request->user()->id}", now()->addSeconds(60), fn () => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters'])), 'kardex-catalogs'),
            'types' => Inertia::defer(fn () => Cache::remember('kardex-types:v2:'.SystemCacheInvalidator::operationalVersion(), now()->addMinutes(10), fn () => InventoryMovement::query()->select('type')->distinct()->orderBy('type')->pluck('type')), 'kardex-catalogs'),
            'filters' => $request->only(['branch_id', 'product_id', 'product_coil_id', 'type', 'from', 'to', 'per_page']),
        ]);
    }
}
