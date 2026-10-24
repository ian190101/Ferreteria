<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Finance\Support\Decimal;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;

class InventoryQuantityService
{
    public function exact(string $value, int $scale = 3): string
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new \InvalidArgumentException('La cantidad debe ser un decimal sin notación exponencial.');
        }

        $rounded = Decimal::round($value, $scale);
        $integerDigits = strlen(ltrim(explode('.', ltrim($rounded, '-'))[0], '0'));
        if ($integerDigits > 18 - $scale) {
            throw new \InvalidArgumentException('La cantidad excede la precisión permitida.');
        }

        return $rounded;
    }

    public function addExact(string $left, string $right, int $scale = 3): string
    {
        return $this->exact(bcadd($this->exact($left, $scale), $this->exact($right, $scale), $scale), $scale);
    }

    public function subtractExact(string $left, string $right, int $scale = 3): string
    {
        return $this->exact(bcsub($this->exact($left, $scale), $this->exact($right, $scale), $scale), $scale);
    }

    public function multiplyExact(string $left, string $right, int $scale = 3): string
    {
        return $this->exact(bcmul($left, $right, $scale + 6), $scale);
    }

    public function divideExact(string $left, string $right, int $scale = 3): string
    {
        if (bccomp($right, '0', $scale + 6) === 0) {
            throw new \InvalidArgumentException('No se puede dividir entre cero.');
        }

        return $this->exact(bcdiv($left, $right, $scale + 6), $scale);
    }

    public function compareExact(string $left, string $right, int $scale = 3): int
    {
        return bccomp($this->exact($left, $scale), $this->exact($right, $scale), $scale);
    }

    public function metersFromWeightExact(string $kilograms, ?string $kgPerMeter, ?string $metersPerKg): string
    {
        if ($kgPerMeter !== null && bccomp($kgPerMeter, '0', 6) > 0) {
            return $this->divideExact($kilograms, $kgPerMeter);
        }
        if ($metersPerKg !== null && bccomp($metersPerKg, '0', 6) > 0) {
            return $this->multiplyExact($kilograms, $metersPerKg);
        }

        throw new \InvalidArgumentException('Indica los metros o configura el factor de peso del espesor.');
    }
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
