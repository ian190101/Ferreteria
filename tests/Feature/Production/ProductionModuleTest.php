<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Production\Models\ProductionOrder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function productionUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'produccion-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Produccion central',
        'code' => 'PROD-'.$suffix,
        'barcode' => 'BR-PROD-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function productionProduct(string $sku, string $name, string $mode = Product::TRACKING_GLOBAL): Product
{
    return Product::query()->create([
        'name' => $name,
        'sku' => $sku,
        'barcode' => 'PR-'.$sku,
        'inventory_tracking_mode' => $mode,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
}

it('registra produccion global consumiendo entrada y aumentando salida', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-001', 'Materia prima');
    $output = productionProduct('TERM-001', 'Producto terminado');

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $input->id,
        'available_meters' => 100,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('production.store'), [
            'branch_id' => $user->branch_id,
            'order_number' => 'OP-001',
            'produced_at' => now()->format('Y-m-d H:i:s'),
            'input_product_id' => $input->id,
            'input_product_coil_id' => null,
            'output_product_id' => $output->id,
            'input_meters' => 40,
            'output_meters' => 35,
            'waste_meters' => 5,
            'output_coil_barcode' => null,
            'output_lot_number' => null,
            'notes' => 'Prueba de produccion',
        ])
        ->assertRedirect(route('production.index'));

    expect((float) ProductBranchStock::query()->where('product_id', $input->id)->value('available_meters'))->toBe(60.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $output->id)->value('available_meters'))->toBe(35.0)
        ->and(ProductionOrder::query()->where('order_number', 'OP-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'production_input_global')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'production_output_global')->exists())->toBeTrue();
});

it('bloquea produccion cuando no hay stock global suficiente', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-002', 'Materia corta');
    $output = productionProduct('TERM-002', 'Salida corta');

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $input->id,
        'available_meters' => 5,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('production.index'))
        ->post(route('production.store'), [
            'branch_id' => $user->branch_id,
            'order_number' => 'OP-002',
            'produced_at' => now()->format('Y-m-d H:i:s'),
            'input_product_id' => $input->id,
            'output_product_id' => $output->id,
            'input_meters' => 10,
            'output_meters' => 9,
            'waste_meters' => 1,
        ])
        ->assertRedirect(route('production.index'))
        ->assertSessionHasErrors('input_meters');
});

it('lista produccion con permiso y bloquea sin permiso', function () {
    $user = productionUser(['production.view']);

    $this->actingAs($user)
        ->get(route('production.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Production/Index', false)
            ->has('orders.data', 0)
        );

    $blocked = productionUser([]);

    $this->actingAs($blocked)
        ->get(route('production.index'))
        ->assertForbidden();
});
