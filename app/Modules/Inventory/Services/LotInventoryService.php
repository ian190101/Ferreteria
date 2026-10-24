<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\OperationalCacheOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LotInventoryService
{
    public function __construct(
        private readonly LotTrackingPolicy $policy,
        private readonly InventoryQuantityService $quantity,
        private readonly OperationalCacheOutbox $outbox,
    ) {}

    public function lockBatch(array $entries, bool $createMissingStock = false): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La planificación de lotes requiere una transacción abierta.');
        }
        $this->policy->lockRule();
        $this->policy->persistedConfiguration();
        $products = [];
        $stocks = [];
        $lots = [];
        foreach ($entries as $entry) {
            $productId = (int) ($entry['product_id'] ?? 0);
            $branchId = (int) ($entry['branch_id'] ?? 0);
            $lotId = (int) ($entry['lot_id'] ?? 0);
            if ($productId < 1 || $branchId < 1) {
                throw ValidationException::withMessages(['lot_id' => 'Falta producto o sucursal en la planificación de lotes.']);
            }
            $products[$productId] = $productId;
            $stocks[$branchId.':'.$productId] = [$branchId, $productId];
            if ($lotId > 0) {
                $lots[$lotId] = $lotId;
            }
        }
        sort($products, SORT_NUMERIC);
        foreach ($products as $id) {
            Product::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
        usort($stocks, fn (array $left, array $right) => $left <=> $right);
        foreach ($stocks as [$branchId, $productId]) {
            $stock = ProductBranchStock::query()->where('branch_id', $branchId)
                ->where('product_id', $productId)->lockForUpdate()->first();
            if (! $stock && $createMissingStock) {
                $created = ProductBranchStock::query()->create([
                    'branch_id' => $branchId, 'product_id' => $productId,
                    'available_meters' => '0.000', 'reserved_meters' => '0.000', 'is_enabled' => true,
                ]);
                ProductBranchStock::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
            }
        }
        sort($lots, SORT_NUMERIC);
        foreach ($lots as $id) {
            ProductCoil::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
        if ($lots !== []) {
            InventoryReservation::query()->whereIn('product_coil_id', $lots)
                ->where('status', InventoryReservation::STATUS_ACTIVE)
                ->orderBy('id')->lockForUpdate()->get();
        }
    }

    public function apply(
        int $productId,
        int $branchId,
        int $lotId,
        string $delta,
        string $type,
        Model $source,
        string $idempotencyKey,
        int $userId,
        bool $allowVoidedReturn = false,
        ?int $reversesMovementId = null,
        ?int $consumeReservationId = null,
    ): InventoryMovement {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('El movimiento requiere una transacción abierta.');
        }

        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
        // La segunda lectura debe ser bloqueante después de serializar el producto.
        $existing = InventoryMovement::query()->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->product_id !== $productId || (int) $existing->branch_id !== $branchId
                || (int) $existing->product_coil_id !== $lotId || $existing->type !== $type
                || $existing->source_type !== $source->getMorphClass()
                || (int) $existing->source_id !== (int) $source->getKey()
                || $this->quantity->compareExact((string) $existing->meters_delta, $delta) !== 0) {
                throw ValidationException::withMessages(['lot_id' => 'La clave del movimiento ya corresponde a otra operación.']);
            }
            return $existing;
        }
        $stock = ProductBranchStock::query()->where('branch_id', $branchId)
            ->where('product_id', $productId)->lockForUpdate()->first();
        if (! $stock) {
            $stock = ProductBranchStock::query()->create([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'available_meters' => '0.000',
                'reserved_meters' => '0.000',
                'is_enabled' => true,
            ]);
            $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }

        $lot = ProductCoil::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
        if ($lot->product_id !== $product->id || $lot->branch_id !== $branchId || $lot->lot_code === null || $lot->trashed()) {
            throw ValidationException::withMessages(['lot_id' => 'El lote no pertenece al producto y sucursal indicados.']);
        }

        if ($lot->isVoided() && ! $allowVoidedReturn) {
            throw ValidationException::withMessages(['lot_id' => 'El lote está anulado.']);
        }
        if (! $this->policy->required($product, $configuration) && ! $allowVoidedReturn) {
            throw ValidationException::withMessages(['lot_id' => 'El control de lotes no está activo para este producto.']);
        }
        if (! $stock->is_enabled && ! $allowVoidedReturn) {
            throw ValidationException::withMessages(['lot_id' => 'El producto no está habilitado en la sucursal.']);
        }

        $delta = $this->quantity->exact($delta);
        if ($this->quantity->compareExact($delta, '0') === 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser distinta de cero.']);
        }

        $reservations = InventoryReservation::query()->where('product_coil_id', $lotId)
            ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        $reserved = '0.000';
        foreach ($reservations as $reservation) {
            $reserved = $this->quantity->addExact($reserved, (string) $reservation->remaining_meters);
        }

        $ownReservation = $consumeReservationId === null ? null : $reservations->firstWhere('id', $consumeReservationId);
        if ($consumeReservationId !== null && ! $ownReservation) {
            throw ValidationException::withMessages(['lot_id' => 'No existe el compromiso activo de esta línea.']);
        }
        $reservedAfter = $reserved;
        if ($ownReservation !== null) {
            $consumeAmount = ltrim($delta, '-');
            if ($this->quantity->compareExact($delta, '0') >= 0
                || $this->quantity->compareExact((string) $ownReservation->remaining_meters, $consumeAmount) < 0) {
                throw ValidationException::withMessages(['lot_id' => 'El compromiso no cubre esta salida.']);
            }
            $reservedAfter = $this->quantity->subtractExact($reserved, $consumeAmount);
        }

        $lotBefore = (string) $lot->available_meters;
        $stockBefore = (string) $stock->available_meters;
        $lotAfter = $this->quantity->addExact($lotBefore, $delta);
        $stockAfter = $this->quantity->addExact($stockBefore, $delta);
        $stockReservedAfter = $ownReservation === null
            ? (string) $stock->reserved_meters
            : $this->quantity->subtractExact((string) $stock->reserved_meters, ltrim($delta, '-'));
        if ($this->quantity->compareExact($lotAfter, $reservedAfter) < 0
            || $this->quantity->compareExact($stockAfter, $stockReservedAfter) < 0) {
            throw ValidationException::withMessages(['lot_id' => 'Saldo insuficiente o comprometido en el lote.']);
        }

        $stock->available_meters = $stockAfter;
        if ($ownReservation !== null) {
            $consumeAmount = ltrim($delta, '-');
            $stock->reserved_meters = $stockReservedAfter;
            $ownReservation->remaining_meters = $this->quantity->subtractExact((string) $ownReservation->remaining_meters, $consumeAmount);
            if ($this->quantity->compareExact((string) $ownReservation->remaining_meters, '0') === 0) {
                $ownReservation->status = InventoryReservation::STATUS_CONSUMED;
                $ownReservation->consumed_at = now();
            }
            $ownReservation->save();
        }
        $stock->save();
        if ($this->quantity->compareExact((string) $lot->initial_meters, '0') === 0
            && $this->quantity->compareExact($delta, '0') > 0
            && ! InventoryMovement::query()->where('product_coil_id', $lot->id)->exists()) {
            $lot->initial_meters = $delta;
        }
        $lot->available_meters = $lotAfter;
        if (! $lot->isVoided()) {
            $lot->status = $this->quantity->compareExact($lotAfter, '0') === 0 ? 'depleted' : 'available';
        }
        $lot->save();

        $movement = InventoryMovement::query()->create([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product_coil_id' => $lotId,
            'lot_code_snapshot' => $lot->lot_code,
            'user_id' => $userId,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $stockBefore,
            'meters_after' => $stockAfter,
            'lot_quantity_before' => $lotBefore,
            'lot_quantity_after' => $lotAfter,
            'reverses_movement_id' => $reversesMovementId,
            'idempotency_key' => $idempotencyKey,
            'reason' => $type,
            'created_at' => now(),
        ]);

        $this->outbox->schedule('lot_inventory');

        return $movement;
    }
}
