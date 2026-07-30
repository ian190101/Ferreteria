<?php

namespace App\Modules\ServiceOrders\Services;

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use Illuminate\Validation\ValidationException;

class ServiceOrderInventoryService
{
    public function __construct(
        private readonly InventoryQuantityService $quantities,
        private readonly ServiceOrderWorkflowPolicy $policy,
    ) {}

    public function consumeMaterials(ServiceOrder $order, int $userId): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items->where('discount_inventory', true) as $item) {
            $quantity = (float) $item->quantity;
            if ($quantity <= 0) {
                continue;
            }

            $stock = ProductBranchStock::query()
                ->where('branch_id', $order->branch_id)
                ->where('product_id', $item->product_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                if (! $this->policy->allowsNegativeStock()) {
                    throw ValidationException::withMessages([
                        'items' => "No hay stock configurado para {$item->product?->name}.",
                    ]);
                }

                $stock = ProductBranchStock::query()->create([
                    'branch_id' => $order->branch_id,
                    'product_id' => $item->product_id,
                    'available_meters' => 0,
                    'reserved_meters' => 0,
                    'is_enabled' => true,
                ]);
                $stock = ProductBranchStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            }

            if (! $this->policy->allowsNegativeStock() && $this->quantities->free($stock) < $quantity) {
                throw ValidationException::withMessages([
                    'items' => "No hay stock suficiente de {$item->product?->name} para esta orden de servicio.",
                ]);
            }

            [$before, $after] = $this->quantities->decrease($stock, $quantity);

            InventoryMovement::query()->create([
                'branch_id' => $order->branch_id,
                'product_id' => $item->product_id,
                'product_coil_id' => null,
                'user_id' => $userId,
                'source_type' => $order::class,
                'source_id' => $order->id,
                'type' => 'service_order_material_consumption',
                'meters_delta' => -$quantity,
                'meters_before' => $before,
                'meters_after' => $after,
                'reason' => "Material usado en orden {$order->order_number}",
                'created_at' => now(),
            ]);
        }
    }
}
