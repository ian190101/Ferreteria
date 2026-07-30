<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Services\RecipeInventoryService;
use App\Modules\Restaurant\Services\RestaurantWorkflowPolicy;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantController extends Controller
{
    public function index(Request $request, RestaurantWorkflowPolicy $policy): Response
    {
        $branches = UiCatalogCache::activeBranchesForUser($request->user(), ['id', 'name']);
        $branchId = (int) ($request->integer('branch_id') ?: ($request->user()->branch_id ?? $branches->first()?->id));

        if (! BranchAccess::canAccess($request->user(), $branchId)) {
            $branchId = (int) ($branches->first()?->id ?? 0);
        }

        return Inertia::render('Restaurant/Index', [
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'policy' => $policy->summary(),
            'tables' => RestaurantTable::query()
                ->where('branch_id', $branchId)
                ->orderBy('area_name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'area_name', 'name', 'code', 'capacity', 'status', 'sort_order', 'is_active']),
            'orders' => KitchenOrder::query()
                ->with(['table:id,name,area_name', 'waiter:id,name', 'items.product:id,name,sku'])
                ->where('branch_id', $branchId)
                ->latest('sent_at')
                ->limit(30)
                ->get(),
            'recipes' => Recipe::query()
                ->with(['product:id,name,sku,base_unit,sale_price', 'items.ingredient:id,name,sku,base_unit'])
                ->where(fn ($query) => $query
                    ->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId))
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->where('is_active', true)
                ->where('is_sellable', true)
                ->whereIn('item_type', ['prepared_product', 'physical', 'combo', 'kit'])
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'sku', 'barcode', 'base_unit', 'item_type', 'sale_price']),
            'ingredients' => Product::query()
                ->where('is_active', true)
                ->where('is_inventory_item', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'sku', 'base_unit']),
            'filters' => $request->only(['branch_id']),
        ]);
    }

    public function storeTable(Request $request, RestaurantWorkflowPolicy $policy): RedirectResponse
    {
        abort_unless($policy->tablesEnabled(), 404);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'area_name' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'min:1', 'max:999'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], [], [
            'branch_id' => 'sucursal',
            'area_name' => 'salon o ambiente',
            'name' => 'mesa',
            'code' => 'codigo',
            'capacity' => 'capacidad',
        ]);

        $this->ensureBranchAccess($request, (int) $data['branch_id']);

        RestaurantTable::query()->create([
            ...$data,
            'status' => RestaurantTable::STATUS_AVAILABLE,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Mesa registrada correctamente.');
    }

    public function updateTable(Request $request, RestaurantTable $table, RestaurantWorkflowPolicy $policy): RedirectResponse
    {
        abort_unless($policy->tablesEnabled(), 404);
        $this->ensureBranchAccess($request, (int) $table->branch_id);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                RestaurantTable::STATUS_AVAILABLE,
                RestaurantTable::STATUS_OCCUPIED,
                RestaurantTable::STATUS_RESERVED,
                RestaurantTable::STATUS_INACTIVE,
            ])],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'status' => 'estado',
            'is_active' => 'activo',
        ]);

        $table->update($data);
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Mesa actualizada correctamente.');
    }

    public function storeRecipe(Request $request, RestaurantWorkflowPolicy $policy): RedirectResponse
    {
        abort_unless($policy->recipesEnabled(), 404);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:160'],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_product_id' => ['required', 'integer', 'exists:products,id', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_label' => ['nullable', 'string', 'max:40'],
            'items.*.waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'product_id' => 'producto preparado',
            'yield_quantity' => 'rendimiento',
            'items' => 'insumos',
            'items.*.ingredient_product_id' => 'insumo',
            'items.*.quantity' => 'cantidad del insumo',
        ]);

        if (! empty($data['branch_id'])) {
            $this->ensureBranchAccess($request, (int) $data['branch_id']);
        }

        DB::transaction(function () use ($data) {
            $recipe = Recipe::query()->updateOrCreate([
                'branch_id' => $data['branch_id'] ?? null,
                'product_id' => $data['product_id'],
            ], [
                'name' => $data['name'],
                'yield_quantity' => $data['yield_quantity'],
                'instructions' => $data['instructions'] ?? null,
                'is_active' => true,
            ]);

            $recipe->items()->delete();
            $recipe->items()->createMany(collect($data['items'])->map(fn ($item) => [
                'ingredient_product_id' => $item['ingredient_product_id'],
                'quantity' => $item['quantity'],
                'unit_label' => $item['unit_label'] ?? null,
                'waste_percentage' => $item['waste_percentage'] ?? 0,
            ])->all());
        });
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Receta guardada correctamente.');
    }

    public function storeOrder(Request $request, RestaurantWorkflowPolicy $policy, RecipeInventoryService $inventory): RedirectResponse
    {
        abort_unless($policy->kitchenEnabled(), 404);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'customer_label' => ['nullable', 'string', 'max:160'],
            'channel' => ['required', Rule::in(['table', 'counter', 'delivery', 'takeaway'])],
            'preparation_area' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'branch_id' => 'sucursal',
            'restaurant_table_id' => 'mesa',
            'channel' => 'canal',
            'items' => 'items de comanda',
            'items.*.product_id' => 'producto',
            'items.*.quantity' => 'cantidad',
        ]);

        $this->ensureBranchAccess($request, (int) $data['branch_id']);

        if (! empty($data['restaurant_table_id'])) {
            $table = RestaurantTable::query()->whereKey($data['restaurant_table_id'])->firstOrFail();
            if ((int) $table->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages(['restaurant_table_id' => 'La mesa seleccionada no pertenece a la sucursal de la comanda.']);
            }
        }

        DB::transaction(function () use ($data, $request, $policy, $inventory) {
            $productIds = collect($data['items'])->pluck('product_id')->unique()->values()->all();
            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
            $recipes = Recipe::query()
                ->with('items.ingredient')
                ->whereIn('product_id', $productIds)
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereNull('branch_id')
                    ->orWhere('branch_id', $data['branch_id']))
                ->orderByRaw('branch_id is null')
                ->get()
                ->unique('product_id')
                ->keyBy('product_id');

            foreach ($data['items'] as $item) {
                $recipe = $recipes->get((int) $item['product_id']);
                if ($policy->shouldDiscountOnKitchenOrder() && $recipe) {
                    $inventory->validateAvailability($recipe, (int) $data['branch_id'], (float) $item['quantity']);
                }
            }

            $subtotal = collect($data['items'])->sum(fn ($item) => round((float) $item['quantity'] * (float) ($item['unit_price'] ?? $products->get((int) $item['product_id'])?->sale_price ?? 0), 2));

            $order = KitchenOrder::query()->create([
                'branch_id' => $data['branch_id'],
                'restaurant_table_id' => $data['restaurant_table_id'] ?? null,
                'waiter_user_id' => $request->user()->id,
                'order_number' => $this->nextOrderNumber(),
                'customer_label' => $data['customer_label'] ?? null,
                'channel' => $data['channel'],
                'preparation_area' => $data['preparation_area'] ?? null,
                'status' => KitchenOrder::STATUS_SENT,
                'subtotal' => $subtotal,
                'sent_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $product = $products->get((int) $item['product_id']);
                $unitPrice = (float) ($item['unit_price'] ?? $product?->sale_price ?? 0);

                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'recipe_id' => $recipes->get((int) $item['product_id'])?->id,
                    'quantity' => $item['quantity'],
                    'unit_label' => $product?->base_unit ?: 'u',
                    'unit_price' => $unitPrice,
                    'total' => round((float) $item['quantity'] * $unitPrice, 2),
                    'status' => KitchenOrder::STATUS_SENT,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            if ($policy->shouldDiscountOnKitchenOrder()) {
                $inventory->consumeForOrder($order, $request->user()->id);
            }

            if (! empty($data['restaurant_table_id'])) {
                RestaurantTable::query()->whereKey($data['restaurant_table_id'])->update(['status' => RestaurantTable::STATUS_OCCUPIED]);
            }
        });
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Comanda enviada correctamente.');
    }

    public function updateOrderStatus(Request $request, KitchenOrder $order, RestaurantWorkflowPolicy $policy): RedirectResponse
    {
        abort_unless($policy->kitchenEnabled(), 404);
        $this->ensureBranchAccess($request, (int) $order->branch_id);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                KitchenOrder::STATUS_PREPARING,
                KitchenOrder::STATUS_READY,
                KitchenOrder::STATUS_DELIVERED,
                KitchenOrder::STATUS_CLOSED,
                KitchenOrder::STATUS_CANCELLED,
            ])],
        ], [], ['status' => 'estado']);

        $timestamps = match ($data['status']) {
            KitchenOrder::STATUS_READY => ['prepared_at' => now()],
            KitchenOrder::STATUS_DELIVERED => ['delivered_at' => now()],
            KitchenOrder::STATUS_CLOSED, KitchenOrder::STATUS_CANCELLED => ['closed_at' => now()],
            default => [],
        };

        $order->update([...$data, ...$timestamps]);
        $order->items()->update(['status' => $data['status']]);

        if ($order->restaurant_table_id && in_array($data['status'], [KitchenOrder::STATUS_CLOSED, KitchenOrder::STATUS_CANCELLED], true)) {
            RestaurantTable::query()->whereKey($order->restaurant_table_id)->update(['status' => RestaurantTable::STATUS_AVAILABLE]);
        }
        SystemCacheInvalidator::bumpOperational();

        return back()->with('success', 'Estado de comanda actualizado.');
    }

    private function ensureBranchAccess(Request $request, int $branchId): void
    {
        abort_unless(BranchAccess::canAccess($request->user(), $branchId), 403);
    }

    private function nextOrderNumber(): string
    {
        return 'COM-'.now()->format('Ymd-His').'-'.random_int(100, 999);
    }
}
