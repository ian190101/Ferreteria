<?php

namespace App\Support;

use App\Models\User;

class UserHomeRoute
{
    public static function nameFor(?User $user): string
    {
        if ($user && AuthSessionCache::isSystemSuperadmin($user)) {
            return 'system-superadmin.business-profiles.index';
        }

        $routesByPermission = [
            'dashboard.view' => 'dashboard',
            'cash.view' => 'cash.index',
            'sales.view' => 'sales.index',
            'payments.view' => 'payments.index',
            'inventory.products.view' => 'inventory.products.index',
            'purchases.view' => 'purchases.index',
            'customers.view' => 'customers.index',
            'reports.view' => 'reports.index',
        ];

        $permissions = $user ? AuthSessionCache::permissionNamesFor($user) : [];

        foreach ($routesByPermission as $permission => $route) {
            if (in_array($permission, $permissions, true)) {
                return $route;
            }
        }

        return 'profile.edit';
    }

    public static function pathFor(?User $user): string
    {
        return route(self::nameFor($user), absolute: false);
    }
}
