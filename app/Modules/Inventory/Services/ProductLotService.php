<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\OperationalCacheOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductLotService
{
    public function __construct(
        private readonly LotAuthorization $authorization,
        private readonly LotTrackingPolicy $policy,
        private readonly LotInventoryService $inventory,
        private readonly InventoryQuantityService $quantity,
        private readonly OperationalCacheOutbox $outbox,
    ) {}

    public function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (strlen($code) > 80 || ! preg_match('/^[A-Z0-9-]+$/D', $code)
            || ! preg_match('/[A-Z0-9]/', $code)) {
            throw ValidationException::withMessages(['lot_code' => 'Usa de 1 a 80 letras, números o guiones; el código no puede contener solo guiones.']);
        }

        return $code;
    }

    public function registerCodes(User $actor, Product $product, array $rows): void
    {
        abort_unless($actor->can('inventory.products.manage'), 403);

        DB::transaction(function () use ($actor, $product, $rows): void {
            $this->policy->lockRule();
            $configuration = $this->policy->persistedConfiguration();
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $product->is_active || ! $this->policy->required($product, $configuration)) {
                throw ValidationException::withMessages(['lot_registrations' => 'Activa el control de lotes para este producto inventariable antes de registrar códigos.']);
            }
            $entries = [];
            foreach ($rows as $index => $row) {
                $branchId = (int) $row['branch_id'];
                abort_unless(\App\Support\BranchAccess::canAccess($actor, $branchId), 403);
                $entries[] = ['product_id' => $product->id, 'branch_id' => $branchId, 'index' => $index, ...$row];
            }
            $this->inventory->lockBatch($entries);
            usort($entries, fn (array $left, array $right) => [$left['branch_id'], $left['lot_code']] <=> [$right['branch_id'], $right['lot_code']]);
            $product->load('thickness');
            foreach ($entries as $entry) {
                $key = 'lot_registrations.'.$entry['index'];
                if (! ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $entry['branch_id'])
                    ->where('is_enabled', true)->whereHas('branch', fn ($query) => $query->where('is_active', true))->exists()) {
                    throw ValidationException::withMessages(['lot_registrations.'.$entry['index'].'.branch_id' => 'Habilita el producto en esta sucursal antes de registrar sus lotes.']);
                }
                try {
                    $meters = filled($entry['initial_meters'] ?? null)
                        ? $this->quantity->exact((string) $entry['initial_meters'])
                        : $this->quantity->metersFromWeightExact((string) ($entry['initial_kg'] ?? '0'),
                            $product->thickness?->kg_per_meter, $product->thickness?->kg_to_meter_factor);
                    $kg = filled($entry['initial_kg'] ?? null) ? $this->quantity->exact((string) $entry['initial_kg']) : null;
                    if ($this->quantity->compareExact($meters, '0') < 0 || ($kg !== null && $this->quantity->compareExact($kg, '0') < 0)) {
                        throw new \InvalidArgumentException('Los metros y el peso inicial no pueden ser negativos.');
                    }
                    $lot = $this->create($product, (int) $entry['branch_id'], $entry['lot_code'], $entry['barcode'] ?? null, $entry['notes'] ?? null, $kg);
                    if ($this->quantity->compareExact($meters, '0') > 0) {
                        $this->inventory->apply($product->id, (int) $entry['branch_id'], $lot->id, $meters,
                            'initial_lot_entry', $lot, 'initial-lot:'.$lot->id, $actor->id);
                    }
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$key.'.lot_code' => collect($exception->errors())->flatten()->all()]);
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$key.'.initial_meters' => $exception->getMessage()]);
                }
            }
        }, 3);
    }

    public function create(Product $product, int $branchId, string $code, ?string $barcode = null, ?string $notes = null, ?string $initialKg = null): ProductCoil
    {
        $this->policy->lockRule();
        $configuration = $this->policy->persistedConfiguration();
        $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        if (! $this->policy->required($product, $configuration)) {
            throw ValidationException::withMessages(['new_lot_code' => 'El producto no tiene control de lotes activo.']);
        }
        $code = $this->normalizeCode($code);
        if (ProductCoil::withTrashed()->where('product_id', $product->id)->where('branch_id', $branchId)
            ->where('lot_code', $code)->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages(['new_lot_code' => 'El código ya existe para este producto y sucursal.']);
        }

        try {
            $lot = ProductCoil::query()->create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'barcode' => $barcode,
                'lot_code' => $code,
                'lot_number' => $code,
                'notes' => $notes,
                'initial_kg' => $initialKg,
                'initial_meters' => '0.000',
                'available_meters' => '0.000',
                'status' => 'depleted',
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains($exception->getMessage(), 'coils_product_branch_lot_code_uq')) {
                throw ValidationException::withMessages(['new_lot_code' => 'El código ya existe para este producto y sucursal.']);
            }
            throw $exception;
        }
        $this->outbox->schedule('lot_inventory');

        return $lot;
    }

    public function update(User $actor, Product $product, ProductCoil $lot, string $code, ?string $notes): ProductCoil
    {
        $this->authorization->assertManage($actor, $lot);

        return DB::transaction(function () use ($actor, $product, $lot, $code, $notes): ProductCoil {
            $this->policy->lockRule();
            $this->policy->persistedConfiguration();
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lot = ProductCoil::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertManage($actor, $lot);
            $this->assertEditable($product, $lot);
            $code = $this->normalizeCode($code);
            if (ProductCoil::withTrashed()->where('product_id', $product->id)
                ->where('branch_id', $lot->branch_id)->where('lot_code', $code)
                ->whereKeyNot($lot->id)->lockForUpdate()->first(['id'])) {
                throw ValidationException::withMessages(['lot_code' => 'El código ya está registrado.']);
            }
            $lot->lot_code = $code;
            $lot->lot_number = $code;
            $lot->notes = $notes;
            try {
                $lot->save();
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                    && str_contains($exception->getMessage(), 'coils_product_branch_lot_code_uq')) {
                    throw ValidationException::withMessages(['lot_code' => 'El código ya está registrado.']);
                }
                throw $exception;
            }
            $this->outbox->schedule('lot_inventory');

            return $lot->refresh();
        }, 3);
    }

    public function void(User $actor, Product $product, ProductCoil $lot, string $reason): ProductCoil
    {
        $this->authorization->assertManage($actor, $lot);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Indica el motivo de anulación.']);
        }

        return DB::transaction(function () use ($actor, $product, $lot, $reason): ProductCoil {
            $this->policy->lockRule();
            $this->policy->persistedConfiguration();
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $stock = ProductBranchStock::query()->where('branch_id', $lot->branch_id)
                ->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $lot = ProductCoil::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertManage($actor, $lot);
            $this->assertEditable($product, $lot);
            $reservations = InventoryReservation::query()->where('product_coil_id', $lot->id)
                ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
            if ($reservations->contains(fn (InventoryReservation $row) => $this->quantity->compareExact((string) $row->remaining_meters, '0') > 0)) {
                throw ValidationException::withMessages(['lot_id' => 'El lote conserva reservas o compromisos activos.']);
            }

            $amount = (string) $lot->available_meters;
            if ($this->quantity->compareExact($amount, '0') > 0) {
                if ($this->quantity->compareExact((string) $stock->available_meters, $amount) < 0) {
                    throw ValidationException::withMessages(['lot_id' => 'El saldo general no cubre la anulación del lote.']);
                }
                $adjustment = InventoryAdjustment::query()->create([
                    'branch_id' => $lot->branch_id,
                    'product_id' => $product->id,
                    'product_coil_id' => $lot->id,
                    'user_id' => $actor->id,
                    'adjustment_number' => 'LOT-VOID-'.$lot->id,
                    'type' => InventoryAdjustment::TYPE_DECREASE,
                    'meters_delta' => '-'.$amount,
                    'meters_before' => $stock->available_meters,
                    'meters_after' => $this->quantity->subtractExact((string) $stock->available_meters, $amount),
                    'reason' => $reason,
                    'adjusted_at' => now(),
                ]);
                $this->inventory->apply($product->id, $lot->branch_id, $lot->id, '-'.$amount,
                    'lot_void_adjustment', $adjustment, 'lot-void:'.$lot->id, $actor->id);
            }

            $lot->voided_at = now();
            $lot->voided_by = $actor->id;
            $lot->void_reason = $reason;
            $lot->status = 'voided';
            $lot->save();
            $this->outbox->schedule('lot_inventory');

            return $lot->refresh();
        }, 3);
    }

    private function assertEditable(Product $product, ProductCoil $lot): void
    {
        if ($lot->product_id !== $product->id || $lot->isVoided() || $lot->trashed() || $lot->lot_code === null) {
            throw ValidationException::withMessages(['lot_id' => 'El lote no puede modificarse.']);
        }
    }
}
