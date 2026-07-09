<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->select([
                'id',
                'thickness_id',
                'product_category_id',
                'product_unit_id',
                'name',
                'category',
                'sku',
                'barcode',
                'inventory_tracking_mode',
                'base_unit',
                'purchase_price',
                'sale_price',
                'is_active',
                'created_at',
            ])
            ->with(['thickness:id,name', 'productCategory:id,name', 'unit:id,name,symbol'])
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('tracking'), fn ($query) => $query->where('inventory_tracking_mode', $request->string('tracking')->toString()))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'filters' => $request->only('search', 'tracking', 'per_page'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'product' => null,
            'thicknesses' => $this->activeThicknesses(),
            'categories' => $this->activeCategories(),
            'units' => $this->activeUnits(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $product = Product::query()->create($request->validated());

            Branch::query()
                ->where('is_active', true)
                ->select('id')
                ->chunkById(100, function ($branches) use ($product) {
                    foreach ($branches as $branch) {
            $product->branchStocks()->firstOrCreate([
                            'branch_id' => $branch->id,
                        ], [
                            'available_meters' => 0,
                            'reserved_meters' => 0,
                        ]);
                    }
                });
        });

        UiCatalogCache::forgetProductCatalogs();

        return redirect()
            ->route('inventory.products.index')
            ->with('success', 'Producto creado correctamente.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'product' => $product->load(['thickness', 'productCategory', 'unit']),
            'thicknesses' => $this->activeThicknesses(),
            'categories' => $this->activeCategories(),
            'units' => $this->activeUnits(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());
        UiCatalogCache::forgetProductCatalogs();

        return redirect()
            ->route('inventory.products.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        abort_unless(request()->user()?->can('inventory.products.manage'), 403);

        $product->delete();
        UiCatalogCache::forgetProductCatalogs();

        return redirect()
            ->route('inventory.products.index')
            ->with('success', 'Producto desactivado correctamente.');
    }

    private function activeThicknesses()
    {
        return UiCatalogCache::activeThicknesses();
    }

    private function activeCategories()
    {
        return UiCatalogCache::productCategories();
    }

    private function activeUnits()
    {
        return UiCatalogCache::productUnits();
    }
}
