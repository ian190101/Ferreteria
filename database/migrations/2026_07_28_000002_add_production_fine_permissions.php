<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'sales.void',
            'sales.discounts.override',
            'products.costs.view',
            'purchases.costs.view',
            'payments.void',
            'exports.sensitive',
            'production.updates.manage',
            'production.rollback.manage',
            'production.alerts.manage',
            'setup.wizard.manage',
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', ['superadmin', 'sistemasuperadmin'])->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissions = [
            'sales.void',
            'sales.discounts.override',
            'products.costs.view',
            'purchases.costs.view',
            'payments.void',
            'exports.sensitive',
            'production.updates.manage',
            'production.rollback.manage',
            'production.alerts.manage',
            'setup.wizard.manage',
        ];

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
