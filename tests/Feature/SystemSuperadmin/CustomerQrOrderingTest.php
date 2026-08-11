<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Reservations\Models\BusinessReservation;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\CustomerQrChannel;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use App\Modules\SystemSuperadmin\Models\OperationalTrace;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use App\Support\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function qrOrderingBranch(): Branch
{
    return Branch::query()->create([
        'name' => 'Sucursal QR',
        'code' => 'QR-'.uniqid(),
        'barcode' => 'BR-QR-'.uniqid(),
        'address' => 'Cochabamba',
        'is_active' => true,
    ]);
}

function qrOrderingSystemUser(?Branch $branch = null): User
{
    $role = Role::firstOrCreate([
        'name' => SystemRoles::SYSTEM_SUPERADMIN,
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create([
        'branch_id' => ($branch ?? qrOrderingBranch())->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function activateQrOrderingProfile(?User $user = null): void
{
    BusinessProfile::query()->create([
        'name' => 'Perfil con pedidos QR',
        'business_type' => 'restaurant',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => [
                'pos' => true,
                'quotes' => true,
                'sales_notes' => true,
                'alerts' => true,
                'kitchen_orders' => true,
                'service_orders' => true,
                'reservations' => true,
                'customer_qr_ordering' => true,
            ],
            'capabilities' => [
                'uses_pos' => true,
                'allows_direct_sale' => true,
                'allows_quote_to_sale' => true,
                'uses_reservations' => true,
                'uses_operational_traceability' => true,
                'uses_customer_qr_ordering' => true,
            ],
            'feature_flags' => [
                'customer_qr_ordering_engine' => true,
                'operational_traceability_engine' => true,
            ],
            'customer_qr_ordering' => [
                'enabled' => true,
                'max_items_per_order' => 10,
                'max_quantity_per_item' => 50,
                'allowed_order_types' => ['pickup', 'table', 'delivery'],
                'requires_customer_name' => true,
                'requires_customer_phone' => false,
                'cache_menu_minutes' => 5,
            ],
            'traceability' => [
                'enabled' => true,
            ],
        ]),
        'applied_at' => now(),
        'applied_by' => $user?->id,
    ]);

    SystemCacheInvalidator::bumpOperational();
}

function qrOrderingOperator(Branch $branch, array $permissions = []): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'operador-qr', 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);
    $user->accessibleBranches()->sync([$branch->id]);

    return $user;
}

function qrProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Servicio de prueba QR',
        'sku' => 'SKU-QR-'.uniqid(),
        'barcode' => 'BAR-QR-'.uniqid(),
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'un',
        'item_type' => 'service',
        'is_sellable' => true,
        'is_purchasable' => false,
        'is_inventory_item' => false,
        'sale_price' => 25,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ], $overrides));
}

it('mantiene bloqueados los pedidos por QR por defecto en ferreteria', function () {
    $branch = qrOrderingBranch();
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'mesa_01',
        'name' => 'Mesa 01',
        'token' => 'mesa01token',
        'context_type' => 'restaurant_table',
        'service_mode' => 'table',
        'allowed_order_types' => ['table'],
        'is_active' => true,
    ]);

    $this->get(route('customer-qr-ordering.show', $channel->token))->assertNotFound();
    $this->get(route('customer-qr-ordering.svg', $channel->token))->assertNotFound();
});

it('muestra menu publico y recibe un pedido cuando el perfil lo habilita', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $product = qrProduct(['name' => 'Delivery centro', 'sale_price' => 12.5]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'retiro_central',
        'name' => 'Retiro central',
        'token' => 'retirocentralqr',
        'context_type' => 'branch_pickup',
        'service_mode' => 'pickup',
        'allowed_order_types' => ['pickup'],
        'requires_customer_name' => true,
        'is_active' => true,
    ]);

    $this->get(route('customer-qr-ordering.show', $channel->token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CustomerQrOrdering/Show', false)
            ->where('channel.name', 'Retiro central')
            ->has('menu', 1)
        );

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente QR',
        'customer_phone' => '70000000',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ])->assertRedirect();

    $order = CustomerQrOrder::query()->first();

    expect($order)->not->toBeNull()
        ->and($order->branch_id)->toBe($branch->id)
        ->and($order->customer_name)->toBe('Cliente QR')
        ->and((float) $order->subtotal)->toBe(25.0)
        ->and((float) $order->total)->toBe(25.0)
        ->and($order->items[0]['name'])->toBe('Delivery centro');
});

