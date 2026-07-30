<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Thickness;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\ProductImage;
use App\Modules\SystemSuperadmin\Models\ProductVariant;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function inventoryUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'inventario-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Central',
        'code' => 'CENTRAL',
        'barcode' => 'BR-CENTRAL',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea productos y genera stock por sucursal activa', function () {
    $user = inventoryUser(['inventory.products.view', 'inventory.products.manage']);
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => 'M'], ['name' => 'Metro', 'is_active' => true]);
    $category = ProductCategory::query()->firstOrCreate(
        ['name' => 'Laminas'],
        ['slug' => 'laminas', 'default_unit_id' => $unit->id, 'default_tracking_mode' => Product::TRACKING_GLOBAL, 'is_active' => true],
    );
    $thickness = Thickness::query()->create([
        'name' => '0.50 mm',
        'millimeters' => 0.5,
        'kg_to_meter_factor' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('inventory.products.store'), [
            'thickness_id' => $thickness->id,
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'name' => 'Polietileno 0.50',
            'sku' => 'PE-050',
            'barcode' => 'PR-PE-050',
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'purchase_price' => 10,
            'sale_price' => 15,
            'minimum_stock_meters' => 50,
            'is_active' => true,
            'branch_scope' => 'specific',
            'branch_ids' => [$user->branch_id],
        ])
        ->assertRedirect(route('inventory.products.index'));

    $product = Product::query()->where('sku', 'PE-050')->firstOrFail();

    expect(ProductBranchStock::query()
        ->where('product_id', $product->id)
        ->where('branch_id', $user->branch_id)
        ->exists())->toBeTrue();
});

it('crea productos flexibles con imagenes y variantes cuando el perfil lo permite', function () {
    BusinessProfile::query()->create([
        'name' => 'Perfil tienda con variantes',
        'business_type' => 'clothing_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'products' => [
                'catalog_mode' => 'variants',
                'item_types' => ['physical', 'service', 'combo', 'internal_supply'],
                'images_enabled' => true,
                'gallery_enabled' => true,
                'variants_enabled' => true,
                'allow_service_items' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);

    $user = inventoryUser(['inventory.products.view', 'inventory.products.manage']);
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => 'unidad'], ['name' => 'Unidad', 'is_active' => true]);
    $category = ProductCategory::query()->firstOrCreate(
        ['name' => 'Ropa'],
        ['slug' => 'ropa', 'default_unit_id' => $unit->id, 'default_tracking_mode' => Product::TRACKING_GLOBAL, 'is_active' => true],
    );

    $this->actingAs($user)
        ->post(route('inventory.products.store'), [
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'name' => 'Polera deportiva',
            'sku' => 'POL-DEP',
            'barcode' => '779100000001',
            'item_type' => 'physical',
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'purchase_price' => 30,
            'sale_price' => 55,
            'minimum_stock_meters' => 5,
            'is_active' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
            'is_inventory_item' => true,
            'is_consumable' => false,
            'is_prepared' => false,
            'is_digital' => false,
            'branch_scope' => 'specific',
            'branch_ids' => [$user->branch_id],
            'images' => [[
                'url' => 'https://i.ibb.co/TxYvJ6hf/logoaromacal.jpg',
                'alt_text' => 'Polera deportiva azul',
                'is_primary' => true,
            ]],
            'variants' => [[
                'name' => 'Azul talla M',
                'sku' => 'POL-DEP-AZU-M',
                'barcode' => '779100000002',
                'attributes' => ['color' => 'Azul', 'talla' => 'M'],
                'cost_price' => 32,
                'sale_price' => 58,
                'is_active' => true,
            ]],
        ])
        ->assertRedirect(route('inventory.products.index'));

    $product = Product::query()->where('sku', 'POL-DEP')->firstOrFail();

    expect($product->item_type)->toBe('physical')
        ->and(ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('product_id', $product->id)->where('sku', 'POL-DEP-AZU-M')->where('is_active', true)->exists())->toBeTrue();
});

it('bloquea servicios cuando el perfil no permite items de servicio', function () {
    BusinessProfile::query()->create([
        'name' => 'Perfil ferreteria estricta',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'products' => [
                'item_types' => ['physical'],
                'allow_service_items' => false,
            ],
        ]),
        'applied_at' => now(),
    ]);

    $user = inventoryUser(['inventory.products.view', 'inventory.products.manage']);
    $unit = ProductUnit::query()->firstOrCreate(['symbol' => 'unidad'], ['name' => 'Unidad', 'is_active' => true]);
    $category = ProductCategory::query()->firstOrCreate(
        ['name' => 'Servicios'],
        ['slug' => 'servicios', 'default_unit_id' => $unit->id, 'default_tracking_mode' => Product::TRACKING_GLOBAL, 'is_active' => true],
    );

    $this->actingAs($user)
        ->from(route('inventory.products.create'))
        ->post(route('inventory.products.store'), [
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'name' => 'Instalacion basica',
            'sku' => 'SERV-001',
            'barcode' => '779100000003',
            'item_type' => 'service',
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'purchase_price' => 0,
            'sale_price' => 80,
            'minimum_stock_meters' => 0,
            'is_active' => true,
            'branch_scope' => 'specific',
            'branch_ids' => [$user->branch_id],
        ])
        ->assertRedirect(route('inventory.products.create'))
        ->assertSessionHasErrors('item_type');
});

it('registra bobinas solo para productos con rastreo individual', function () {
    $user = inventoryUser(['inventory.coils.manage']);
    $product = Product::query()->create([
        'name' => 'Bobina trazable',
        'sku' => 'BOB-001',
        'barcode' => 'PR-BOB-001',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('inventory.coils.store'), [
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'barcode' => 'COIL-001',
            'lot_number' => 'LOT-001',
            'initial_kg' => 12.5,
            'initial_meters' => 125,
        ])
        ->assertRedirect(route('inventory.coils.index'));

    expect(ProductCoil::query()->where('barcode', 'COIL-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'coil_entry')->exists())->toBeTrue();
});
