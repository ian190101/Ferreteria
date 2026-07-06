<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function reportsUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'reportes-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Central reportes',
        'code' => 'REP',
        'barcode' => 'BR-REP',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('muestra dashboard de reportes con metricas cacheadas y relaciones cargadas', function () {
    $user = reportsUser(['reports.view']);
    $branch = Branch::query()->findOrFail($user->branch_id);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina reporte',
        'sku' => 'REP-001',
        'barcode' => 'PR-REP-001',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 20,
        'is_active' => true,
    ]);

    Sale::query()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'REP-VENTA-001',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente reporte',
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

    Purchase::query()->create([
        'branch_id' => $branch->id,
        'supplier_id' => null,
        'user_id' => $user->id,
        'document_number' => 'REP-COMPRA-001',
        'purchase_date' => now()->format('Y-m-d'),
        'total_amount' => 50,
        'status' => 'received',
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available_meters' => 10,
        'reserved_meters' => 0,
    ]);

    ProductCoil::query()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'barcode' => 'COIL-REP-001',
        'lot_number' => 'LOT-REP',
        'initial_meters' => 100,
        'available_meters' => 80,
        'status' => 'available',
    ]);

    $this->actingAs($user)
        ->get(route('reports.index', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index', false)
            ->where('metrics.sales_count', 1)
            ->where('metrics.sales_total', 100)
            ->where('metrics.purchase_total', 50)
            ->where('metrics.low_stock_count', 1)
            ->has('recentSales', 1)
            ->has('lowStocks', 1)
            ->has('latestMovements', 1)
        );
});

it('bloquea reportes sin permiso', function () {
    $user = reportsUser([]);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertForbidden();
});

it('muestra antiguedad de cuentas por cobrar por rangos y proxima promesa', function () {
    $user = reportsUser(['reports.view']);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $sales = collect([
        ['number' => 'AGE-001', 'days' => 3, 'balance' => 100],
        ['number' => 'AGE-002', 'days' => 10, 'balance' => 200],
        ['number' => 'AGE-003', 'days' => 20, 'balance' => 300],
        ['number' => 'AGE-004', 'days' => 40, 'balance' => 400],
    ])->map(fn (array $row) => Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'currency_id' => $currency->id,
        'receipt_number' => $row['number'],
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente '.$row['number'],
        'customer_contact' => '70000000',
        'sold_at' => now()->subDays($row['days']),
        'exchange_rate_to_bob' => 1,
        'subtotal' => $row['balance'],
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => $row['balance'],
        'total' => $row['balance'],
        'status' => 'issued',
    ]));

    PaymentPromise::query()->create([
        'sale_id' => $sales[1]->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'promise_number' => 'AGE-PROM-001',
        'promised_date' => now()->addDay()->toDateString(),
        'promised_amount' => 150,
        'channel' => 'phone',
        'status' => PaymentPromise::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->get(route('reports.index', ['branch_id' => $user->branch_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('agingBuckets.0_7.count', 1)
            ->where('agingBuckets.0_7.total', 100)
            ->where('agingBuckets.8_15.count', 1)
            ->where('agingBuckets.8_15.total', 200)
            ->where('agingBuckets.16_30.count', 1)
            ->where('agingBuckets.31_plus.count', 1)
            ->has('agingReceivables.data', 4)
            ->where('agingReceivables.data.2.next_promise_date', now()->addDay()->toDateString())
        );
});
