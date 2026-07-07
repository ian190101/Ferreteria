<?php

namespace App\Modules\Cash\Support;

use App\Models\User;
use App\Modules\Cash\Models\CashRegisterSession;

class CashSessionGuard
{
    public static function requiresOpenSession(User $user, int $branchId): bool
    {
        return ! $user->isSuperAdministrator()
            && ! self::hasOpenSession($user, $branchId);
    }

    public static function hasOpenSession(User $user, int $branchId): bool
    {
        return CashRegisterSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $user->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();
    }

    public static function message(): string
    {
        return 'Debes abrir caja en esta sucursal antes de registrar ventas o cobros.';
    }
}
