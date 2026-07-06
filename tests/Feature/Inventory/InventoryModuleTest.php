<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\Thickness;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function inventoryUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'inventario-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Central',
        'code' => 'CENTRAL',
        'barcode' => 'BR-CENTRAL',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea productos y genera stock por sucursal activa', function () {
    $user = inventoryUser(['inventory.products.view', 'inventory.products.manage']);
    $thickness = Thickness::query()->create([
        'name' => '0.50 mm',
        'millimeters' => 0.5,
        'kg_to_meter_factor' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('inventory.products.store'), [
            'thickness_id' => $thickness->id,
            'name' => 'Polietileno 0.50',
            'sku' => 'PE-050',
            'barcode' => 'PR-PE-050',
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'default_width' => 1.2,
            'minimum_stock_meters' => 50,
            'is_active' => true,
        ])
        ->assertRedirect(route('inventory.products.index'));

    $product = Product::query()->where('sku', 'PE-050')->firstOrFail();

    expect(ProductBranchStock::query()
        ->where('product_id', $product->id)
        ->where('branch_id', $user->branch_id)
        ->exists())->toBeTrue();
});

it('registra bobinas solo para productos con rastreo individual', function () {
    $user = inventoryUser(['inventory.coils.manage']);
    $product = Product::query()->create([
        'name' => 'Bobina trazable',
        'sku' => 'BOB-001',
        'barcode' => 'PR-BOB-001',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('inventory.coils.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'barcode' => 'COIL-001',
            'lot_number' => 'LOT-001',
            'initial_kg' => 12.5,
            'initial_meters' => 125,
        ])
        ->assertRedirect(route('inventory.coils.index'));

    expect(ProductCoil::query()->where('barcode', 'COIL-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'coil_entry')->exists())->toBeTrue();
});
