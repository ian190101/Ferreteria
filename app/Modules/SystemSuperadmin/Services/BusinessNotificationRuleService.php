<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\NotificationRule;
use Illuminate\Support\Collection;

class BusinessNotificationRuleService
{
    /**
     * @return Collection<int, NotificationRule>
     */
    public function activeFor(string $trigger): Collection
    {
        return NotificationRule::query()
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
