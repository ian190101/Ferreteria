<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reservationsUser(array $permissions): User
{
    $suffix = strtoupper(substr(uniqid(), -6));

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'reservas-test-'.$suffix, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Reservas central',
        'code' => 'RES-'.$suffix,
        'barcode' => 'BR-RES-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function reservationProduct(string $suffix = '001'): Product
{
    return Product::query()->create([
        'name' => 'Calamina reserva '.$suffix,
        'sku' => 'RES-'.$suffix,
        'barcode' => 'PR-RES-'.$suffix,
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
}

function reservationStock(int $branchId, int $productId, float $available, float $reserved = 0): ProductBranchStock
{
    return ProductBranchStock::query()->create([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'available_meters' => $available,
        'reserved_meters' => $reserved,
    ]);
}

it('registra reservas globales y las lista paginadas', function () {
    $user = reservationsUser(['inventory.reservations.view', 'inventory.reservations.manage']);
    $product = reservationProduct('LIST');
    reservationStock($user->branch_id, $product->id, 100);

    $this->actingAs($user)
        ->post(route('inventory.reservations.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'product_coil_id' => null,
            'sale_id' => null,
            'meters' => 25,
            'expires_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'reason' => 'Reserva para cliente',
            'notes' => null,
        ])
        ->assertRedirect(route('inventory.reservations.index'));

    $stock = ProductBranchStock::query()->where('product_id', $product->id)->firstOrFail();

    expect((float) $stock->reserved_meters)->toBe(25.0);

    $this->actingAs($user)
        ->get(route('inventory.reservations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Reservations/Index', false)
            ->has('reservations.data', 1)
            ->where('reservations.data.0.status', InventoryReservation::STATUS_ACTIVE)
        );
});

it('libera reservas globales devolviendo metros reservados', function () {
    $user = reservationsUser(['inventory.reservations.view', 'inventory.reservations.manage']);
    $product = reservationProduct('REL');
    reservationStock($user->branch_id, $product->id, 100, 30);
    $reservation = InventoryReservation::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'user_id' => $user->id,
        'meters' => 30,
        'status' => InventoryReservation::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)
        ->patch(route('inventory.reservations.release', $reservation->id))
        ->assertRedirect(route('inventory.reservations.index'));

    $reservation->refresh();
    $stock = ProductBranchStock::query()->where('product_id', $product->id)->firstOrFail();

    expect($reservation->status)->toBe(InventoryReservation::STATUS_RELEASED)
        ->and((float) $stock->reserved_meters)->toBe(0.0);
});

it('bloquea ventas manuales cuando el stock libre esta reservado', function () {
    $user = reservationsUser(['inventory.reservations.view', 'inventory.reservations.manage', 'sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = reservationProduct('SALE');
    reservationStock($user->branch_id, $product->id, 100, 95);

    $this->actingAs($user)
        ->from(route('sales.create', ['type' => 'sale_note']))
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'RES-BLOCK-001',
            'customer_name' => 'Cliente reserva',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect(route('sales.create', ['type' => 'sale_note']))
        ->assertSessionHasErrors('items');
});

it('consume reservas vinculadas al convertir una cotizacion', function () {
    $user = reservationsUser(['inventory.reservations.view', 'inventory.reservations.manage', 'sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = reservationProduct('CONV');
    reservationStock($user->branch_id, $product->id, 100, 10);
    $quotation = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'RES-COT-001',
        'document_type' => 'quotation',
        'customer_name' => 'Cliente conversion reserva',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 200,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 200,
        'total' => 200,
        'status' => 'quoted',
    ]);
    $quotation->items()->create([
        'product_id' => $product->id,
        'product_coil_id' => null,
        'description' => 'CALAMINA 10M',
        'unit_label' => 'M',
        'meters' => 10,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => 200,
    ]);
    $reservation = InventoryReservation::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'sale_id' => $quotation->id,
        'user_id' => $user->id,
        'meters' => 10,
        'status' => InventoryReservation::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)
        ->post(route('sales.convert', $quotation->id), [
            'receipt_number' => 'RES-NV-001',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect();

    $reservation->refresh();
    $stock = ProductBranchStock::query()->where('product_id', $product->id)->firstOrFail();

    expect($reservation->status)->toBe(InventoryReservation::STATUS_CONSUMED)
        ->and($reservation->consumed_sale_id)->not->toBeNull()
        ->and((float) $stock->reserved_meters)->toBe(0.0)
        ->and((float) $stock->available_meters)->toBe(90.0);
});

it('bloquea reservas sin permiso', function () {
    $user = reservationsUser([]);

    $this->actingAs($user)
        ->get(route('inventory.reservations.index'))
        ->assertForbidden();
});
