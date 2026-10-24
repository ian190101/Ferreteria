<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Sales\Models\DeliveryNoteItem;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleInventoryService
{
    public function __construct(
        private readonly SalesWorkflowPolicy $workflow,
        private readonly InventoryQuantityService $quantities,
        private readonly LotTrackingPolicy $lotPolicy,
        private readonly LotInventoryService $lotInventory,
        private readonly InventoryReservationService $lotReservations,
    )
    {
    }

    public function returnedDeliveryMetersByItem(array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return DB::table('inventory_movements as restitution')
            ->join('inventory_movements as physical_out', 'physical_out.id', '=', 'restitution.reverses_movement_id')
            ->join('delivery_note_items as delivered', 'delivered.id', '=', 'physical_out.source_id')
            ->where('physical_out.source_type', (new DeliveryNoteItem)->getMorphClass())
            ->where('physical_out.type', 'delivery_stock_out')
            ->where('restitution.type', 'sale_return')->where('restitution.meters_delta', '>', 0)
            ->whereIn('delivered.sale_item_id', $itemIds)->groupBy('delivered.sale_item_id')
            ->selectRaw('delivered.sale_item_id, SUM(restitution.meters_delta) as returned_meters')
            ->pluck('returned_meters', 'sale_item_id');
    }

    public function returnableMetersByItem(array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }
        $deliveries = DeliveryNoteItem::query()->whereIn('sale_item_id', $itemIds)->pluck('sale_item_id', 'id');
        $saleItemType = (new SaleItem)->getMorphClass();
        $deliveryItemType = (new DeliveryNoteItem)->getMorphClass();
        $outflows = InventoryMovement::query()->where(fn ($query) => $query
            ->where(fn ($source) => $source->where('source_type', $saleItemType)->whereIn('source_id', $itemIds)->where('type', 'sale_stock_out'))
            ->orWhere(fn ($source) => $source->where('source_type', $deliveryItemType)->whereIn('source_id', $deliveries->keys())->where('type', 'delivery_stock_out')))
            ->orderBy('id')->get(['id', 'source_type', 'source_id', 'meters_delta']);
        $restored = InventoryMovement::query()->whereIn('reverses_movement_id', $outflows->pluck('id'))
            ->where('meters_delta', '>', 0)->selectRaw('reverses_movement_id, SUM(meters_delta) as restored_meters')
            ->groupBy('reverses_movement_id')->pluck('restored_meters', 'reverses_movement_id');
        $available = collect();
        foreach ($outflows as $outflow) {
            $itemId = $outflow->source_type === $saleItemType ? $outflow->source_id : $deliveries[$outflow->source_id];
            $remaining = $this->quantities->subtractExact(ltrim((string) $outflow->meters_delta, '-'),
                (string) ($restored[$outflow->id] ?? '0.000'));
            if ($this->quantities->compareExact($remaining, '0') > 0) {
                $available[$itemId] = $this->quantities->addExact((string) ($available[$itemId] ?? '0.000'), $remaining);
            }
        }

        return $available;
    }

    public function pendingDeliveryMeters(SaleItem $item, string $returned, string $delivered, string $returnedAfterDelivery = '0.000'): string
    {
        // Lo devuelto de un despacho ya está contado como entregado: restarlo otra vez deja compromisos huérfanos.
        $notDeliverable = $this->quantities->subtractExact($returned, $returnedAfterDelivery);
        $pending = $this->quantities->subtractExact(
            $this->quantities->subtractExact((string) $item->meters, $notDeliverable), $delivered);

        return $this->quantities->compareExact($pending, '0') > 0 ? $pending : '0.000';
    }

    public function decrementForSale(Sale $sale, int $userId): void
    {
        $plan = $sale->items->filter(fn (SaleItem $item) => $this->tracksInventory($item->product))
            ->map(fn (SaleItem $item) => [
                'product_id' => (int) $item->product_id,
                'branch_id' => (int) $sale->branch_id,
                'lot_id' => $item->lot_code_snapshot !== null ? (int) $item->product_coil_id : null,
            ])->all();
        if ($plan !== []) {
            $this->lotInventory->lockBatch($plan);
        }
        foreach ($sale->items as $item) {
            if (! $this->tracksInventory($item->product)) {
                continue;
            }

            if ($this->alreadyMoved(SaleItem::class, (int) $item->id, 'sale_stock_out')) {
                continue;
            }

            if ($item->lot_code_snapshot !== null
                && ! $this->lotPolicy->required($item->product, $this->lotPolicy->persistedConfiguration())) {
                throw ValidationException::withMessages(['lot_id' => 'La venta conserva un lote pendiente; reactive el control antes de descontarlo.']);
            }
            if ($this->lotPolicy->required($item->product, $this->lotPolicy->persistedConfiguration())) {
                $commitment = $this->lotReservations->commitmentFor($item);
                $this->lotInventory->apply((int) $item->product_id, (int) $sale->branch_id,
                    (int) $item->product_coil_id, '-'.(string) $item->meters,
                    'sale_stock_out', $item, 'sale-out:'.$item->id, $userId,
                    false, null, $commitment?->id);
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
            'saleItem:id,sale_id,product_id,product_coil_id,meters,lot_code_snapshot',
            'product:id,inventory_tracking_mode,item_type,is_inventory_item,requires_lot',
        ]);

        if (! $this->tracksInventory($deliveryItem->product)) {
            return;
        }

        if ($deliveryItem->saleItem?->lot_code_snapshot !== null
            && ! $this->lotPolicy->required($deliveryItem->product, $this->lotPolicy->persistedConfiguration())) {
            throw ValidationException::withMessages(['lot_id' => 'El despacho conserva un lote pendiente; reactive el control antes de descontarlo.']);
        }
        if ($this->lotPolicy->required($deliveryItem->product, $this->lotPolicy->persistedConfiguration())) {
            $commitment = $this->lotReservations->commitmentFor($deliveryItem->saleItem);
            $this->lotInventory->apply((int) $deliveryItem->product_id,
                (int) $deliveryItem->deliveryNote->branch_id, (int) $deliveryItem->product_coil_id,
                '-'.(string) $deliveryItem->meters, 'delivery_stock_out', $deliveryItem,
                'delivery-out:'.$deliveryItem->id, $userId, false, null, $commitment?->id);
            return;
        }

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
        $managedLotIds = ProductCoil::query()->whereIn('id', $movements->pluck('product_coil_id')->filter())
            ->whereNotNull('lot_code')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $plan = $movements->filter(fn (InventoryMovement $movement) =>
            in_array((int) $movement->product_coil_id, $managedLotIds, true))
            ->map(fn (InventoryMovement $movement) => [
                'product_id' => (int) $movement->product_id,
                'branch_id' => (int) $movement->branch_id,
                'lot_id' => (int) $movement->product_coil_id,
            ])->all();
        if ($plan !== []) {
            $this->lotInventory->lockBatch($plan);
        }

        foreach ($movements as $movement) {
            if ($this->alreadyMoved($movement->source_type, (int) $movement->source_id, 'sale_void_return')) {
                continue;
            }

            $this->returnMovement($movement, $sale, $userId);
        }
    }

    public function consumeReservationsForQuotation(Sale $quotation, Sale $newSale): void
    {
        $quotation->loadMissing('items.product');
        $newSale->loadMissing('items.product');
        $plan = $quotation->items->filter(fn (SaleItem $item) => $item->lot_code_snapshot !== null)
            ->map(fn (SaleItem $item) => [
                'product_id' => (int) $item->product_id,
                'branch_id' => (int) $quotation->branch_id,
                'lot_id' => (int) $item->product_coil_id,
            ])->all();
        if ($plan !== []) {
            $this->lotInventory->lockBatch($plan);
        }
        $reservations = InventoryReservation::query()
            ->where('sale_id', $quotation->id)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->orderBy('id')->lockForUpdate()
            ->get();

        $assignedItems = [];

        foreach ($reservations as $reservation) {
            if ($reservation->product_coil_id && $reservation->sale_item_id) {
                $original = $quotation->items->firstWhere('id', $reservation->sale_item_id);
                $newItem = $newSale->items->first(fn (SaleItem $item) =>
                    $original && ! in_array($item->id, $assignedItems, true)
                    && $item->product_id === $original->product_id
                    && $item->product_coil_id === $original->product_coil_id
                    && $this->quantities->compareExact((string) $item->meters, (string) $original->meters) === 0);
                if (! $newItem) {
                    throw ValidationException::withMessages(['source_quotation_id' => 'La cotización y venta no coinciden por lote.']);
                }
                $assignedItems[] = $newItem->id;
                $this->lotReservations->transferToSale($reservation, $newItem);
                continue;
            }
            if (! $reservation->product_coil_id) {
                $stock = ProductBranchStock::query()
                    ->where('branch_id', $reservation->branch_id)
                    ->where('product_id', $reservation->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock) {
                    $this->quantities->release($stock, (float) $reservation->meters);
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
        $this->lotPolicy->lockRule();
        Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
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

        $managedLots = ProductCoil::query()->where('branch_id', $sale->branch_id)
            ->where('product_id', $item->product_id)->whereNotNull('lot_code')
            ->orderBy('id')->lockForUpdate()->get();
        if ($managedLots->isNotEmpty()) {
            $protected = '0.000';
            foreach ($managedLots as $managedLot) {
                $protected = $this->quantities->addExact($protected, (string) $managedLot->available_meters);
            }
            $untracedReservations = InventoryReservation::query()->where('branch_id', $sale->branch_id)
                ->where('product_id', $item->product_id)->whereNull('product_coil_id')
                ->where('status', InventoryReservation::STATUS_ACTIVE)
                ->orderBy('id')->lockForUpdate()->get();
            $untracedReserved = '0.000';
            foreach ($untracedReservations as $reservation) {
                $untracedReserved = $this->quantities->addExact($untracedReserved, (string) $reservation->remaining_meters);
            }
            $beforeExact = (string) $stock->available_meters;
            $freeUntraced = $this->quantities->subtractExact(
                $this->quantities->subtractExact($beforeExact, $protected), $untracedReserved);
            $amount = $this->quantities->exact((string) $item->meters);
            if ($this->quantities->compareExact($amount, $freeUntraced) > 0) {
                throw ValidationException::withMessages(['items' => 'El saldo libre sin lote no cubre la venta.']);
            }
            $afterExact = $this->quantities->subtractExact($beforeExact, $amount);
            $stock->available_meters = $afterExact;
            $stock->save();
            $this->movement($sale, $item, $userId, $movementType,
                '-'.$amount, $beforeExact, $afterExact, null, $sourceType, $sourceId);
            return;
        }

        $before = $this->quantities->available($stock);
        $reserved = $this->quantities->reserved($stock);
        $after = round($before - (float) $item->meters, 3);

        if (! $allowsNegativeStock && ($after < 0 || $after < $reserved)) {
            throw ValidationException::withMessages([
                'items' => 'La sucursal no tiene stock global libre suficiente para completar la venta.',
            ]);
        }

        $this->quantities->decrease($stock, (float) $item->meters);
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

    private function movement(Sale $sale, SaleItem $item, int $userId, string $type, float|string $delta, float|string $before, float|string $after, ?int $coilId, string $sourceType = SaleItem::class, ?int $sourceId = null): void
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
        if ($movement->product_coil_id && ProductCoil::query()->whereKey($movement->product_coil_id)
            ->whereNotNull('lot_code')->exists()) {
            $restored = (string) InventoryMovement::query()->where('reverses_movement_id', $movement->id)
                ->where('meters_delta', '>', 0)->sum('meters_delta');
            $remaining = $this->quantities->subtractExact(ltrim((string) $movement->meters_delta, '-'), $restored);
            if ($this->quantities->compareExact($remaining, '0') <= 0) {
                return;
            }
            $this->lotInventory->apply((int) $movement->product_id, (int) $movement->branch_id,
                (int) $movement->product_coil_id, $remaining,
                'sale_void_return', $sale, 'sale-void-return:'.$movement->id, $userId,
                true, (int) $movement->id);
            return;
        }
        $quantity = abs($this->quantities->movementQuantity($movement));

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
        [$before, $after] = $this->quantities->increase($stock, $quantity);
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

    private function tracksInventory(?Product $product): bool
    {
        return (bool) ($product?->is_inventory_item ?? true);
    }
}
