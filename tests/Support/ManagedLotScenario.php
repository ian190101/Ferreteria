<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemRoles;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class ManagedLotScenario
{
    public static function catalogPayload(Branch $branch): array
    {
        $unit = \App\Modules\Inventory\Models\ProductUnit::query()->firstOrCreate(['symbol' => 'm'], ['name' => 'Metro', 'is_active' => true]);
        $sheet = \App\Modules\Inventory\Models\ProductUnit::query()->firstOrCreate(['symbol' => 'plancha'], ['name' => 'Plancha', 'is_active' => true]);
        $category = \App\Modules\Inventory\Models\ProductCategory::query()->create([
            'name' => 'Calaminas '.uniqid(), 'slug' => 'calaminas-'.uniqid(), 'default_unit_id' => $unit->id,
            'default_tracking_mode' => Product::TRACKING_GLOBAL, 'is_active' => true,
        ]);
        $thickness = \App\Modules\Inventory\Models\Thickness::query()->create([
            'name' => 'Espesor lotes '.uniqid(), 'millimeters' => '0.5000', 'kg_to_meter_factor' => '0.500000',
            'kg_per_meter' => '2.000000', 'is_active' => true,
        ]);

        return [
            'name' => 'Calamina Roja Trapezoidal', 'product_category_id' => $category->id, 'product_unit_id' => $unit->id,
            'thickness_id' => $thickness->id, 'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'base_unit' => 'm', 'requires_lot' => true, 'purchase_price' => '2.0000', 'sale_price' => '3.0000',
            'minimum_stock_meters' => '0.000', 'is_active' => true, 'branch_scope' => 'specific', 'branch_ids' => [$branch->id],
            'allowed_units' => ['m', 'plancha'],
            'unit_conversions' => [['product_unit_id' => $sheet->id, 'factor_to_base' => '2.500000', 'is_active' => true]],
            'lot_registrations' => [
                ['branch_id' => $branch->id, 'lot_code' => ' g260427d03 ', 'initial_kg' => '0.200', 'initial_meters' => '0.100'],
                ['branch_id' => $branch->id, 'lot_code' => 'G260427D04', 'initial_kg' => '0.400', 'initial_meters' => '0.200'],
                ['branch_id' => $branch->id, 'lot_code' => '12345', 'initial_kg' => '0.600', 'initial_meters' => null],
            ],
        ];
    }

    public static function profile(bool $enabled = true, bool $reserveQuotation = false): BusinessProfile
    {
        return BusinessProfile::query()->create([
            'name' => 'Perfil lotes '.uniqid(),
            'business_type' => 'mixed',
            'status' => 'active',
            'configuration' => BusinessProfileConfiguration::normalized([
                'modules' => ['inventory' => true, 'reservations' => true],
                'capabilities' => ['uses_inventory' => true, 'uses_lots' => true, 'uses_reservations' => true],
                'inventory' => ['lot_tracking_enabled' => $enabled],
                'sales' => ['reserve_lots_on_quotation' => $reserveQuotation, 'inventory_discount_timing' => 'sale_note'],
            ]),
            'applied_at' => now(),
        ]);
    }

    public static function branch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Sucursal lotes',
            'code' => 'LOT-'.uniqid(),
            'barcode' => 'BR-LOT-'.uniqid(),
            'is_active' => true,
        ]);
    }

    public static function user(Branch $branch, string $role, array $permissions = []): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $modelRole = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $modelRole->syncPermissions($permissions);
        $user = User::factory()->create(['branch_id' => $branch->id, 'email_verified_at' => now()]);
        $user->assignRole($modelRole);

        return $user;
    }

    public static function product(Branch $branch): Product
    {
        $product = Product::query()->create([
            'name' => 'Producto lote '.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'barcode' => 'BC-'.uniqid(),
            'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
            'base_unit' => 'm',
            'item_type' => 'physical',
            'is_sellable' => true,
            'is_purchasable' => true,
            'is_inventory_item' => true,
            'requires_lot' => true,
            'purchase_price' => '2.0000',
            'sale_price' => '3.0000',
            'is_active' => true,
        ]);
        ProductBranchStock::query()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'available_meters' => '0.000',
            'reserved_meters' => '0.000',
            'is_enabled' => true,
        ]);

        return $product;
    }

    public static function lot(Product $product, Branch $branch, string $code = 'LT2026A01'): ProductCoil
    {
        return ProductCoil::query()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'lot_code' => $code,
            'lot_number' => $code,
            'available_meters' => '0.000',
            'initial_meters' => '0.000',
            'status' => 'depleted',
        ]);
    }
}
