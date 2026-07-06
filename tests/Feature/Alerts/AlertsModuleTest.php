<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInteraction;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function alertsUser(array $permissions): User
{
    $suffix = strtoupper(substr(uniqid(), -6));

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'alertas-test-'.$suffix, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Alertas central',
        'code' => 'ALT-'.$suffix,
        'barcode' => 'BR-ALT-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('lista alertas operativas filtradas por permisos y sucursal', function () {
    $user = alertsUser([
        'alerts.view',
        'inventory.products.view',
        'inventory.coils.manage',
        'payments.view',
        'cash.view',
    ]);
    $otherBranch = Branch::query()->create([
        'name' => 'Alertas externa',
        'code' => 'AEX',
        'barcode' => 'BR-AEX',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina alerta',
        'sku' => 'ALT-001',
        'barcode' => 'PR-ALT-001',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 20,
        'is_active' => true,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 0,
        'reserved_meters' => 0,
    ]);
    ProductCoil::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'barcode' => 'COIL-ALT-001',
        'lot_number' => 'LOT-ALT',
        'initial_meters' => 50,
        'available_meters' => 0,
        'status' => 'depleted',
    ]);
    Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'ALT-VENTA-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente alerta',
        'sold_at' => now()->subDays(8),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 20,
        'balance_due' => 80,
        'total' => 100,
        'status' => 'partial_paid',
    ]);
    CashRegisterSession::query()->create([
        'branch_id' => $user->branch_id,
        'opened_by' => $user->id,
        'opened_at' => now()->subDay(),
        'opening_amount' => 100,
        'cash_income_amount' => 0,
        'cash_expense_amount' => 0,
        'expected_cash_amount' => 100,
        'status' => CashRegisterSession::STATUS_OPEN,
    ]);
    Sale::query()->create([
        'branch_id' => $otherBranch->id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'ALT-VENTA-EXT',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente externo',
        'sold_at' => now()->subDays(10),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 999,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 999,
        'total' => 999,
        'status' => 'issued',
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index', false)
            ->where('summary.total', 4)
            ->where('summary.critical', 3)
            ->where('summary.info', 1)
            ->has('alerts.data', 4)
            ->where('types.0', 'low_stock')
            ->where('types.1', 'receivable')
            ->where('types.2', 'cash_open')
            ->where('types.3', 'depleted_coil')
        );
});

it('filtra alertas por tipo y bloquea usuarios sin permiso', function () {
    $user = alertsUser(['alerts.view', 'payments.view']);
    $blocked = alertsUser([]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);

    Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'ALT-FILTRO-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente filtro',
        'sold_at' => now()->subDays(2),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index', ['type' => 'receivable']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('alerts.data.0.type', 'receivable')
        );

    $this->actingAs($blocked)
        ->get(route('alerts.index'))
        ->assertForbidden();
});

it('muestra alertas de promesas de pago vencidas con permiso de cobranza', function () {
    $user = alertsUser(['alerts.view', 'payment-promises.view']);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'ALT-PROM-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente promesa',
        'sold_at' => now()->subDays(5),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    PaymentPromise::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'promise_number' => 'PROM-ALT-001',
        'promised_date' => now()->subDay()->toDateString(),
        'promised_amount' => 80,
        'contact_phone' => '70775320',
        'channel' => 'phone',
        'status' => PaymentPromise::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index', ['type' => 'payment_promise']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.critical', 1)
            ->where('alerts.data.0.type', 'payment_promise')
            ->where('alerts.data.0.severity', 'critical')
            ->where('types.0', 'payment_promise')
        );
});

it('muestra alertas de seguimiento crm vencido con permiso de clientes', function () {
    $user = alertsUser(['alerts.view', 'customers.view']);
    $customer = Customer::query()->create([
        'name' => 'Cliente CRM alerta',
        'phone' => '70000000',
        'is_active' => true,
    ]);

    CustomerInteraction::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $user->id,
        'type' => 'call',
        'status' => CustomerInteraction::STATUS_PENDING,
        'contact_at' => now()->subDays(2),
        'follow_up_at' => now()->subDay(),
        'subject' => 'Confirmar compra pendiente',
    ]);

    $this->actingAs($user)
        ->get(route('alerts.index', ['type' => 'customer_follow_up']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.critical', 1)
            ->where('alerts.data.0.type', 'customer_follow_up')
            ->where('alerts.data.0.source_url', route('customers.statement', $customer->id))
            ->where('types.0', 'customer_follow_up')
        );
});
