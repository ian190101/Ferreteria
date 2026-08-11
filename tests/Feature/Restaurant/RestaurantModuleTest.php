<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Restaurant\Models\KitchenOrder;
use App\Modules\Restaurant\Models\Recipe;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function restaurantModuleUser(array $configuration = []): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil restaurante prueba',
        'business_type' => data_get($configuration, 'identity.business_type', 'restaurant'),
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    foreach (['restaurant.view', 'restaurant.manage'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'restaurante-test', 'guard_name' => 'web']);
    $role->syncPermissions(['restaurant.view', 'restaurant.manage']);

    $branch = Branch::query()->create([
        'name' => 'Sucursal Restaurante',
        'code' => 'REST',
        'barcode' => 'BR-REST',
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

function restaurantModuleConfiguration(array $overrides = []): array
{
    return array_replace_recursive([
        'identity' => ['business_type' => 'restaurant'],
        'modules' => [
            'pos' => true,
            'inventory' => true,
            'restaurant_tables' => true,
            'kitchen_orders' => true,
            'recipes' => true,
        ],
        'capabilities' => [
            'uses_pos' => true,
            'uses_inventory' => true,
            'uses_tables' => true,
            'uses_waiters' => true,
            'uses_kitchen_orders' => true,
            'uses_recipes' => true,
            'uses_split_payments' => true,
            'requires_cash_session' => false,
        ],
        'sales' => [
            'workflow' => 'restaurant_table',
            'customer_mode' => 'optional',
            'allow_negative_stock' => false,
        ],
        'restaurant' => [
            'mode' => 'full',
            'tables_enabled' => true,
            'waiters_enabled' => true,
            'kitchen_areas' => ['Cocina', 'Barra'],
            'discount_inventory_at' => 'kitchen_order',
            'tips_mode' => 'optional',
            'split_bill_enabled' => true,
        ],
        'cash' => ['required_to_sell' => false],
        'products' => [
            'catalog_mode' => 'restaurant_menu',
            'item_types' => ['prepared_product', 'internal_supply', 'combo'],
        ],
    ], $overrides);
}

function restaurantModuleProduct(array $attributes = []): Product
{
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => $attributes['base_unit'] ?? 'u'], [
        'name' => $attributes['unit_name'] ?? 'Unidad',
        'kind' => 'unit',
        'is_active' => true,
    ]);

    return Product::query()->create(array_merge([
        'product_unit_id' => $unit->id,
        'name' => 'Producto restaurante',
        'sku' => 'REST-'.fake()->unique()->numerify('###'),
        'barcode' => fake()->unique()->numerify('779#########'),
        'base_unit' => $unit->symbol,
        'item_type' => 'physical',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'is_sellable' => true,
        'is_purchasable' => true,
        'is_inventory_item' => true,
        'is_consumable' => true,
        'purchase_price' => 5,
        'sale_price' => 10,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ], $attributes, ['product_unit_id' => $unit->id]));
}

it('muestra el panel de restaurante cuando el perfil lo activa', function () {
    $user = restaurantModuleUser(restaurantModuleConfiguration());

    RestaurantTable::query()->create([
        'branch_id' => $user->branch_id,
        'area_name' => 'Salon principal',
        'name' => 'Mesa 1',
        'code' => 'M1',
        'capacity' => 4,
        'status' => RestaurantTable::STATUS_AVAILABLE,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('restaurant.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Restaurant/Index', false)
            ->where('policy.tablesEnabled', true)
            ->where('policy.kitchenEnabled', true)
            ->where('policy.recipesEnabled', true)
            ->where('tables.0.name', 'Mesa 1'));
});

it('bloquea rutas de restaurante cuando el perfil no tiene la capacidad activa', function () {
    $user = restaurantModuleUser([
        'identity' => ['business_type' => 'hardware_store'],
        'modules' => [
            'restaurant_tables' => false,
            'kitchen_orders' => false,
            'recipes' => false,
        ],
        'capabilities' => [
            'uses_tables' => false,
            'uses_kitchen_orders' => false,
            'uses_recipes' => false,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('restaurant.index'))
        ->assertNotFound();
});

it('registra mesas y permite cambiar su estado por sucursal', function () {
    $user = restaurantModuleUser(restaurantModuleConfiguration());

    $this->actingAs($user)
        ->post(route('restaurant.tables.store'), [
            'branch_id' => $user->branch_id,
            'area_name' => 'Terraza',
            'name' => 'Mesa 8',
            'code' => 'T-8',
            'capacity' => 6,
            'sort_order' => 8,
        ])
        ->assertRedirect();

    $table = RestaurantTable::query()->where('code', 'T-8')->firstOrFail();

    $this->actingAs($user)
        ->patch(route('restaurant.tables.update', $table), [
            'status' => RestaurantTable::STATUS_RESERVED,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($table->fresh()->status)->toBe(RestaurantTable::STATUS_RESERVED);
});

it('guarda recetas y descuenta insumos al enviar una comanda', function () {
    $user = restaurantModuleUser(restaurantModuleConfiguration());

    $plate = restaurantModuleProduct([
        'name' => 'Hamburguesa clasica',
        'sku' => 'HAMB-001',
        'barcode' => '779100000001',
        'item_type' => 'prepared_product',
        'is_inventory_item' => false,
        'is_prepared' => true,
        'base_unit' => 'u',
        'sale_price' => 25,
    ]);
    $ingredient = restaurantModuleProduct([
        'name' => 'Pan de hamburguesa',
        'sku' => 'PAN-001',
        'barcode' => '779100000002',
        'item_type' => 'internal_supply',
        'base_unit' => 'u',
        'sale_price' => 0,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $ingredient->id,
        'available_meters' => 10,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->post(route('restaurant.recipes.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $plate->id,
            'name' => 'Receta hamburguesa clasica',
            'yield_quantity' => 1,
            'items' => [[
                'ingredient_product_id' => $ingredient->id,
                'quantity' => 2,
                'unit_label' => 'u',
                'waste_percentage' => 0,
            ]],
        ])
        ->assertRedirect();

    $recipe = Recipe::query()->where('product_id', $plate->id)->firstOrFail();

    $this->actingAs($user)
        ->post(route('restaurant.orders.store'), [
            'branch_id' => $user->branch_id,
            'restaurant_table_id' => null,
            'channel' => 'counter',
            'preparation_area' => 'Cocina',
            'items' => [[
                'product_id' => $plate->id,
                'quantity' => 3,
                'unit_price' => 25,
            ]],
        ])
        ->assertRedirect();

    expect(ProductBranchStock::query()->where('product_id', $ingredient->id)->value('available_meters'))->toBe('4.000')
        ->and(KitchenOrder::query()->first()?->items()->first()?->recipe_id)->toBe($recipe->id)
        ->and((float) InventoryMovement::query()->where('type', 'restaurant_recipe_consumption')->sum('meters_delta'))->toBe(-6.0);
});
