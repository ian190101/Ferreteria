<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SaleInventoryService
{
    public function __construct(private readonly SalesWorkflowPolicy $workflow)
    {
    }

    public function decrementForSale(Sale $sale, int $userId): void
    {
        foreach ($sale->items as $item) {
            if ($this->alreadyMoved(SaleItem::class, (int) $item->id, 'sale_stock_out')) {
                continue;
            }

            if ($item->product->inventory_tracking_mode === Product::TRACKING_COIL) {
                $this->decrementCoilStock($sale, $item, $userId);

                continue;
            }

            $this->decrementGlobalStock($sale, $item, $userId);
        }
    }

    public function decrementForDeliveryItem(DeliveryNoteItem $deliveryItem, int $userId): void
    {
        if ($this->alreadyMoved(DeliveryNoteItem::class, (int) $deliveryItem->id, 'delivery_stock_out')) {
            return;
        }

        $deliveryItem->loadMissing([
            'deliveryNote:id,branch_id,delivery_number,delivered_at',
            'saleItem:id,sale_id,product_id,product_coil_id,meters',
            'product:id,inventory_tracking_mode',
        ]);

        $sale = new Sale([
            'branch_id' => $deliveryItem->deliveryNote->branch_id,
            'receipt_number' => $deliveryItem->deliveryNote->delivery_number,
            'sold_at' => $deliveryItem->deliveryNote->delivered_at,
        ]);
        $item = new SaleItem([
            'id' => $deliveryItem->id,
            'product_id' => $deliveryItem->product_id,
            'product_coil_id' => $deliveryItem->product_coil_id,
            'meters' => $deliveryItem->meters,
        ]);
        $item->setRelation('product', $deliveryItem->product);

        if ($deliveryItem->product->inventory_tracking_mode === Product::TRACKING_COIL) {
            $this->decrementCoilStock($sale, $item, $userId, DeliveryNoteItem::class, (int) $deliveryItem->id, 'delivery_stock_out');

            return;
        }

        $this->decrementGlobalStock($sale, $item, $userId, DeliveryNoteItem::class, (int) $deliveryItem->id, 'delivery_stock_out');
    }

    public function returnForVoidedSale(Sale $sale, int $userId): void
    {
        $sale->loadMissing('items.deliveryItems');

        $saleItemIds = $sale->items->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $deliveryItemIds = $sale->items
            ->flatMap(fn (SaleItem $item) => $item->deliveryItems->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $movements = $this->stockOutMovements($saleItemIds, $deliveryItemIds);

        foreach ($movements as $movement) {
            if ($this->alreadyMoved($movement->source_type, (int) $movement->source_id, 'sale_void_return')) {
                continue;
            }

            $this->returnMovement($movement, $sale, $userId);
        }
    }

    public function consumeReservationsForQuotation(Sale $quotation, Sale $newSale): void
    {
        $reservations = InventoryReservation::query()
            ->where('sale_id', $quotation->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if (! $reservation->product_coil_id) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $reservation->branch_id)
                    ->where('product_id', $reservation->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $stock->update([
                        'reserved_meters' => max(round((float) $stock->reserved_meters - (float) $reservation->meters, 3), 0),
                    ]);
                }
            }

            $reservation->update([
                'status' => InventoryReservation::STATUS_CONSUMED,
                'consumed_sale_id' => $newSale->id,
                'consumed_at' => now(),
            ]);
        }
    }

    private function decrementGlobalStock(Sale $sale, SaleItem $item, int $userId, string $sourceType = SaleItem::class, ?int $sourceId = null, string $movementType = 'sale_stock_out'): void
    {
        $stock = ProductBranchStock::query()
            ->where('branch_id', $sale->branch_id)
            ->where('product_id', $item->product_id)
            ->lockForUpdate()
            ->first();

        $allowsNegativeStock = $this->allowsNegativeStockFor($item, $userId);

        if (! $stock) {
            if (! $allowsNegativeStock) {
                throw ValidationException::withMessages([
                    'items' => 'La sucursal no tiene stock global suficiente para completar la venta.',
                ]);
            }

            $stock = ProductBranchStock::query()->create([
                'branch_id' => $sale->branch_id,
                'product_id' => $item->product_id,
                'available_meters' => 0,
                'reserved_meters' => 0,
                'is_enabled' => true,
            ]);
            $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }

        $before = (float) $stock->available_meters;
        $after = round($before - (float) $item->meters, 3);
        $reserved = (float) $stock->reserved_meters;

        if (! $allowsNegativeStock && ($after < 0 || $after < $reserved)) {
            throw ValidationException::withMessages([
                'items' => 'La sucursal no tiene stock global libre suficiente para completar la venta.',
            ]);
        }

        $stock->update(['available_meters' => $after]);
        $this->movement($sale, $item, $userId, $movementType, -((float) $item->meters), $before, $after, null, $sourceType, $sourceId);
    }

    private function decrementCoilStock(Sale $sale, SaleItem $item, int $userId, string $sourceType = SaleItem::class, ?int $sourceId = null, string $movementType = 'sale_stock_out'): void
    {
        $coil = ProductCoil::query()->lockForUpdate()->findOrFail($item->product_coil_id);

        if ((int) $coil->branch_id !== (int) $sale->branch_id || (int) $coil->product_id !== (int) $item->product_id || $coil->status !== 'available') {
            throw ValidationException::withMessages([
                'items' => 'Un lote o unidad fisica seleccionada ya no esta disponible para la venta.',
            ]);
        }

        $before = (float) $coil->available_meters;
        $after = round($before - (float) $item->meters, 3);
        $reserved = (float) InventoryReservation::query()
            ->where('product_coil_id', $coil->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->sum('meters');

        if ($after < 0 || $after < $reserved) {
            throw ValidationException::withMessages([
                'items' => 'Un lote o unidad fisica seleccionada no tiene cantidad libre suficiente.',
            ]);
        }

        $coil->update([
            'available_meters' => $after,
            'status' => $after <= 0 ? 'depleted' : 'available',
        ]);
        $this->movement($sale, $item, $userId, $movementType, -((float) $item->meters), $before, $after, $coil->id, $sourceType, $sourceId);
    }

    private function movement(Sale $sale, SaleItem $item, int $userId, string $type, float $delta, float $before, float $after, ?int $coilId, string $sourceType = SaleItem::class, ?int $sourceId = null): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $sale->branch_id,
            'product_id' => $item->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? $item->id,
            'type' => $type,
            'meters_delta' => $delta,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => "Venta {$sale->receipt_number}",
            'created_at' => $sale->sold_at,
        ]);
    }

    private function stockOutMovements(array $saleItemIds, array $deliveryItemIds): Collection
    {
        if ($saleItemIds === [] && $deliveryItemIds === []) {
            return collect();
        }

        return InventoryMovement::query()
            ->where(function ($query) use ($saleItemIds, $deliveryItemIds) {
                if ($saleItemIds !== []) {
                    $query->orWhere(function ($saleItemQuery) use ($saleItemIds) {
                        $saleItemQuery
                            ->where('source_type', SaleItem::class)
                            ->whereIn('source_id', $saleItemIds)
                            ->where('type', 'sale_stock_out');
                    });
                }

                if ($deliveryItemIds !== []) {
                    $query->orWhere(function ($deliveryItemQuery) use ($deliveryItemIds) {
                        $deliveryItemQuery
                            ->where('source_type', DeliveryNoteItem::class)
                            ->whereIn('source_id', $deliveryItemIds)
                            ->where('type', 'delivery_stock_out');
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }

    private function returnMovement(InventoryMovement $movement, Sale $sale, int $userId): void
    {
        $quantity = abs((float) $movement->meters_delta);

        if ($quantity <= 0) {
            return;
        }

        if ($movement->product_coil_id) {
            $coil = ProductCoil::query()->lockForUpdate()->findOrFail($movement->product_coil_id);
            $before = (float) $coil->available_meters;
            $after = round($before + $quantity, 3);

            $coil->update([
                'available_meters' => $after,
                'status' => 'available',
            ]);

            $this->returnMovementRecord($movement, $sale, $userId, $quantity, $before, $after, (int) $coil->id);

            return;
        }

        $stock = ProductBranchStock::query()->firstOrCreate([
            'branch_id' => $movement->branch_id,
            'product_id' => $movement->product_id,
        ], [
            'available_meters' => 0,
            'reserved_meters' => 0,
            'is_enabled' => true,
        ]);
        $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = (float) $stock->available_meters;
        $after = round($before + $quantity, 3);

        $stock->update(['available_meters' => $after]);
        $this->returnMovementRecord($movement, $sale, $userId, $quantity, $before, $after, null);
    }

    private function returnMovementRecord(InventoryMovement $sourceMovement, Sale $sale, int $userId, float $quantity, float $before, float $after, ?int $coilId): void
    {
        InventoryMovement::query()->create([
            'branch_id' => $sourceMovement->branch_id,
            'product_id' => $sourceMovement->product_id,
            'product_coil_id' => $coilId,
            'user_id' => $userId,
            'source_type' => $sourceMovement->source_type,
            'source_id' => $sourceMovement->source_id,
            'type' => 'sale_void_return',
            'meters_delta' => $quantity,
            'meters_before' => $before,
            'meters_after' => $after,
            'reason' => "Anulacion {$sale->receipt_number}",
            'created_at' => now(),
        ]);
    }

    private function alreadyMoved(string $sourceType, int $sourceId, string $type): bool
    {
        return InventoryMovement::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', $type)
            ->exists();
    }

    private function allowsNegativeStockFor(SaleItem $item, int $userId): bool
    {
        if (! $this->workflow->allowsNegativeStock()) {
            return false;
        }

        $user = User::query()->with('roles:id,name')->find($userId);

        if (! $user) {
            return false;
        }

        $item->loadMissing('product.productCategory:id,name');

        return app(CommercialPolicy::class)->canSellNegativeStock($user, $item->product?->productCategory?->name);
    }
}
