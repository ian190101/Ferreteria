<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreProductCoilRequest;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductCoilController extends Controller
{
    public function index(Request $request): Response
    {
        $coils = ProductCoil::query()
            ->with(['branch:id,name', 'product:id,name,sku,inventory_tracking_mode'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('barcode', 'like', "%{$search}%")
                        ->orWhere('lot_number', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Coils/Index', [
            'coils' => $coils,
            'filters' => $request->only('search', 'status', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Coils/Form', [
            'branches' => UiCatalogCache::activeBranchesForUser(request()->user()),
            'products' => UiCatalogCache::activeCoilProducts(),
        ]);
    }

    public function store(StoreProductCoilRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['available_meters'] = $data['initial_meters'];
            $data['status'] = 'available';

            $coil = ProductCoil::query()->create($data);

            InventoryMovement::query()->create([
                'branch_id' => $coil->branch_id,
                'product_id' => $coil->product_id,
                'product_coil_id' => $coil->id,
                'user_id' => $request->user()->id,
                'source_type' => ProductCoil::class,
                'source_id' => $coil->id,
                'type' => 'coil_entry',
                'meters_delta' => $coil->initial_meters,
                'meters_before' => 0,
                'meters_after' => $coil->available_meters,
                'reason' => 'Ingreso inicial de bobina',
                'created_at' => now(),
            ]);
        });

        return redirect()
            ->route('inventory.coils.index')
            ->with('success', 'Bobina registrada correctamente.');
    }
}
