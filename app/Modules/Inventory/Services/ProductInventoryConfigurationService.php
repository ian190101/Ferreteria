<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductInventoryConfigurationService
{
    public function __construct(
        private readonly LotTrackingPolicy $policy,
        private readonly InventoryQuantityService $quantity,
    ) {}

    public function validateCreate(array $attributes): void
    {
        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $legacyRental = ($attributes['item_type'] ?? null) === 'rental'
            && ($configuration['rentals']['enabled'] ?? false) === true;
        if (($attributes['requires_lot'] ?? false) && ((! $this->policy->enabled($configuration) && ! $legacyRental)
            || ! ($attributes['is_inventory_item'] ?? false))) {
            throw ValidationException::withMessages(['requires_lot' => 'El control de lotes requiere un producto inventariable y la regla activa.']);
        }
    }

    public function validateUpdate(Product $product, array $attributes, array $enabledBranchIds): void
    {
        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $newRequiresLot = array_key_exists('requires_lot', $attributes)
            ? (bool) $attributes['requires_lot'] : (bool) $product->requires_lot;

        if ($newRequiresLot && ! $product->requires_lot && (! $this->policy->enabled($configuration)
            || ! ($attributes['is_inventory_item'] ?? $product->is_inventory_item))) {
            throw ValidationException::withMessages(['requires_lot' => 'El control de lotes requiere un producto inventariable y la regla activa.']);
        }

        $stocks = ProductBranchStock::query()->where('product_id', $product->id)
            ->orderBy('branch_id')->orderBy('product_id')->lockForUpdate()->get();
        $lots = ProductCoil::query()->where('product_id', $product->id)
            ->orderBy('id')->lockForUpdate()->get();
        $reservations = InventoryReservation::query()->where('product_id', $product->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();

        if (! $product->requires_lot && $newRequiresLot) {
            foreach ($stocks as $stock) {
                $identified = '0.000';
                foreach ($lots->where('branch_id', $stock->branch_id) as $lot) {
                    if ($lot->lot_code === null && $this->quantity->compareExact((string) $lot->available_meters, '0') !== 0) {
                        throw ValidationException::withMessages(['requires_lot' => 'Existen bobinas sin código gestionado; concilia sus identidades antes de activar.']);
                    }
                    if ($lot->lot_code !== null) {
                        $identified = $this->quantity->addExact($identified, (string) $lot->available_meters);
                    }
                }
                if ($this->quantity->compareExact((string) $stock->available_meters, $identified) !== 0
                    || $reservations->where('branch_id', $stock->branch_id)->whereNull('product_coil_id')->isNotEmpty()) {
                    throw ValidationException::withMessages(['requires_lot' => 'El saldo o las reservas existentes no están conciliados por lote.']);
                }
            }
        }

        $hasHistory = $lots->whereNotNull('lot_code')->isNotEmpty()
            || InventoryMovement::query()->where('product_id', $product->id)->whereNotNull('lot_code_snapshot')->exists();
        if ($hasHistory) {
            foreach (['inventory_tracking_mode', 'base_unit', 'product_unit_id'] as $field) {
                if (array_key_exists($field, $attributes) && (string) $attributes[$field] !== (string) $product->{$field}) {
                    throw ValidationException::withMessages([$field => 'El producto conserva historial gestionado; esta conversión requiere un procedimiento de migración.']);
                }
            }
            if (! ($attributes['is_inventory_item'] ?? $product->is_inventory_item)) {
                throw ValidationException::withMessages(['is_inventory_item' => 'El producto conserva historial de lotes.']);
            }
        }

        $hasBalance = $lots->whereNotNull('lot_code')->contains(fn (ProductCoil $lot) =>
            $this->quantity->compareExact((string) $lot->available_meters, '0') > 0);
        $hasCommitment = $reservations->contains(fn (InventoryReservation $reservation) =>
            $this->quantity->compareExact((string) $reservation->remaining_meters, '0') > 0);
        if (($hasBalance || $hasCommitment) && ! $newRequiresLot) {
            throw ValidationException::withMessages(['requires_lot' => 'Libera compromisos y agota el saldo identificado antes de desactivar el producto.']);
        }
        foreach ($stocks as $stock) {
            if (($hasBalance || $hasCommitment) && $stock->is_enabled && ! in_array((int) $stock->branch_id, $enabledBranchIds, true)) {
                throw ValidationException::withMessages(['branch_ids' => 'No se puede deshabilitar una sucursal con lotes o compromisos pendientes.']);
            }
        }
    }
}
