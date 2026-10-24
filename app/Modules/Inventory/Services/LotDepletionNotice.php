<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\Sale;

class LotDepletionNotice
{
    public function forLots(array $ids): ?string
    {
        $codes = ProductCoil::query()->whereIn('id', $ids)->whereNotNull('lot_code')
            ->whereNull('voided_at')->where('status', 'depleted')->where('available_meters', '=', '0.000')
            ->orderBy('id')->pluck('lot_code');

        return $codes->isEmpty() ? null : 'Atención: lote en 0 (Agotado): '.$codes->implode(', ').'. Selecciona otro lote para la próxima salida.';
    }

    public function forSale(Sale $sale): ?string
    {
        if ($sale->document_type !== 'sale_note') return null;

        return $this->forLots($sale->items()->whereNotNull('product_coil_id')->pluck('product_coil_id')->all());
    }
}
