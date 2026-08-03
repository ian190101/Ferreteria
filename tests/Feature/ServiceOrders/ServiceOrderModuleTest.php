<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\ServiceOrders\Models\ServiceOrder;
use App\Modules\ServiceOrders\Models\ServiceType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function serviceOrderConfiguration(array $overrides = []): array
{
    return array_replace_recursive([
        'identity' => ['business_type' => 'services'],
        'modules' => [
            'services' => true,
            'service_orders' => true,
            'workers' => true,
            'customers' => true,
            'inventory' => true,
        ],
        'capabilities' => [
            'uses_services' => true,
            'uses_service_orders' => true,
            'uses_inventory' => true,
            'requires_customer' => true,
            'uses_custom_statuses' => true,
            'requires_cash_session' => false,
        ],
        'services' => [
            'mode' => 'technical',
            'orders_enabled' => true,
            'technician_required' => true,
            'evidence_enabled' => true,
            'warranty_enabled' => true,
            'signature_enabled' => true,
        ],
        'sales' => ['allow_negative_stock' => false],
    ], $overrides);
}

function serviceOrderUser(array $configuration = []): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil servicios prueba',
        'business_type' => data_get($configuration, 'identity.business_type', 'services'),
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    foreach (['service-orders.view', 'service-orders.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'servicios-test', 'guard_name' => 'web']);
    $role->syncPermissions(['service-orders.view', 'service-orders.manage']);

    $branch = Branch::query()->create([
        'name' => 'Sucursal Servicios',
        'code' => 'SERV',
        'barcode' => 'BR-SERV',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);
    $user->accessibleBranches()->sync([$branch->id]);

    return $user;
}

function serviceOrderMaterial(array $attributes = []): Product
{
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => $attributes['base_unit'] ?? 'u'], [
        'name' => $attributes['unit_name'] ?? 'Unidad',
        'kind' => 'unit',
        'is_active' => true,
    ]);

    return Product::query()->create(array_merge([
        'product_unit_id' => $unit->id,
        'name' => 'Repuesto servicio',
        'sku' => 'SRV-'.fake()->unique()->numerify('###'),
        'barcode' => fake()->unique()->numerify('778#########'),
        'base_unit' => $unit->symbol,
        'item_type' => 'physical',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'is_sellable' => true,
        'is_purchasable' => true,
        'is_inventory_item' => true,
        'is_consumable' => true,
        'purchase_price' => 12,
        'sale_price' => 20,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ], $attributes, ['product_unit_id' => $unit->id]));
}

it('muestra ordenes de servicio cuando el perfil activa servicios', function () {
    $user = serviceOrderUser(serviceOrderConfiguration());

    Worker::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Tecnico prueba',
        'position' => 'Tecnico',
        'salary_amount' => 0,
        'salary_frequency' => 'monthly',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('service-orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceOrders/Index', false)
            ->where('policy.ordersEnabled', true)
            ->where('policy.technicianRequired', true)
            ->where('workers.0.name', 'Tecnico prueba'));
});

it('bloquea ordenes de servicio cuando el perfil desactiva el modulo', function () {
    $user = serviceOrderUser([
        'identity' => ['business_type' => 'hardware_store'],
        'modules' => ['services' => false, 'service_orders' => false],
        'capabilities' => ['uses_services' => false, 'uses_service_orders' => false],
    ]);

    $this->actingAs($user)
        ->get(route('service-orders.index'))
        ->assertNotFound();
});

