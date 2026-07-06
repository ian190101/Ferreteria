<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function adjustmentUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'ajustes-inventario-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Ajustes central',
        'code' => 'AJ-'.$suffix,
        'barcode' => 'BR-AJ-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function adjustmentProduct(string $sku): Product
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

it('registra aumento de stock global con movimiento trazable', function () {
    $user = adjustmentUser(['inventory.adjustments.view', 'inventory.adjustments.manage']);
    $product = adjustmentProduct('AJ-001');

    $this->actingAs($user)
        ->post(route('inventory.adjustments.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'product_coil_id' => null,
            'adjustment_number' => 'AJ-0001',
            'type' => 'increase',
            'meters' => 25,
            'reason' => 'Conteo fisico',
            'adjusted_at' => now()->format('Y-m-d H:i:s'),
            'notes' => null,
        ])
        ->assertRedirect(route('inventory.adjustments.index'));

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(25.0)
        ->and(InventoryAdjustment::query()->where('adjustment_number', 'AJ-0001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'inventory_adjustment')->exists())->toBeTrue();
});

it('bloquea disminucion que deja stock global negativo', function () {
    $user = adjustmentUser(['inventory.adjustments.view', 'inventory.adjustments.manage']);
    $product = adjustmentProduct('AJ-002');

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 5,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('inventory.adjustments.index'))
        ->post(route('inventory.adjustments.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'adjustment_number' => 'AJ-0002',
            'type' => 'decrease',
            'meters' => 10,
            'reason' => 'Merma',
            'adjusted_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('inventory.adjustments.index'))
        ->assertSessionHasErrors('meters');
});

it('lista ajustes con permiso y bloquea sin permiso', function () {
    $user = adjustmentUser(['inventory.adjustments.view']);

    $this->actingAs($user)
        ->get(route('inventory.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Adjustments/Index', false)
            ->has('adjustments.data', 0)
        );

    $blocked = adjustmentUser([]);

    $this->actingAs($blocked)
        ->get(route('inventory.adjustments.index'))
        ->assertForbidden();
});