it('bloquea canales inactivos o vencidos', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $inactive = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'inactivo',
        'name' => 'Canal inactivo',
        'token' => 'canalinactivo',
        'allowed_order_types' => ['pickup'],
        'is_active' => false,
    ]);
    $expired = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'vencido',
        'name' => 'Canal vencido',
        'token' => 'canalvencido',
        'allowed_order_types' => ['pickup'],
        'expires_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    $this->get(route('customer-qr-ordering.show', $inactive->token))->assertNotFound();
    $this->get(route('customer-qr-ordering.show', $expired->token))->assertNotFound();
});

it('rechaza tipos no permitidos productos inactivos y limites de cantidad', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $inactiveProduct = qrProduct(['is_active' => false]);
    $product = qrProduct();
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'validaciones',
        'name' => 'Validaciones QR',
        'token' => 'validacionesqr',
        'allowed_order_types' => ['pickup'],
        'is_active' => true,
    ]);

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'delivery',
        'customer_name' => 'Cliente QR',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('order_type');

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente QR',
        'items' => [['product_id' => $inactiveProduct->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('items');

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente QR',
        'items' => [['product_id' => $product->id, 'quantity' => 99]],
    ])->assertSessionHasErrors('items.0.quantity');
});

it('aplica rate limit anti spam en la ruta publica de creacion', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $product = qrProduct();
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'limite',
        'name' => 'Limite QR',
        'token' => 'limiteqr',
        'allowed_order_types' => ['pickup'],
        'is_active' => true,
    ]);

    for ($i = 0; $i < 8; $i++) {
        $this->post(route('customer-qr-ordering.store', $channel->token), [
            'order_type' => 'pickup',
            'customer_name' => 'Cliente '.$i,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertRedirect();
    }

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente limitado',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertTooManyRequests();
});

it('valida y reserva stock al aceptar un pedido QR con producto fisico', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);
    $operator = qrOrderingOperator($branch, ['customer-qr-orders.view', 'customer-qr-orders.accept']);

    $product = qrProduct([
        'item_type' => 'physical',
        'is_inventory_item' => true,
        'sale_price' => 10,
    ]);
    ProductBranchStock::query()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available_meters' => 5,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'stock',
        'name' => 'Stock QR',
        'token' => 'stockqr',
        'allowed_order_types' => ['pickup'],
        'is_active' => true,
    ]);

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente QR',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertRedirect();

    $order = CustomerQrOrder::query()->firstOrFail();
    $this->actingAs($operator)->post(route('customer-qr-orders.accept', $order))->assertRedirect();

    $stock = ProductBranchStock::query()->where('product_id', $product->id)->firstOrFail();
    expect((float) $stock->reserved_meters)->toBe(2.0)
        ->and(InventoryReservation::query()->where('customer_qr_order_id', $order->id)->where('status', InventoryReservation::STATUS_ACTIVE)->exists())->toBeTrue()
        ->and(OperationalTrace::query()->where('entity_type', 'customer_qr_order')->where('event', 'customer_qr_order_accepted')->exists())->toBeTrue();
});

it('bloquea pedidos QR de producto fisico sin stock libre', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $product = qrProduct([
        'item_type' => 'physical',
        'is_inventory_item' => true,
        'sale_price' => 10,
    ]);
    ProductBranchStock::query()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available_meters' => 1,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'sin_stock',
        'name' => 'Sin stock QR',
        'token' => 'sinstockqr',
        'allowed_order_types' => ['pickup'],
        'is_active' => true,
    ]);

    $this->post(route('customer-qr-ordering.store', $channel->token), [
        'order_type' => 'pickup',
        'customer_name' => 'Cliente QR',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertSessionHasErrors('items');
});

