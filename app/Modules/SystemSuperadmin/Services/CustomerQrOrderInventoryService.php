<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerQrOrderInventoryService
{
    public function __construct(private readonly InventoryQuantityService $quantities)
    {
    }

    public function reserve(CustomerQrOrder $order, User $user): void
    {
        if (! $order->branch_id) {
            return;
        }

        if ($this->hasActiveReservations($order)) {
            return;
        }

        DB::transaction(function () use ($order, $user): void {
            foreach ($this->inventoryQuantities($order) as $productId => $quantity) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $order->branch_id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                $free = $stock ? $this->quantities->free($stock) : 0.0;

                if (! $stock || ! $stock->is_enabled || $free < $quantity) {
                    throw ValidationException::withMessages([
                        'stock' => 'No hay stock libre suficiente para aceptar este pedido QR.',
                    ]);
                }

                $this->quantities->reserve($stock, $quantity);

                InventoryReservation::query()->create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $productId,
                    'customer_qr_order_id' => $order->id,
                    'user_id' => $user->id,
                    'meters' => $quantity,
                    'status' => InventoryReservation::STATUS_ACTIVE,
                    'expires_at' => now()->addMinutes((int) data_get(ActiveBusinessProfile::payload(), 'customer_qr_ordering.stock_reservation_minutes', 120)),
                    'reason' => 'Pedido QR '.$order->public_code,
                    'notes' => 'Reserva automatica al aceptar pedido QR.',
                ]);
            }
        });
    }

    public function release(CustomerQrOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $reservations = $this->activeReservations($order);

            foreach ($reservations as $reservation) {
                $this->releaseStock($reservation);
                $reservation->update([
                    'status' => InventoryReservation::STATUS_RELEASED,
                    'released_at' => now(),
                ]);
            }
        });
    }

    public function consumeForSale(CustomerQrOrder $order, int $saleId): void
    {
        DB::transaction(function () use ($order, $saleId): void {
            $reservations = $this->activeReservations($order);

            foreach ($reservations as $reservation) {
                $this->releaseStock($reservation);
                $reservation->update([
                    'status' => InventoryReservation::STATUS_CONSUMED,
                    'sale_id' => $saleId,
                    'consumed_sale_id' => $saleId,
                    'consumed_at' => now(),
                ]);
            }
        });
    }

    public function hasActiveReservations(CustomerQrOrder $order): bool
    {
        return InventoryReservation::query()
            ->where('customer_qr_order_id', $order->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->exists();
    }

    private function activeReservations(CustomerQrOrder $order)
    {
        return InventoryReservation::query()
            ->where('customer_qr_order_id', $order->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();
    }

    private function releaseStock(InventoryReservation $reservation): void
    {
        if ($reservation->product_coil_id) {
            return;
        }

        $stock = ProductBranchStock::query()
            ->where('branch_id', $reservation->branch_id)
            ->where('product_id', $reservation->product_id)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            $this->quantities->release($stock, (float) $reservation->meters);
        }
    }

    /**
     * @return array<int, float>
     */
    private function inventoryQuantities(CustomerQrOrder $order): array
    {
        return collect($order->items ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['is_inventory_item'] ?? false))
            ->groupBy('product_id')
            ->map(fn ($items): float => round(collect($items)->sum(fn (array $item): float => (float) $item['quantity']), 3))
            ->all();
    }
}
