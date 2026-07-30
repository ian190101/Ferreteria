<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;

class BusinessLicensePolicyService
{
    public function moduleEnabled(string $module): bool
    {
        $license = BusinessModuleLicense::query()
            ->where('module', $module)
            ->first();

        if (! $license) {
            return true;
        }

        if (! $license->is_enabled) {
            return false;
        }

        return blank($license->support_until) || $license->support_until->endOfDay()->isFuture();
    }
}
