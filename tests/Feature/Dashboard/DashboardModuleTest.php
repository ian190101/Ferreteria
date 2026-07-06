<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function dashboardUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'dashboard-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Dashboard central',
        'code' => 'DASH',
        'barcode' => 'BR-DASH',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('muestra dashboard operativo filtrado por permisos y sucursal', function () {
    $user = dashboardUser([
        'sales.view',
        'sales.manage',
        'payments.view',
        'cash.view',
        'inventory.products.view',
    ]);
    $otherBranch = Branch::query()->create([
        'name' => 'Sucursal externa',
        'code' => 'EXT',
        'barcode' => 'BR-EXT',
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
        'name' => 'Calamina dashboard',
        'sku' => 'DASH-001',
        'barcode' => 'PR-DASH-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 20,
        'is_active' => true,
    ]);

    Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'DASH-VENTA-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente dashboard',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 40,
        'balance_due' => 60,
        'total' => 100,
        'status' => 'partial_paid',
    ]);
    Sale::query()->create([
        'branch_id' => $otherBranch->id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'DASH-VENTA-EXT',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente externo',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 999,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 999,
        'total' => 999,
        'status' => 'issued',
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 10,
        'reserved_meters' => 0,
    ]);
    CashRegisterSession::query()->create([
        'branch_id' => $user->branch_id,
        'opened_by' => $user->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'cash_income_amount' => 0,
        'cash_expense_amount' => 0,
        'expected_cash_amount' => 100,
        'status' => CashRegisterSession::STATUS_OPEN,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index', false)
            ->where('scope.branch_id', $user->branch_id)
            ->where('metrics.sales_today_total', 100)
            ->where('metrics.receivables_total', 60)
            ->where('metrics.open_cash_count', 1)
            ->where('metrics.low_stock_count', 1)
            ->has('quickActions', 2)
            ->has('recentSales', 1)
            ->has('pendingReceivables', 1)
            ->has('lowStocks', 1)
            ->has('openCashSessions', 1)
        );
});

it('permite entrar al dashboard sin permisos operativos exponiendo solo datos vacios', function () {
    $user = dashboardUser([]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index', false)
            ->where('metrics.sales_today_total', null)
            ->where('metrics.receivables_total', null)
            ->has('quickActions', 0)
            ->has('recentSales', 0)
            ->has('pendingReceivables', 0)
            ->has('lowStocks', 0)
            ->has('openCashSessions', 0)
        );
});

it('muestra metricas de promesas de pago con permiso de cobranza', function () {
    $user = dashboardUser(['payment-promises.view', 'payment-promises.manage']);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'DASH-PROM-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente promesa',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    foreach ([now()->subDay(), now()] as $index => $date) {
        PaymentPromise::query()->create([
            'sale_id' => $sale->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'promise_number' => 'DASH-PROM-'.$index,
            'promised_date' => $date->toDateString(),
            'promised_amount' => 50,
            'channel' => 'phone',
            'status' => PaymentPromise::STATUS_PENDING,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.payment_promises_overdue_count', 1)
            ->where('metrics.payment_promises_today_count', 1)
            ->has('quickActions', 1)
            ->where('quickActions.0.label', 'Promesa de pago')
        );
});
