<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SaleItem;
use App\Support\OperationalCacheOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReservationService
{
    public function __construct(
        private readonly LotTrackingPolicy $policy,
        private readonly InventoryQuantityService $quantity,
        private readonly OperationalCacheOutbox $outbox,
    ) {}

    public function reserve(int $lotId, int $saleId, int $saleItemId, string $amount, int $userId,
        ?int $committedSaleItemId = null): InventoryReservation
    {
        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $lotRef = ProductCoil::query()->findOrFail($lotId);
        $product = Product::query()->whereKey($lotRef->product_id)->lockForUpdate()->firstOrFail();
        if (! $this->policy->required($product, $configuration)) {
            throw ValidationException::withMessages(['lot_id' => 'El producto no tiene control de lotes activo.']);
        }
        $stock = ProductBranchStock::query()->where('branch_id', $lotRef->branch_id)
            ->where('product_id', $lotRef->product_id)->lockForUpdate()->firstOrFail();
        if (! $stock->is_enabled) {
            throw ValidationException::withMessages(['lot_id' => 'El producto no está habilitado en la sucursal.']);
        }
        $lot = ProductCoil::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
        if ($lot->product_id !== $product->id || $lot->trashed()) {
            throw ValidationException::withMessages(['lot_id' => 'El lote no pertenece al producto.']);
        }
        $reservations = InventoryReservation::query()->where('product_coil_id', $lotId)
            ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        if ($reservations->contains(fn (InventoryReservation $entry) => (int) $entry->sale_item_id === $saleItemId)) {
            throw ValidationException::withMessages(['sale_item_id' => 'La línea ya tiene una reserva activa.']);
        }
        $amount = $this->quantity->exact($amount);
        $reserved = '0.000';
        foreach ($reservations as $reservation) {
            $reserved = $this->quantity->addExact($reserved, (string) $reservation->remaining_meters);
        }
        if ($lot->isVoided() || $this->quantity->compareExact($amount, '0') <= 0
            || $this->quantity->compareExact($this->quantity->subtractExact((string) $lot->available_meters, $reserved), $amount) < 0
            || $this->quantity->compareExact($this->quantity->subtractExact((string) $stock->available_meters,
                (string) $stock->reserved_meters), $amount) < 0) {
            throw ValidationException::withMessages(['lot_id' => 'El lote no tiene saldo libre suficiente.']);
        }

        $reservation = InventoryReservation::query()->create([
            'branch_id' => $lot->branch_id,
            'product_id' => $lot->product_id,
            'product_coil_id' => $lot->id,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'committed_sale_item_id' => $committedSaleItemId,
            'user_id' => $userId,
            'meters' => $amount,
            'remaining_meters' => $amount,
            'status' => InventoryReservation::STATUS_ACTIVE,
        ]);
        $stock->reserved_meters = $this->quantity->addExact((string) $stock->reserved_meters, $amount);
        $stock->save();
        $this->outbox->schedule('lot_inventory');

        return $reservation;
    }

    public function commitmentFor(SaleItem $item): ?InventoryReservation
    {
        return InventoryReservation::query()->where('committed_sale_item_id', $item->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->orderBy('id')->first();
    }

    public function releaseForDocument(\App\Modules\Sales\Models\Sale $sale): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La liberación del documento requiere transacción.');
        }
        $this->policy->lockRule();
        $this->policy->persistedConfiguration();
        $reservations = InventoryReservation::query()->where('status', InventoryReservation::STATUS_ACTIVE)
            ->where(fn ($query) => $query->where('sale_id', $sale->id)->orWhere('consumed_sale_id', $sale->id))
            ->when($sale->document_type === 'quotation', fn ($query) => $query->whereNull('committed_sale_item_id'))
            ->whereHas('lot', fn ($query) => $query->whereNotNull('lot_code'))
            ->orderBy('id')->get();
        if ($reservations->isEmpty()) {
            return;
        }
        app(LotInventoryService::class)->lockBatch($reservations->map(fn (InventoryReservation $reservation) => [
            'product_id' => (int) $reservation->product_id, 'branch_id' => (int) $reservation->branch_id,
            'lot_id' => (int) $reservation->product_coil_id,
        ])->all());
        foreach ($reservations as $reservation) {
            $this->release($reservation);
        }
    }

    public function transferToSale(InventoryReservation $reservation, SaleItem $item): void
    {
        if ((int) $reservation->product_coil_id !== (int) $item->product_coil_id
            || (int) $reservation->product_id !== (int) $item->product_id
            || $this->quantity->compareExact((string) $reservation->remaining_meters, (string) $item->meters) !== 0) {
            throw ValidationException::withMessages(['source_quotation_id' => 'La reserva de la cotización no coincide con la línea de venta.']);
        }
        $reservation->committed_sale_item_id = $item->id;
        $reservation->consumed_sale_id = $item->sale_id;
        $reservation->save();
        $this->outbox->schedule('lot_inventory');
    }

    public function release(InventoryReservation $reservation): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La liberación requiere transacción.');
        }
        $this->policy->lockRule();
        $this->policy->persistedConfiguration();
        Product::query()->whereKey($reservation->product_id)->lockForUpdate()->firstOrFail();
        $stock = ProductBranchStock::query()->where('branch_id', $reservation->branch_id)
            ->where('product_id', $reservation->product_id)->lockForUpdate()->firstOrFail();
        ProductCoil::query()->whereKey($reservation->product_coil_id)->lockForUpdate()->firstOrFail();
        $reservation = InventoryReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        if ($reservation->status !== InventoryReservation::STATUS_ACTIVE) {
            return;
        }
        $remaining = (string) $reservation->remaining_meters;
        $stock->reserved_meters = $this->quantity->subtractExact((string) $stock->reserved_meters, $remaining);
        $stock->save();
        $reservation->remaining_meters = '0.000';
        $reservation->status = InventoryReservation::STATUS_RELEASED;
        $reservation->released_at = now();
        $reservation->save();
        $this->outbox->schedule('lot_inventory');
    }

    public function expireDue(int $limit = 100): int
    {
        $ids = InventoryReservation::query()->where('status', InventoryReservation::STATUS_ACTIVE)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->whereHas('lot', fn ($query) => $query->whereNotNull('lot_code'))
            ->orderBy('id')->limit($limit)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$expired): void {
                $this->policy->lockRule();
                $this->policy->persistedConfiguration();
                $snapshot = InventoryReservation::query()->find($id);
                if (! $snapshot || $snapshot->status !== InventoryReservation::STATUS_ACTIVE
                    || $snapshot->expires_at === null || $snapshot->expires_at->isFuture()) {
                    return;
                }
                $this->release($snapshot);
                $expired++;
            }, 3);
        }

        return $expired;
    }
}
