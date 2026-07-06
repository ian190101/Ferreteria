<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function transferUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'transferencias-inventario-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Transferencias central',
        'code' => 'TR-'.$suffix,
        'barcode' => 'BR-TR-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function transferBranch(string $prefix): Branch
{
    $suffix = uniqid();

    return Branch::query()->create([
        'name' => $prefix.' '.$suffix,
        'code' => strtoupper(substr($prefix, 0, 2)).'-'.$suffix,
        'barcode' => 'BR-'.$prefix.'-'.$suffix,
        'is_active' => true,
    ]);
}

function transferProduct(string $sku, string $trackingMode = Product::TRACKING_GLOBAL): Product
{
    return Product::query()->create([
        'name' => 'Producto '.$sku,
        'sku' => $sku,
        'barcode' => 'PR-'.$sku,
        'inventory_tracking_mode' => $trackingMode,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
}

it('transfiere stock global entre sucursales y registra movimientos de salida y entrada', function () {
    $user = transferUser(['inventory.transfers.view', 'inventory.transfers.manage']);
    $origin = transferBranch('Origen');
    $destination = transferBranch('Destino');
    $product = transferProduct('TR-GLOBAL-'.uniqid());

    ProductBranchStock::query()->create([
        'branch_id' => $origin->id,
        'product_id' => $product->id,
        'available_meters' => 100,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'from_branch_id' => $origin->id,
            'to_branch_id' => $destination->id,
            'product_id' => $product->id,
            'transfer_number' => 'TR-0001-'.uniqid(),
            'meters' => 40,
            'transferred_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Reposicion sucursal',
            'notes' => null,
        ])
        ->assertRedirect(route('inventory.transfers.index'));

    $sourceStock = ProductBranchStock::query()->where('branch_id', $origin->id)->where('product_id', $product->id)->first();
    $destinationStock = ProductBranchStock::query()->where('branch_id', $destination->id)->where('product_id', $product->id)->first();

    expect((float) $sourceStock->available_meters)->toBe(60.0)
        ->and((float) $destinationStock->available_meters)->toBe(40.0)
        ->and(InventoryTransfer::query()->where('product_id', $product->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'transfer_out_global')->where('product_id', $product->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'transfer_in_global')->where('product_id', $product->id)->exists())->toBeTrue();
});

it('bloquea transferencias globales sin stock suficiente', function () {
    $user = transferUser(['inventory.transfers.view', 'inventory.transfers.manage']);
    $origin = transferBranch('Origen');
    $destination = transferBranch('Destino');
    $product = transferProduct('TR-BLOCK-'.uniqid());

    ProductBranchStock::query()->create([
        'branch_id' => $origin->id,
        'product_id' => $product->id,
        'available_meters' => 5,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('inventory.transfers.index'))
        ->post(route('inventory.transfers.store'), [
            'from_branch_id' => $origin->id,
            'to_branch_id' => $destination->id,
            'product_id' => $product->id,
            'transfer_number' => 'TR-0002-'.uniqid(),
            'meters' => 10,
            'transferred_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Reposicion sucursal',
        ])
        ->assertRedirect(route('inventory.transfers.index'))
        ->assertSessionHasErrors('meters');
});

it('transfiere una bobina completa entre sucursales', function () {
    $user = transferUser(['inventory.transfers.view', 'inventory.transfers.manage']);
    $origin = transferBranch('Origen');
    $destination = transferBranch('Destino');
    $product = transferProduct('TR-COIL-'.uniqid(), Product::TRACKING_COIL);
    $coil = ProductCoil::query()->create([
        'branch_id' => $origin->id,
        'product_id' => $product->id,
        'barcode' => 'COIL-TR-'.uniqid(),
        'lot_number' => 'LOT-TR-'.uniqid(),
        'initial_meters' => 25,
        'available_meters' => 25,
        'initial_kg' => 10,
        'status' => 'available',
    ]);

    $this->actingAs($user)
        ->post(route('inventory.transfers.store'), [
            'from_branch_id' => $origin->id,
            'to_branch_id' => $destination->id,
            'product_id' => $product->id,
            'product_coil_id' => $coil->id,
            'transfer_number' => 'TR-0003-'.uniqid(),
            'meters' => 25,
            'transferred_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Movimiento de bobina',
        ])
        ->assertRedirect(route('inventory.transfers.index'));

    $coil->refresh();

    expect($coil->branch_id)->toBe($destination->id)
        ->and(InventoryMovement::query()->where('type', 'transfer_out_coil')->where('product_coil_id', $coil->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'transfer_in_coil')->where('product_coil_id', $coil->id)->exists())->toBeTrue();
});

it('lista transferencias con permiso y bloquea sin permiso', function () {
    $user = transferUser(['inventory.transfers.view']);

    $this->actingAs($user)
        ->get(route('inventory.transfers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Transfers/Index', false)
            ->has('transfers.data')
        );

    $blocked = transferUser([]);

    $this->actingAs($blocked)
        ->get(route('inventory.transfers.index'))
        ->assertForbidden();
});
