<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\PriceList;
use Illuminate\Support\Collection;

class BusinessPriceListService
{
    /**
     * @return Collection<int, PriceList>
     */
    public function activeFor(string $channel, ?int $branchId = null, ?int $customerId = null): Collection
    {
        return PriceList::query()
            ->where('channel', $channel)
            ->where('is_active', true)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->where(function ($query) use ($customerId) {
                $query->whereNull('customer_id')->orWhere('customer_id', $customerId);
            })
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByRaw('case when customer_id is null then 1 else 0 end')
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->latest('updated_at')
            ->get();
    }
}
