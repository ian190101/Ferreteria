<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;

class InventoryQuantityService
{
    public function available(ProductBranchStock $stock): float
    {
        return (float) $stock->available_meters;
    }

    public function reserved(ProductBranchStock $stock): float
    {
        return (float) $stock->reserved_meters;
    }

    public function free(ProductBranchStock $stock): float
    {
        return round($this->available($stock) - $this->reserved($stock), 3);
    }

    public function increase(ProductBranchStock $stock, float $quantity): array
    {
        $before = $this->available($stock);
        $after = round($before + $quantity, 3);
        $stock->update(['available_meters' => $after]);

        return [$before, $after];
    }

    public function decrease(ProductBranchStock $stock, float $quantity): array
    {
        $before = $this->available($stock);
        $after = round($before - $quantity, 3);
        $stock->update(['available_meters' => $after]);

        return [$before, $after];
    }

    public function reserve(ProductBranchStock $stock, float $quantity): array
    {
        $before = $this->reserved($stock);
        $after = round($before + $quantity, 3);
        $stock->update(['reserved_meters' => $after]);

        return [$before, $after];
    }

    public function release(ProductBranchStock $stock, float $quantity): array
    {
        $before = $this->reserved($stock);
        $after = max(round($before - $quantity, 3), 0);
        $stock->update(['reserved_meters' => $after]);

        return [$before, $after];
    }

    public function movementQuantity(InventoryMovement $movement): float
    {
        return (float) $movement->meters_delta;
    }
}
