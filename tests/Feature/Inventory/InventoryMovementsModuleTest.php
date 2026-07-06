<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function movementUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'kardex-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Kardex central',
        'code' => 'KDX-'.$suffix,
        'barcode' => 'BR-KDX-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function movementProduct(string $sku): Product
{
    return Product::query()->create([
        'name' => 'Producto '.$sku,
        'sku' => $sku,
        'barcode' => 'PR-'.$sku,
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
}

it('lista kardex filtrado por producto y tipo', function () {
    $user = movementUser(['inventory.movements.view']);
    $product = movementProduct('KDX-001');

    InventoryMovement::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'product_coil_id' => null,
        'user_id' => $user->id,
        'type' => 'inventory_adjustment',
        'meters_delta' => 15,
        'meters_before' => 0,
        'meters_after' => 15,
        'reason' => 'Conteo',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('inventory.movements.index', [
            'product_id' => $product->id,
            'type' => 'inventory_adjustment',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Movements/Index', false)
            ->has('movements.data', 1)
            ->where('movements.data.0.type', 'inventory_adjustment')
            ->where('movements.data.0.product.name', 'Producto KDX-001')
        );
});

it('bloquea kardex sin permiso', function () {
    $user = movementUser([]);

    $this->actingAs($user)
        ->get(route('inventory.movements.index'))
        ->assertForbidden();
});
