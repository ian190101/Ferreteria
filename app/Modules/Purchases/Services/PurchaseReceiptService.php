<?php

namespace App\Modules\Purchases\Services;

use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\Inventory\Services\ProductLotService;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptService
{
    public function __construct(
        private readonly LotTrackingPolicy $policy,
        private readonly ProductLotService $lots,
        private readonly LotInventoryService $inventory,
        private readonly InventoryQuantityService $quantities,
    ) {}

    public function orderQuantity(PurchaseOrderItem $item, array $input): string
    {
        if (filled($input['meters'] ?? null)) {
            return $this->quantities->exact((string) $input['meters']);
        }
        $kilograms = $this->quantities->multiplyExact((string) ($input['kilograms'] ?? '0'),
            ($input['weight_unit'] ?? 'kg') === 'ton' ? '1000' : '1');
        $item->product->loadMissing('thickness');

        return $this->quantities->metersFromWeightExact($kilograms,
            $item->product->thickness?->kg_per_meter, $item->product->thickness?->kg_to_meter_factor);
    }

    public function receiveManaged(PurchaseItem $item, array $input, int $branchId, int $userId): ProductCoil
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La recepción requiere una transacción abierta.');
        }
        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $product = $item->product;
        if (! $this->policy->required($product, $configuration)) {
            throw ValidationException::withMessages(['lot_id' => 'El producto no tiene control de lotes activo.']);
        }

        $lotId = $input['lot_id'] ?? null;
        $newCode = $input['new_lot_code'] ?? null;
        if (($lotId === null) === blank($newCode)) {
            throw ValidationException::withMessages(['lot_id' => 'Elige un lote existente o indica un código nuevo.']);
        }

        if ($lotId !== null) {
            if (filled($input['coil_barcode'] ?? null)) {
                throw ValidationException::withMessages(['coil_barcode' => 'Una bobina nueva requiere un lote y código nuevos.']);
            }
            $lot = ProductCoil::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
            if ($lot->product_id !== $product->id || $lot->branch_id !== $branchId
                || $lot->isVoided() || $lot->trashed() || $lot->lot_code === null) {
                throw ValidationException::withMessages(['lot_id' => 'El lote no corresponde al producto y sucursal, o está anulado.']);
            }
        } else {
            $lot = $this->lots->create($product, $branchId, (string) $newCode,
                $input['coil_barcode'] ?? null, null, $item->kilograms === null ? null : (string) $item->kilograms);
        }

        $item->product_coil_id = $lot->id;
        $item->lot_number = $lot->lot_code;
        $item->coil_barcode = $lot->barcode;
        $item->save();

        $this->inventory->apply($product->id, $branchId, $lot->id, (string) $item->meters,
            'purchase_lot_entry', $item, 'purchase-in:'.$item->id, $userId);

        return $lot->refresh();
    }
}
