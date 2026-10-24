<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use Illuminate\Validation\ValidationException;

class BusinessLotRuleTransitionGuard
{
    public function __construct(
        private readonly LotTrackingPolicy $policy,
        private readonly InventoryQuantityService $quantity,
    ) {}

    public function assertTransition(array $next): void
    {
        // La fila estable serializa el cambio de perfil con los escritores de inventario.
        $this->policy->lockRule(true);
        $previous = $this->policy->persistedConfiguration();
        $this->policy->assertConsistent($next);
        $wasEnabled = $this->policy->enabled($previous);
        $willEnable = $this->policy->enabled($next);
        if ($wasEnabled === $willEnable) {
            return;
        }

        $products = Product::query()->where('requires_lot', true)->where('is_inventory_item', true)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($products as $product) {
            $stocks = ProductBranchStock::query()->where('product_id', $product->id)
                ->orderBy('branch_id')->orderBy('product_id')->lockForUpdate()->get();
            $lots = ProductCoil::query()->where('product_id', $product->id)
                ->orderBy('id')->lockForUpdate()->get();
            $reservations = InventoryReservation::query()->where('product_id', $product->id)
                ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();

            if ($willEnable) {
                foreach ($stocks as $stock) {
                    $identified = '0.000';
                    foreach ($lots->where('branch_id', $stock->branch_id) as $lot) {
                        if ($lot->lot_code === null && $this->quantity->compareExact((string) $lot->available_meters, '0') !== 0) {
                            throw ValidationException::withMessages(['configuration.inventory.lot_tracking_enabled' => "El producto {$product->id} tiene bobinas sin código gestionado."]);
                        }
                        if ($lot->lot_code !== null) {
                            $identified = $this->quantity->addExact($identified, (string) $lot->available_meters);
                        }
                    }
                    if ($this->quantity->compareExact((string) $stock->available_meters, $identified) !== 0) {
                        throw ValidationException::withMessages(['configuration.inventory.lot_tracking_enabled' => "El producto {$product->id} de la sucursal {$stock->branch_id} tiene saldo sin conciliar."]);
                    }
                }
                if ($reservations->contains(fn (InventoryReservation $row) => $row->product_coil_id === null)) {
                    throw ValidationException::withMessages(['configuration.inventory.lot_tracking_enabled' => "El producto {$product->id} conserva reservas sin lote."]);
                }
            } else {
                $hasBalance = $lots->whereNotNull('lot_code')->contains(fn (ProductCoil $lot) =>
                    $this->quantity->compareExact((string) $lot->available_meters, '0') > 0);
                $hasCommitment = $reservations->whereNotNull('product_coil_id')->contains(fn (InventoryReservation $row) =>
                    $this->quantity->compareExact((string) $row->remaining_meters, '0') > 0);
                if ($hasBalance || $hasCommitment) {
                    throw ValidationException::withMessages(['configuration.inventory.lot_tracking_enabled' => "El producto {$product->id} aún tiene saldos o compromisos de lote."]);
                }
            }
        }
    }
}
