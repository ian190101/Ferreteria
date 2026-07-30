<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Restaurant\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RecipeInventoryService
{
    public function __construct(
        private readonly InventoryQuantityService $quantities,
        private readonly RestaurantWorkflowPolicy $policy,
    ) {}

    public function consumeForOrder(KitchenOrder $order, int $userId): void
    {
        $order->loadMissing(['items.recipe.items.ingredient']);

        foreach ($order->items as $orderItem) {
            if (! $orderItem->recipe) {
                continue;
            }

            $this->consumeRecipe($order, $orderItem->recipe, (float) $orderItem->quantity, $userId);
        }
    }

    public function validateAvailability(Recipe $recipe, int $branchId, float $quantity): void
    {
        if ($this->policy->allowsNegativeStock()) {
            return;
        }

        $recipe->loadMissing('items.ingredient');
        $required = $this->requiredQuantities($recipe, $quantity);
        $stocks = ProductBranchStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $required->keys()->all())
            ->get()
            ->keyBy('product_id');

        foreach ($required as $productId => $meters) {
            $stock = $stocks->get($productId);
            $free = $stock ? $this->quantities->free($stock) : 0.0;

            if ($free < $meters) {
                $ingredient = $recipe->items->firstWhere('ingredient_product_id', $productId)?->ingredient?->name ?? 'insumo';

                throw ValidationException::withMessages([
                    'items' => "No hay stock suficiente del insumo {$ingredient} para preparar {$recipe->name}.",
                ]);
            }
        }
    }

    private function consumeRecipe(KitchenOrder $order, Recipe $recipe, float $quantity, int $userId): void
    {
        $required = $this->requiredQuantities($recipe, $quantity);

        foreach ($required as $productId => $meters) {
            $stock = ProductBranchStock::query()
                ->where('branch_id', $order->branch_id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                if (! $this->policy->allowsNegativeStock()) {
                    throw ValidationException::withMessages(['items' => 'No hay stock configurado para uno de los insumos de la receta.']);
                }

                $stock = ProductBranchStock::query()->create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $productId,
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                    'is_enabled' => true,
                ]);
                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            }

            [$before, $after] = $this->quantities->decrease($stock, $meters);

            InventoryMovement::query()->create([
                'branch_id' => $order->branch_id,
                'product_id' => $productId,
                'product_coil_id' => null,
                'user_id' => $userId,
                'source_type' => $order::class,
                'source_id' => $order->id,
                'type' => 'restaurant_recipe_consumption',
                'meters_delta' => -$meters,
                'meters_before' => $before,
                'meters_after' => $after,
                'reason' => "Consumo de receta {$recipe->name} en comanda {$order->order_number}",
                'created_at' => now(),
            ]);
        }
    }

    private function requiredQuantities(Recipe $recipe, float $quantity): Collection
    {
        $yield = max((float) $recipe->yield_quantity, 0.001);
        $multiplier = $quantity / $yield;

        return $recipe->items
            ->groupBy('ingredient_product_id')
            ->map(fn (Collection $items) => round($items->sum(function ($item) use ($multiplier) {
                $base = (float) $item->quantity * $multiplier;
                $waste = max((float) $item->waste_percentage, 0) / 100;

                return $base + ($base * $waste);
            }), 3));
    }
}