it('permite ver gestionar y convertir pedidos QR desde pantalla operativa', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);
    $operator = qrOrderingOperator($branch, [
        'customer-qr-orders.view',
        'customer-qr-orders.accept',
        'customer-qr-orders.reject',
        'customer-qr-orders.convert',
    ]);

    $product = qrProduct(['name' => 'Menu QR', 'sale_price' => 20]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'operativo',
        'name' => 'Operativo QR',
        'token' => 'operativoqr',
        'allowed_order_types' => ['table'],
        'is_active' => true,
    ]);
    $order = CustomerQrOrder::query()->create([
        'customer_qr_channel_id' => $channel->id,
        'branch_id' => $branch->id,
        'public_code' => 'QR-260811-TEST01',
        'status' => CustomerQrOrder::STATUS_PENDING,
        'order_type' => 'table',
        'customer_name' => 'Mesa QR',
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'base_unit' => 'un',
            'item_type' => 'service',
            'is_inventory_item' => false,
            'quantity' => 1,
            'unit_price' => 20,
            'subtotal' => 20,
        ]],
        'subtotal' => 20,
        'total' => 20,
        'submitted_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get(route('customer-qr-orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CustomerQrOrders/Index', false)
            ->has('orders.data', 1)
            ->where('orders.data.0.public_code', 'QR-260811-TEST01')
        );

    $this->actingAs($operator)
        ->patch(route('customer-qr-orders.status', $order), ['status' => CustomerQrOrder::STATUS_READY])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(CustomerQrOrder::STATUS_READY);

    $this->actingAs($operator)
        ->post(route('customer-qr-orders.convert', $order), ['target_type' => 'kitchen_order'])
        ->assertRedirect();

    expect(KitchenOrder::query()->count())->toBe(1)
        ->and($order->fresh()->converted_to_type)->toBe('kitchen_order');
});

it('convierte pedido QR a venta y cotizacion segun destino permitido', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);
    $operator = qrOrderingOperator($branch, ['customer-qr-orders.view', 'customer-qr-orders.convert']);

    $product = qrProduct(['sale_price' => 30]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'conversion',
        'name' => 'Conversion QR',
        'token' => 'conversionqr',
        'allowed_order_types' => ['pickup'],
        'is_active' => true,
    ]);

    foreach (['QR-260811-VENTA1', 'QR-260811-COTIZA'] as $code) {
        CustomerQrOrder::query()->create([
            'customer_qr_channel_id' => $channel->id,
            'branch_id' => $branch->id,
            'public_code' => $code,
            'status' => CustomerQrOrder::STATUS_PENDING,
            'order_type' => 'pickup',
            'customer_name' => 'Cliente QR',
            'items' => [[
                'product_id' => $product->id,
                'name' => $product->name,
                'base_unit' => 'un',
                'item_type' => 'service',
                'is_inventory_item' => false,
                'quantity' => 1,
                'unit_price' => 30,
                'subtotal' => 30,
            ]],
            'subtotal' => 30,
            'total' => 30,
            'submitted_at' => now(),
        ]);
    }

    $saleOrder = CustomerQrOrder::query()->where('public_code', 'QR-260811-VENTA1')->firstOrFail();
    $quoteOrder = CustomerQrOrder::query()->where('public_code', 'QR-260811-COTIZA')->firstOrFail();

    $this->actingAs($operator)->post(route('customer-qr-orders.convert', $saleOrder), ['target_type' => 'sale'])->assertRedirect();
    $this->actingAs($operator)->post(route('customer-qr-orders.convert', $quoteOrder), ['target_type' => 'quotation'])->assertRedirect();

    expect(Sale::query()->where('document_type', 'sale_note')->count())->toBe(1)
        ->and(Sale::query()->where('document_type', 'quotation')->count())->toBe(1)
        ->and($saleOrder->fresh()->status)->toBe(CustomerQrOrder::STATUS_CONVERTED)
        ->and($quoteOrder->fresh()->converted_to_type)->toBe('quotation');
});

