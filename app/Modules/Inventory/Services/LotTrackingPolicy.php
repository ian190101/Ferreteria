<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LotTrackingPolicy
{
    public function enabled(array $configuration): bool
    {
        $configuration = BusinessProfileConfiguration::normalized($configuration);

        return $configuration['modules']['inventory'] === true
            && $configuration['capabilities']['uses_inventory'] === true
            && $configuration['capabilities']['uses_lots'] === true
            && $configuration['inventory']['lot_tracking_enabled'] === true;
    }

    public function reserveOnQuotation(array $configuration): bool
    {
        $configuration = BusinessProfileConfiguration::normalized($configuration);

        return $this->enabled($configuration)
            && $configuration['sales']['reserve_lots_on_quotation'] === true;
    }

    public function required(Product $product, array $configuration): bool
    {
        return $this->enabled($configuration)
            && $product->is_inventory_item === true
            && $product->requires_lot === true;
    }

    public function activeConfiguration(): array
    {
        return ActiveBusinessProfile::payload()['configuration'];
    }

    public function persistedConfiguration(): array
    {
        $profile = BusinessProfile::query()->where('status', 'active')
            ->orderByDesc('applied_at')->orderByDesc('id')->sharedLock()->first();

        return BusinessProfileConfiguration::normalized($profile?->configuration ?? []);
    }

    public function lockRule(bool $exclusive = false): void
    {
        if (! $exclusive && app()->bound('business_profile_preview_draft')
            && ! app()->bound('business_full_sandbox')) {
            throw ValidationException::withMessages(['business_profile' => 'Sal del borrador en previsualización antes de modificar inventario.']);
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La regla de lotes requiere una transacción abierta.');
        }

        $query = DB::table('inventory_lot_rule_locks')->where('id', 1);
        $row = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first();
        if ($row === null) {
            throw new \LogicException('Falta la fila de coordinación de lotes.');
        }
    }

    public function assertConsistent(array $configuration): void
    {
        $configuration = BusinessProfileConfiguration::normalized($configuration);
        if ($configuration['inventory']['lot_tracking_enabled'] && ! $this->enabled($configuration)) {
            throw ValidationException::withMessages(['configuration.inventory.lot_tracking_enabled' => 'Habilita inventario y las capacidades de inventario y lotes.']);
        }

        if ($configuration['sales']['reserve_lots_on_quotation']
            && (! $this->enabled($configuration)
                || ! $configuration['modules']['reservations']
                || ! $configuration['capabilities']['uses_reservations'])) {
            throw ValidationException::withMessages(['configuration.sales.reserve_lots_on_quotation' => 'La reserva al cotizar requiere control de lotes y reservas habilitadas.']);
        }

        if ($this->enabled($configuration) && $configuration['sales']['inventory_discount_timing'] === 'manual') {
            throw ValidationException::withMessages(['configuration.sales.inventory_discount_timing' => 'El control de lotes requiere descuento al emitir, pagar o despachar.']);
        }
    }

    public function assertAlternativeWriterAllowed(Product $product): void
    {
        $this->lockRule();
        $configuration = $this->persistedConfiguration();
        $protectedBalance = ProductCoil::query()->where('product_id', $product->id)
            ->whereNotNull('lot_code')->where('available_meters', '>', 0)->exists();
        $commitment = InventoryReservation::query()->where('product_id', $product->id)
            ->whereNotNull('product_coil_id')->where('status', InventoryReservation::STATUS_ACTIVE)
            ->where('remaining_meters', '>', 0)->exists();
        if ($this->required($product, $configuration) || $protectedBalance || $commitment) {
            throw ValidationException::withMessages(['product_id' => 'Este producto tiene control o historial de lotes; esta operación requiere un flujo compatible con lotes.']);
        }
    }
}
