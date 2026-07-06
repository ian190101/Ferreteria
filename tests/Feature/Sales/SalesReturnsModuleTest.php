<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnItem;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function saleReturnsUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'devoluciones-venta-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Sucursal devoluciones',
        'code' => 'DEV-'.$suffix,
        'barcode' => 'BR-DEV-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function saleReturnsProduct(string $sku, string $trackingMode = Product::TRACKING_GLOBAL): Product
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

function saleReturnsSale(User $user, Product $product, ?ProductCoil $coil = null, float $meters = 10): Sale
{
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente devolucion',
        'sold_at' => now(),
        'subtotal' => $meters * 20,
        'discount_total' => 0,
        'total' => $meters * 20,
        'balance_due' => $meters * 20,
        'status' => 'issued',
    ]);

    $sale->items()->create([
        'product_id' => $product->id,
        'product_coil_id' => $coil?->id,
        'description' => $product->name,
        'unit_label' => 'M',
        'meters' => $meters,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => $meters * 20,
    ]);

    return $sale->load('items');
}

it('registra devolucion global y reingresa inventario con kardex', function () {
    $user = saleReturnsUser(['sales.returns.view', 'sales.returns.manage']);
    $product = saleReturnsProduct('DEV-GLOBAL-001');
    $sale = saleReturnsSale($user, $product, null, 10);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 90,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('sales.returns.store'), [
            'sale_id' => $sale->id,
            'return_number' => 'DEV-0001',
            'returned_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Cambio solicitado',
            'notes' => null,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 4],
            ],
        ])
        ->assertRedirect(route('sales.returns.index'));

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(94.0)
        ->and((float) SaleReturn::query()->where('return_number', 'DEV-0001')->value('total_amount'))->toBe(80.0)
        ->and((float) SaleReturnItem::query()->where('sale_item_id', $sale->items->first()->id)->value('meters'))->toBe(4.0)
        ->and(InventoryMovement::query()->where('type', 'sale_return')->where('meters_delta', 4)->exists())->toBeTrue();
});

it('bloquea devolucion mayor al metraje restante vendido', function () {
    $user = saleReturnsUser(['sales.returns.view', 'sales.returns.manage']);
    $product = saleReturnsProduct('DEV-BLOCK-001');
    $sale = saleReturnsSale($user, $product, null, 10);
    $previousReturn = SaleReturn::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'return_number' => 'DEV-PREVIA',
        'returned_at' => now(),
        'total_amount' => 160,
        'reason' => 'Parcial',
    ]);
    $previousReturn->items()->create([
        'sale_item_id' => $sale->items->first()->id,
        'product_id' => $product->id,
        'meters' => 8,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => 160,
    ]);

    $this->actingAs($user)
        ->from(route('sales.returns.index'))
        ->post(route('sales.returns.store'), [
            'sale_id' => $sale->id,
            'return_number' => 'DEV-0002',
            'returned_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Exceso',
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 3],
            ],
        ])
        ->assertRedirect(route('sales.returns.index'))
        ->assertSessionHasErrors('items');
});

it('registra devolucion a bobina y actualiza el metraje fisico', function () {
    $user = saleReturnsUser(['sales.returns.view', 'sales.returns.manage']);
    $product = saleReturnsProduct('DEV-COIL-001', Product::TRACKING_COIL);
    $coil = ProductCoil::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'barcode' => 'COIL-DEV-001',
        'lot_number' => 'L-DEV-001',
        'initial_meters' => 15,
        'available_meters' => 5,
        'status' => 'available',
    ]);
    $sale = saleReturnsSale($user, $product, $coil, 10);

    $this->actingAs($user)
        ->post(route('sales.returns.store'), [
            'sale_id' => $sale->id,
            'return_number' => 'DEV-0003',
            'returned_at' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Material sobrante',
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 2],
            ],
        ])
        ->assertRedirect(route('sales.returns.index'));

    expect((float) $coil->fresh()->available_meters)->toBe(7.0)
        ->and($coil->fresh()->status)->toBe('available')
        ->and(InventoryMovement::query()->where('type', 'sale_return')->where('product_coil_id', $coil->id)->exists())->toBeTrue();
});

it('lista devoluciones con permiso y bloquea sin permiso', function () {
    $user = saleReturnsUser(['sales.returns.view']);

    $this->actingAs($user)
        ->get(route('sales.returns.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Returns/Index', false)
            ->has('returns.data', 0)
        );

    $blocked = saleReturnsUser([]);

    $this->actingAs($blocked)
        ->get(route('sales.returns.index'))
        ->assertForbidden();
});
