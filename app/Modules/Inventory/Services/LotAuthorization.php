<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\ProductCoil;
use App\Support\BranchAccess;
use App\Support\SystemRoles;

class LotAuthorization
{
    public function canManage(User $user, ProductCoil $lot): bool
    {
        return $this->isAdministrator($user) && BranchAccess::canAccess($user, (int) $lot->branch_id);
    }

    public function isAdministrator(User $user): bool
    {
        return $user->hasAnyRole([SystemRoles::BUSINESS_ADMIN, SystemRoles::SYSTEM_SUPERADMIN]);
    }

    public function assertManage(User $user, ProductCoil $lot): void
    {
        abort_unless($this->canManage($user, $lot), 403);
    }
}