it('gestiona tipos de servicio y protege transporte delivery del borrado', function () {
    $user = serviceOrderUser(serviceOrderConfiguration([
        'modules' => ['services' => true, 'service_orders' => false],
        'capabilities' => ['uses_services' => true, 'uses_service_orders' => false],
    ]));

    $delivery = ServiceType::query()->where('code', ServiceType::DELIVERY_CODE)->firstOrFail();

    $this->actingAs($user)
        ->get(route('service-orders.types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceOrders/Types/Index', false)
            ->where('deliveryCode', ServiceType::DELIVERY_CODE)
            ->where('types.data.0.code', 'general_service'));

    $this->actingAs($user)
        ->post(route('service-orders.types.store'), [
            'name' => 'Instalacion especializada',
            'code' => 'instalacion_especializada',
            'billing_unit' => 'service',
            'requires_materials' => true,
            'requires_responsible' => false,
            'requires_schedule' => false,
            'is_delivery' => false,
            'is_active' => true,
            'sort_order' => 30,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ServiceType::query()->where('code', 'instalacion_especializada')->exists())->toBeTrue();

    $this->actingAs($user)
        ->put(route('service-orders.types.update', $delivery), [
            'name' => 'Transporte/Delivery',
            'code' => ServiceType::DELIVERY_CODE,
            'billing_unit' => 'route',
            'requires_materials' => false,
            'requires_responsible' => true,
            'requires_schedule' => false,
            'is_delivery' => true,
            'is_active' => false,
            'sort_order' => 20,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($delivery->fresh()->is_active)->toBeFalse();

    $this->actingAs($user)
        ->from(route('service-orders.types.index'))
        ->delete(route('service-orders.types.destroy', $delivery))
        ->assertRedirect(route('service-orders.types.index'))
        ->assertSessionHasErrors('service_type');

    expect(ServiceType::query()->where('code', ServiceType::DELIVERY_CODE)->exists())->toBeTrue();
});

it('exige tecnico cuando el perfil lo requiere', function () {
    $user = serviceOrderUser(serviceOrderConfiguration());

    $this->actingAs($user)
        ->post(route('service-orders.store'), [
            'branch_id' => $user->branch_id,
            'customer_name' => 'Cliente manual',
            'title' => 'Reparacion de equipo',
            'labor_amount' => 50,
        ])
        ->assertSessionHasErrors('worker_id');
});

it('registra orden con materiales y descuenta inventario de la sucursal', function () {
    $user = serviceOrderUser(serviceOrderConfiguration());

    $worker = Worker::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Tecnico asignado',
        'position' => 'Tecnico',
        'salary_amount' => 0,
        'salary_frequency' => 'monthly',
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Cliente Servicios',
        'phone' => '70000000',
        'is_active' => true,
    ]);
    $material = serviceOrderMaterial([
        'name' => 'Sensor de repuesto',
        'sku' => 'SENSOR-001',
        'barcode' => '778100000001',
        'base_unit' => 'u',
        'sale_price' => 35,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $material->id,
        'available_meters' => 8,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->post(route('service-orders.store'), [
            'branch_id' => $user->branch_id,
            'customer_id' => $customer->id,
            'worker_id' => $worker->id,
            'title' => 'Cambio de sensor',
            'service_type' => 'Servicio tecnico',
            'labor_amount' => 80,
            'advance_amount' => 30,
            'diagnosis' => 'Sensor danado',
            'warranty_terms' => 'Garantia 30 dias',
            'items' => [[
                'product_id' => $material->id,
                'quantity' => 2,
                'unit_label' => 'u',
                'unit_price' => 35,
                'discount_inventory' => true,
            ]],
        ])
        ->assertRedirect();

    $order = ServiceOrder::query()->with('items')->firstOrFail();

    expect($order->total_amount)->toBe('150.0000')
        ->and($order->items)->toHaveCount(1)
        ->and(ProductBranchStock::query()->where('product_id', $material->id)->value('available_meters'))->toBe('6.000')
        ->and(InventoryMovement::query()->where('type', 'service_order_material_consumption')->sum('meters_delta'))->toBe('-2.000');
});

it('respeta estados personalizados y valida transiciones', function () {
    $user = serviceOrderUser(serviceOrderConfiguration());

    Permission::firstOrCreate(['name' => 'service-orders.finish', 'guard_name' => 'web']);
    $user->roles()->first()->givePermissionTo('service-orders.finish');

    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => ServiceOrder::STATUS_PENDING,
        'label' => 'Pendiente',
        'is_initial' => true,
        'is_final' => false,
        'allowed_transitions' => [ServiceOrder::STATUS_IN_PROGRESS],
        'sort_order' => 1,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => ServiceOrder::STATUS_IN_PROGRESS,
        'label' => 'En proceso',
        'is_initial' => false,
        'is_final' => false,
        'allowed_transitions' => [ServiceOrder::STATUS_FINISHED],
        'sort_order' => 2,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => ServiceOrder::STATUS_FINISHED,
        'label' => 'Terminado',
        'is_initial' => false,
        'is_final' => true,
        'allowed_transitions' => [],
        'required_permission' => 'service-orders.finish',
        'sort_order' => 3,
        'is_active' => true,
    ]);

    $order = ServiceOrder::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'order_number' => 'OS-TEST-001',
        'title' => 'Orden con estados',
        'status' => ServiceOrder::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->patch(route('service-orders.status', $order), ['status' => ServiceOrder::STATUS_FINISHED])
        ->assertSessionHasErrors('status');

    $this->actingAs($user)
        ->patch(route('service-orders.status', $order), ['status' => ServiceOrder::STATUS_IN_PROGRESS])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(ServiceOrder::STATUS_IN_PROGRESS);
});
