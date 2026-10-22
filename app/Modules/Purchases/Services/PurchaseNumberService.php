<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

final class PurchaseNumberService
{
    public function next(int $branchId): string
    {
        return DB::transaction(function () use ($branchId): string {
            // Serializa también la primera compra, cuando todavía no existe el correlativo.
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();

            $sequence = DocumentSequence::query()->firstOrCreate([
                'branch_id' => $branchId,
                'document_type' => 'purchase',
            ], [
                'name' => 'Compras',
                'prefix' => "COMP-{$branchId}-",
                'next_number' => 1,
                'padding' => 6,
                'is_active' => true,
            ]);
            $sequence = DocumentSequence::query()->whereKey($sequence->id)->lockForUpdate()->firstOrFail();

            do {
                $number = $sequence->preview();
                $sequence->next_number++;
                // Conserva los números históricos, incluso de compras anuladas o eliminadas.
            } while (Purchase::withTrashed()->where('document_number', $number)->exists());

            $sequence->save();

            return $number;
        });
    }
}
