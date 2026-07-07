<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_price', 18, 4)->default(0)->after('default_width');
            $table->decimal('sale_price', 18, 4)->default(0)->after('purchase_price');
        });

        DB::statement('
            UPDATE products
            SET sale_price = COALESCE((
                SELECT sale_items.unit_price
                FROM sale_items
                WHERE sale_items.product_id = products.id
                ORDER BY sale_items.id DESC
                LIMIT 1
            ), 0)
        ');

        DB::statement('
            UPDATE products
            SET purchase_price = COALESCE((
                SELECT purchase_order_items.unit_cost
                FROM purchase_order_items
                WHERE purchase_order_items.product_id = products.id
                ORDER BY purchase_order_items.id DESC
                LIMIT 1
            ), 0)
        ');

        DB::table('permissions')->updateOrInsert(
            ['name' => 'sales.prices.override', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $permissionId = DB::table('permissions')
            ->where('name', 'sales.prices.override')
            ->where('guard_name', 'web')
            ->value('id');
        $superadminRoleId = DB::table('roles')
            ->where('name', 'superadmin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId && $superadminRoleId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $superadminRoleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'sales.prices.override')->pluck('id'))
            ->delete();
        DB::table('permissions')->where('name', 'sales.prices.override')->delete();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'sale_price']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
