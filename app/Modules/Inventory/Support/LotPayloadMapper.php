<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\ProductCoil;
use Illuminate\Validation\ValidationException;

class LotPayloadMapper
{
    public function toDatabase(array $payload): array
    {
        $lotId = $payload['lot_id'] ?? null;
        $coilId = $payload['product_coil_id'] ?? null;
        if ($lotId !== null && $coilId !== null && (int) $lotId !== (int) $coilId) {
            throw ValidationException::withMessages(['lot_id' => 'Las identidades del lote no coinciden.']);
        }

        unset($payload['lot_id']);
        $payload['product_coil_id'] = $lotId ?? $coilId;

        return $payload;
    }

    public function serialize(ProductCoil $lot): array
    {
        return [
            'lot_id' => $lot->id,
            'lot_code' => $lot->lot_code,
            'product_id' => $lot->product_id,
            'branch_id' => $lot->branch_id,
            'physical' => $lot->available_meters,
            'voided_at' => $lot->voided_at?->toIso8601String(),
        ];
    }
}