it('convierte pedido QR a orden de servicio y reserva cuando el perfil lo permite', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);
    $operator = qrOrderingOperator($branch, ['customer-qr-orders.view', 'customer-qr-orders.convert']);

    $product = qrProduct(['sale_price' => 45]);
    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'conversion_servicios',
        'name' => 'Conversion servicios QR',
        'token' => 'conversionserviciosqr',
        'allowed_order_types' => ['service', 'reservation'],
        'is_active' => true,
    ]);

    foreach (['QR-260811-SERVIC', 'QR-260811-RESERV'] as $index => $code) {
        CustomerQrOrder::query()->create([
            'customer_qr_channel_id' => $channel->id,
            'branch_id' => $branch->id,
            'public_code' => $code,
            'status' => CustomerQrOrder::STATUS_PENDING,
            'order_type' => $index === 0 ? 'service' : 'reservation',
            'customer_name' => 'Cliente QR',
            'customer_phone' => '70000000',
            'items' => [[
                'product_id' => $product->id,
                'name' => $product->name,
                'base_unit' => 'un',
                'item_type' => 'service',
                'is_inventory_item' => false,
                'quantity' => 1,
                'unit_price' => 45,
                'subtotal' => 45,
            ]],
            'subtotal' => 45,
            'total' => 45,
            'submitted_at' => now(),
        ]);
    }

    $serviceOrder = CustomerQrOrder::query()->where('public_code', 'QR-260811-SERVIC')->firstOrFail();
    $reservationOrder = CustomerQrOrder::query()->where('public_code', 'QR-260811-RESERV')->firstOrFail();

    $this->actingAs($operator)->post(route('customer-qr-orders.convert', $serviceOrder), ['target_type' => 'service_order'])->assertRedirect();
    $this->actingAs($operator)->post(route('customer-qr-orders.convert', $reservationOrder), ['target_type' => 'reservation'])->assertRedirect();

    expect(ServiceOrder::query()->count())->toBe(1)
        ->and(BusinessReservation::query()->count())->toBe(1)
        ->and($serviceOrder->fresh()->converted_to_type)->toBe('service_order')
        ->and($reservationOrder->fresh()->converted_to_type)->toBe('reservation')
        ->and(OperationalTrace::query()->where('event', 'customer_qr_order_converted')->count())->toBeGreaterThanOrEqual(2);
});

it('genera svg del codigo QR desde backend cuando el canal esta disponible', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);
    activateQrOrderingProfile($user);

    $channel = CustomerQrChannel::query()->create([
        'branch_id' => $branch->id,
        'code' => 'mesa_02',
        'name' => 'Mesa 02',
        'token' => 'mesa02token',
        'context_type' => 'restaurant_table',
        'service_mode' => 'table',
        'allowed_order_types' => ['table'],
        'is_active' => true,
    ]);

    $this->get(route('customer-qr-ordering.svg', $channel->token))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
        ->assertSee('<svg', false)
        ->assertSee('<rect', false);
});

it('permite a sistemasuperadmin crear canales QR sin activar el perfil por si solo', function () {
    $branch = qrOrderingBranch();
    $user = qrOrderingSystemUser($branch);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'customer-qr-channels'), [
            'branch_id' => $branch->id,
            'code' => 'delivery_zona_norte',
            'name' => 'Delivery zona norte',
            'context_type' => 'delivery_zone',
            'context_reference' => 'Zona norte',
            'service_mode' => 'delivery',
            'allowed_order_types_csv' => 'delivery,quote_request',
            'settings_text' => '{"service_fee":5}',
            'requires_customer_name' => true,
            'requires_customer_phone' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $channel = CustomerQrChannel::query()->where('code', 'delivery_zona_norte')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($channel)->not->toBeNull()
        ->and($channel->token)->not->toBeEmpty()
        ->and($channel->allowed_order_types)->toBe(['delivery', 'quote_request'])
        ->and($configuration['modules']['customer_qr_ordering'])->toBeFalse()
        ->and($configuration['capabilities']['uses_customer_qr_ordering'])->toBeFalse();
});
