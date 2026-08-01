<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\ProductImage;
use App\Modules\SystemSuperadmin\Models\ProductVariant;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function posDynamicUser(array $configuration): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil POS dinamico',
        'business_type' => data_get($configuration, 'identity.business_type', 'mixed'),
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    Permission::firstOrCreate(['name' => 'sales.manage', 'guard_name' => 'web']);

    $role = Role::firstOrCreate(['name' => 'pos-dinamico-test', 'guard_name' => 'web']);
    $role->syncPermissions(['sales.manage']);

    $branch = Branch::query()->create([
        'name' => 'Sucursal POS',
        'code' => 'POS',
        'barcode' => 'BR-POS',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);
    $user->accessibleBranches()->sync([$branch->id]);

    SaleType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]);
    Currency::query()->firstOrCreate(['code' => 'BOB'], [
        'name' => 'Bolivianos',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    PaymentMethod::query()->firstOrCreate(['code' => 'cash'], [
        'name' => 'Efectivo',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    return $user;
}

function posDynamicProduct(User $user, array $attributes = []): Product
{
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => $attributes['base_unit'] ?? 'u'], [
        'name' => $attributes['unit_name'] ?? 'Unidad',
        'kind' => 'unit',
        'is_active' => true,
    ]);

    $product = Product::query()->create(array_merge([
        'product_unit_id' => $unit->id,
        'name' => 'Producto POS',
        'sku' => 'POS-001',
        'barcode' => '779000000001',
        'base_unit' => $unit->symbol,
        'item_type' => 'physical',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'is_sellable' => true,
        'is_purchasable' => true,
        'is_inventory_item' => true,
        'purchase_price' => 10,
        'sale_price' => 15,
        'minimum_stock_meters' => 1,
        'is_active' => true,
    ], $attributes, ['product_unit_id' => $unit->id]));

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 25,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    return $product;
}

it('muestra POS de servicios con items de servicio permitidos por perfil', function () {
    $user = posDynamicUser([
        'modules' => ['pos' => true, 'services' => true],
        'capabilities' => ['uses_pos' => true, 'uses_services' => true, 'requires_cash_session' => false],
        'sales' => ['workflow' => 'service_sale', 'customer_mode' => 'optional'],
        'cash' => ['required_to_sell' => false],
        'products' => [
            'catalog_mode' => 'services',
            'item_types' => ['service'],
            'allow_service_items' => true,
        ],
    ]);

    posDynamicProduct($user, [
        'name' => 'Diagnostico tecnico',
        'sku' => 'SERV-001',
        'barcode' => '779000000002',
        'item_type' => 'service',
        'is_inventory_item' => false,
        'duration_minutes' => 45,
        'sale_price' => 80,
    ]);

    $this->actingAs($user)
        ->get(route('pos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index', false)
            ->where('posPolicy.mode', 'services')
            ->where('posPolicy.supportsServices', true)
            ->where('products.0.item_type', 'service')
            ->where('products.0.duration_minutes', 45));
});

it('expone modo restaurante con senales de mesa cocina y propinas', function () {
    $user = posDynamicUser([
        'modules' => ['pos' => true, 'restaurant_tables' => true, 'kitchen_orders' => true],
        'capabilities' => [
            'uses_pos' => true,
            'uses_tables' => true,
            'uses_waiters' => true,
            'uses_kitchen_orders' => true,
            'uses_tips' => true,
            'requires_cash_session' => false,
        ],
        'sales' => ['workflow' => 'restaurant_table', 'customer_mode' => 'optional'],
        'cash' => ['required_to_sell' => false],
        'restaurant' => ['mode' => 'full', 'tips_mode' => 'optional'],
        'products' => [
            'catalog_mode' => 'restaurant_menu',
            'item_types' => ['prepared_product'],
        ],
    ]);

    posDynamicProduct($user, [
        'name' => 'Menu ejecutivo',
        'sku' => 'MENU-001',
        'barcode' => '779000000003',
        'item_type' => 'prepared_product',
        'is_prepared' => true,
        'preparation_minutes' => 15,
        'sale_price' => 35,
    ]);

    $this->actingAs($user)
        ->get(route('pos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index', false)
            ->where('posPolicy.mode', 'restaurant_table')
            ->where('posPolicy.usesTables', true)
            ->where('posPolicy.usesKitchen', true)
            ->where('posPolicy.usesTips', true)
            ->where('products.0.preparation_minutes', 15));
});

it('carga imagenes y variantes cuando el perfil usa catalogo visual', function () {
    $user = posDynamicUser([
        'modules' => ['pos' => true],
        'capabilities' => ['uses_pos' => true, 'requires_cash_session' => false],
        'sales' => ['workflow' => 'pos', 'customer_mode' => 'optional'],
        'cash' => ['required_to_sell' => false],
        'products' => [
            'catalog_mode' => 'variants',
            'item_types' => ['physical'],
            'images_enabled' => true,
            'variants_enabled' => true,
        ],
    ]);

    $product = posDynamicProduct($user, [
        'name' => 'Polera azul',
        'sku' => 'POL-001',
        'barcode' => '779000000004',
        'sale_price' => 50,
    ]);

    ProductImage::query()->create([
        'product_id' => $product->id,
        'url' => 'https://i.ibb.co/TxYvJ6hf/logoaromacal.jpg',
        'alt_text' => 'Polera azul',
        'is_primary' => true,
        'sort_order' => 1,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Talla M',
        'sku' => 'POL-001-M',
        'barcode' => '779000000005',
        'attributes' => ['talla' => 'M'],
        'sale_price' => 55,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('pos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index', false)
            ->where('posPolicy.usesImages', true)
            ->where('posPolicy.usesVariants', true)
            ->where('products.0.image_url', 'https://i.ibb.co/TxYvJ6hf/logoaromacal.jpg')
            ->where('products.0.variants.0.name', 'Talla M')
            ->where('products.0.variants.0.sale_price', 55));
});

it('bloquea saldo parcial desde POS cuando el perfil exige pago unico', function () {
    $user = posDynamicUser([
        'modules' => ['pos' => true, 'sales_notes' => true],
        'capabilities' => ['uses_pos' => true, 'requires_cash_session' => false],
        'sales' => ['workflow' => 'pos', 'quotation_mode' => 'disabled', 'customer_mode' => 'optional', 'credit_limit_policy' => 'disabled'],
        'cash' => ['required_to_sell' => false],
        'pos' => ['payment_flow' => 'single'],
        'products' => ['catalog_mode' => 'barcode_retail', 'item_types' => ['physical']],
    ]);
    $product = posDynamicProduct($user, ['sale_price' => 20]);
    $paymentMethod = PaymentMethod::query()->where('code', 'cash')->first();
    $saleType = SaleType::query()->first();
    $currency = Currency::query()->where('code', 'BOB')->first();

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'from_pos' => true,
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'customer_name' => 'Cliente POS',
            'advance_mode' => 'none',
            'requires_delivery' => false,
            'pos_payment_method_id' => $paymentMethod->id,
            'pos_payment_amount' => 10,
            'pos_context' => ['mode' => 'supermarket', 'channel' => 'counter'],
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'unit_label' => 'u',
                'display_quantity' => 1,
                'display_unit_label' => 'u',
                'calculation_mode' => 'direct',
                'meters' => 1,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertSessionHasErrors('pos_payment_amount');
});

it('bloquea desde POS items que no pertenecen al catalogo permitido por perfil', function () {
    $user = posDynamicUser([
        'modules' => ['pos' => true, 'sales_notes' => true],
        'capabilities' => ['uses_pos' => true, 'requires_cash_session' => false],
        'sales' => ['workflow' => 'pos', 'quotation_mode' => 'disabled', 'customer_mode' => 'optional'],
        'cash' => ['required_to_sell' => false],
        'products' => ['catalog_mode' => 'barcode_retail', 'item_types' => ['physical']],
    ]);
    $product = posDynamicProduct($user, [
        'name' => 'Servicio no permitido',
        'item_type' => 'service',
        'is_inventory_item' => false,
        'sale_price' => 100,
    ]);
    $paymentMethod = PaymentMethod::query()->where('code', 'cash')->first();
    $saleType = SaleType::query()->first();
    $currency = Currency::query()->where('code', 'BOB')->first();

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'from_pos' => true,
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'customer_name' => 'Cliente POS',
            'advance_mode' => 'none',
            'requires_delivery' => false,
            'pos_payment_method_id' => $paymentMethod->id,
            'pos_payment_amount' => 100,
            'pos_context' => ['mode' => 'supermarket', 'channel' => 'counter'],
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'unit_label' => 'u',
                'display_quantity' => 1,
                'display_unit_label' => 'u',
                'calculation_mode' => 'direct',
                'meters' => 1,
                'unit_price' => 100,
                'discount_amount' => 0,
            ]],
        ])
        ->assertSessionHasErrors('items.0.product_id');
});
