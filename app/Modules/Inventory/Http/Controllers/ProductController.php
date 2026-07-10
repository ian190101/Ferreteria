<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Http\Requests\StoreProductRequest;
use App\Modules\Inventory\Http\Requests\UpdateProductRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $branches = $this->activeBranches($request);
        $allowedBranchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->values();
        $branchId = $request->integer('branch_id');
        $filterBranchId = $branchId && $allowedBranchIds->contains($branchId) ? $branchId : null;

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
            ->whereHas('branchStocks', function ($query) use ($allowedBranchIds, $filterBranchId) {
                $query->where('is_enabled', true)
                    ->whereIn('branch_id', $filterBranchId ? [$filterBranchId] : $allowedBranchIds);
            })
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
            'branches' => $branches,
            'filters' => [
                ...$request->only('search', 'tracking', 'per_page'),
                'branch_id' => $filterBranchId,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'product' => null,
            'thicknesses' => $this->activeThicknesses(),
            'categories' => $this->activeCategories(),
            'units' => $this->activeUnits(),
            'branches' => $this->activeBranches(request()),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $validated = $request->validated();
            $product = Product::query()->create(Arr::except($validated, ['branch_scope', 'branch_ids', 'unit_conversions']));

            $this->syncProductBranches($product, $request, $validated);
            $this->syncUnitConversions($product, $validated['unit_conversions'] ?? []);
        });

        UiCatalogCache::forgetProductCatalogs();

        return redirect()
            ->route('inventory.products.index')
            ->with('success', 'Producto creado correctamente.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'product' => $product->load(['thickness', 'productCategory', 'unit', 'unitConversions.unit:id,name,symbol,kind', 'branchStocks:id,product_id,branch_id,is_enabled']),
            'thicknesses' => $this->activeThicknesses(),
            'categories' => $this->activeCategories(),
            'units' => $this->activeUnits(),
            'branches' => $this->activeBranches(request()),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product) {
            $validated = $request->validated();
            $product->update(Arr::except($validated, ['branch_scope', 'branch_ids', 'unit_conversions']));
            $this->syncProductBranches($product, $request, $validated);
            $this->syncUnitConversions($product, $validated['unit_conversions'] ?? []);
        });

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

    private function activeBranches(Request $request)
    {
        return UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
    }

    private function syncProductBranches(Product $product, Request $request, array $validated): void
    {
        $availableBranchIds = $this->activeBranches($request)->pluck('id')->map(fn ($id) => (int) $id)->values();
        $enabledBranchIds = ($validated['branch_scope'] ?? 'global') === 'global'
            ? $availableBranchIds
            : collect($validated['branch_ids'] ?? [])->map(fn ($id) => (int) $id)->intersect($availableBranchIds)->values();

        Branch::query()
            ->whereIn('id', $availableBranchIds)
            ->select('id')
            ->chunkById(100, function ($branches) use ($product, $enabledBranchIds) {
                foreach ($branches as $branch) {
                    $stock = ProductBranchStock::query()->firstOrCreate([
                        'branch_id' => $branch->id,
                        'product_id' => $product->id,
                    ], [
                        'available_meters' => 0,
                        'reserved_meters' => 0,
                    ]);

                    $stock->update(['is_enabled' => $enabledBranchIds->contains((int) $branch->id)]);
                }
            });
    }

    private function syncUnitConversions(Product $product, array $conversions): void
    {
        $activeUnitIds = collect($conversions)->pluck('product_unit_id')->map(fn ($id) => (int) $id)->all();

        $product->unitConversions()
            ->whereNotIn('product_unit_id', $activeUnitIds ?: [-1])
            ->update(['is_active' => false]);

        foreach ($conversions as $conversion) {
            $product->unitConversions()->updateOrCreate([
                'product_unit_id' => $conversion['product_unit_id'],
            ], [
                'factor_to_base' => $conversion['factor_to_base'],
                'is_active' => $conversion['is_active'] ?? true,
            ]);
        }
    }
}
