<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\PrinterProfile;

class BusinessPrinterProfileService
{
    public function resolve(string $area, ?int $branchId = null): ?PrinterProfile
    {
        return PrinterProfile::query()
            ->where('area', $area)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->latest('updated_at')
            ->first();
    }
}
