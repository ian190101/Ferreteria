<?php

namespace App\Modules\Inventory\Http\Controllers\Movements;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $movements = InventoryMovement::query()
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
            'products' => UiCatalogCache::activeProducts(['id', 'name', 'sku']),
            'coils' => ProductCoil::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('status', 'available')
                ->orderByDesc('id')
                ->limit(500)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters']),
            'types' => InventoryMovement::query()->select('type')->distinct()->orderBy('type')->pluck('type'),
            'filters' => $request->only(['branch_id', 'product_id', 'product_coil_id', 'type', 'from', 'to', 'per_page']),
        ]);
    }
}
