<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function deliveriesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'despachos-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Sucursal despachos',
        'code' => 'DES-'.$suffix,
        'barcode' => 'BR-DES-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function deliveryProduct(string $sku): Product
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

function deliverySale(User $user, Product $product, float $meters = 10): Sale
{
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente despacho',
        'sold_at' => now(),
        'subtotal' => $meters * 20,
        'discount_total' => 0,
        'total' => $meters * 20,
        'balance_due' => $meters * 20,
        'status' => 'issued',
    ]);

    $sale->items()->create([
        'product_id' => $product->id,
        'description' => $product->name,
        'unit_label' => 'M',
        'meters' => $meters,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => $meters * 20,
    ]);

    return $sale->load('items');
}

it('registra despacho parcial de una nota de venta', function () {
    $user = deliveriesUser(['sales.deliveries.view', 'sales.deliveries.manage']);
    $product = deliveryProduct('DES-001');
    $sale = deliverySale($user, $product, 10);

    $this->actingAs($user)
        ->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'delivery_number' => 'DESP-0001',
            'delivered_at' => now()->format('Y-m-d H:i:s'),
            'recipient_name' => 'Ruben Camacho',
            'recipient_document' => '85911',
            'recipient_phone' => '70775320',
            'driver_name' => 'Juan Perez',
            'vehicle_plate' => 'ABC123',
            'notes' => null,
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 4],
            ],
        ])
        ->assertRedirect(route('sales.deliveries.index'));

    $delivery = DeliveryNote::query()->where('delivery_number', 'DESP-0001')->firstOrFail();

    expect((float) $delivery->total_meters)->toBe(4.0)
        ->and($delivery->status)->toBe('partial')
        ->and($delivery->items()->count())->toBe(1);
});

it('marca despacho completo considerando devoluciones previas', function () {
    $user = deliveriesUser(['sales.deliveries.view', 'sales.deliveries.manage']);
    $product = deliveryProduct('DES-002');
    $sale = deliverySale($user, $product, 10);
    $saleReturn = SaleReturn::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'return_number' => 'DEV-DESP-001',
        'returned_at' => now(),
        'total_amount' => 60,
        'reason' => 'Devuelto antes de despacho',
    ]);
    $saleReturn->items()->create([
        'sale_item_id' => $sale->items->first()->id,
        'product_id' => $product->id,
        'meters' => 3,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => 60,
    ]);

    $this->actingAs($user)
        ->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'delivery_number' => 'DESP-0002',
            'delivered_at' => now()->format('Y-m-d H:i:s'),
            'recipient_name' => 'Cliente final',
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 7],
            ],
        ])
        ->assertRedirect(route('sales.deliveries.index'));

    expect(DeliveryNote::query()->where('delivery_number', 'DESP-0002')->value('status'))->toBe('completed');
});

it('bloquea despacho mayor al pendiente por entregar', function () {
    $user = deliveriesUser(['sales.deliveries.view', 'sales.deliveries.manage']);
    $product = deliveryProduct('DES-003');
    $sale = deliverySale($user, $product, 10);
    $delivery = DeliveryNote::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'delivery_number' => 'DESP-PREVIO',
        'delivered_at' => now(),
        'total_meters' => 8,
        'status' => 'partial',
    ]);
    $delivery->items()->create([
        'sale_item_id' => $sale->items->first()->id,
        'product_id' => $product->id,
        'meters' => 8,
    ]);

    $this->actingAs($user)
        ->from(route('sales.deliveries.index'))
        ->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'delivery_number' => 'DESP-0003',
            'delivered_at' => now()->format('Y-m-d H:i:s'),
            'items' => [
                ['sale_item_id' => $sale->items->first()->id, 'meters' => 3],
            ],
        ])
        ->assertRedirect(route('sales.deliveries.index'))
        ->assertSessionHasErrors('items');
});

it('lista despachos con permiso y bloquea sin permiso', function () {
    $user = deliveriesUser(['sales.deliveries.view']);

    $this->actingAs($user)
        ->get(route('sales.deliveries.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Deliveries/Index', false)
            ->has('deliveries.data', 0)
        );

    $blocked = deliveriesUser([]);

    $this->actingAs($blocked)
        ->get(route('sales.deliveries.index'))
        ->assertForbidden();
});
