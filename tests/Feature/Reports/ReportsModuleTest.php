<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
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

function activeReportsProfile(array $configuration, string $businessType = 'mixed'): void
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil reportes',
        'business_type' => $businessType,
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();
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
            ->component('Reports/Index', false)
            ->where('filters.branch_id', $user->branch_id)
        );
});

it('muestra metricas de restaurante solo cuando el perfil activa comandas y mesas', function () {
    activeReportsProfile([
        'modules' => [
            'reports' => true,
            'restaurant_tables' => true,
            'pos' => true,
        ],
        'capabilities' => [
            'uses_pos' => true,
            'uses_tables' => true,
            'uses_kitchen_orders' => true,
        ],
    ], 'restaurant');

    $user = reportsUser(['reports.view']);
    $table = RestaurantTable::query()->create([
        'branch_id' => $user->branch_id,
        'area_name' => 'Salon',
        'name' => 'Mesa 1',
        'code' => 'M-1',
        'capacity' => 4,
        'status' => RestaurantTable::STATUS_OCCUPIED,
        'is_active' => true,
    ]);
    KitchenOrder::query()->create([
        'branch_id' => $user->branch_id,
        'restaurant_table_id' => $table->id,
        'waiter_user_id' => $user->id,
        'order_number' => 'COM-REP-001',
        'channel' => 'table',
        'preparation_area' => 'cocina',
        'status' => KitchenOrder::STATUS_PREPARING,
        'subtotal' => 80,
        'sent_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('reports.index', ['branch_id' => $user->branch_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index', false)
            ->where('profileFeatures.restaurant', true)
            ->where('profileFeatures.service_orders', false)
            ->where('metrics.restaurant_tables_count', 1)
            ->where('metrics.kitchen_orders_count', 1)
            ->where('metrics.kitchen_pending_count', 1)
        );
});

it('muestra metricas de servicios cuando el perfil activa ordenes de servicio', function () {
    activeReportsProfile([
        'modules' => [
            'reports' => true,
            'service_orders' => true,
        ],
        'capabilities' => [
            'uses_services' => true,
            'uses_service_orders' => true,
        ],
    ], 'services');

    $user = reportsUser(['reports.view']);
    ServiceOrder::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'order_number' => 'SER-REP-001',
        'customer_name' => 'Cliente servicio',
        'title' => 'Mantenimiento',
        'service_type' => 'tecnico',
        'status' => ServiceOrder::STATUS_IN_PROGRESS,
        'scheduled_at' => now(),
        'labor_amount' => 120,
        'materials_amount' => 30,
        'advance_amount' => 20,
        'total_amount' => 150,
    ]);

    $this->actingAs($user)
        ->get(route('reports.index', ['branch_id' => $user->branch_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index', false)
            ->where('profileFeatures.service_orders', true)
            ->where('profileFeatures.restaurant', false)
            ->where('metrics.service_orders_count', 1)
            ->where('metrics.service_orders_open_count', 1)
            ->where('metrics.service_orders_total', 150)
        );
});
