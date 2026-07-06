<?php

namespace App\Support;

use App\Models\User;

class BranchAccess
{
    /**
     * @return array<int>
     */
    public static function idsFor(User $user): array
    {
        return $user->isSuperAdministrator()
            ? []
            : ($user->accessibleBranchIds() ?: [-1]);
    }

    public static function canAccess(User $user, ?int $branchId): bool
    {
        if (! $branchId) {
            return false;
        }

        return $user->isSuperAdministrator()
            || in_array($branchId, $user->accessibleBranchIds(), true);
    }

    public static function apply($query, User $user, string $column = 'branch_id')
    {
        if ($user->isSuperAdministrator()) {
            return $query;
        }

        return $query->whereIn($column, $user->accessibleBranchIds() ?: [-1]);
    }

    public static function validate(User $user, ?int $branchId, string $message = 'No tienes acceso a esta sucursal.'): ?string
    {
        return self::canAccess($user, $branchId) ? null : $message;
    }
}
