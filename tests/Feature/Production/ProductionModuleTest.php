<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionFormula;
use App\Modules\Production\Models\ProductionOrderStage;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function productionUser(array $permissions): User
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil test produccion',
        'business_type' => 'factory',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['production' => true],
        ]),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'produccion-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Produccion central',
        'code' => 'PROD-'.$suffix,
        'barcode' => 'BR-PROD-'.$suffix,
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

function productionProduct(string $sku, string $name, string $mode = Product::TRACKING_GLOBAL): Product
{
    return Product::query()->create([
        'name' => $name,
        'sku' => $sku,
        'barcode' => 'PR-'.$sku,
        'base_unit' => 'M',
        'allowed_units' => ['M'],
        'inventory_tracking_mode' => $mode,
        'purchase_price' => 10,
        'sale_price' => 15,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
}

it('registra produccion global consumiendo entrada y aumentando salida', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-001', 'Materia prima');
    $output = productionProduct('TERM-001', 'Producto terminado');

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $input->id,
        'available_meters' => 100,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('production.store'), [
            'branch_id' => $user->branch_id,
            'order_number' => 'OP-001',
            'produced_at' => now()->format('Y-m-d H:i:s'),
            'input_product_id' => $input->id,
            'input_product_coil_id' => null,
            'output_product_id' => $output->id,
            'input_meters' => 40,
            'output_meters' => 35,
            'waste_meters' => 5,
            'output_coil_barcode' => null,
            'output_lot_number' => null,
            'notes' => 'Prueba de produccion',
        ])
        ->assertRedirect(route('production.index'));

    expect((float) ProductBranchStock::query()->where('product_id', $input->id)->value('available_meters'))->toBe(60.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $output->id)->value('available_meters'))->toBe(35.0)
        ->and(ProductionOrder::query()->where('order_number', 'OP-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'production_input_global')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('type', 'production_output_global')->exists())->toBeTrue();
});

it('bloquea produccion cuando no hay stock global suficiente', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-002', 'Materia corta');
    $output = productionProduct('TERM-002', 'Salida corta');

    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $input->id,
        'available_meters' => 5,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('production.index'))
        ->post(route('production.store'), [
            'branch_id' => $user->branch_id,
            'order_number' => 'OP-002',
            'produced_at' => now()->format('Y-m-d H:i:s'),
            'input_product_id' => $input->id,
            'output_product_id' => $output->id,
            'input_meters' => 10,
            'output_meters' => 9,
            'waste_meters' => 1,
        ])
        ->assertRedirect(route('production.index'))
        ->assertSessionHasErrors('input_meters');
});

it('lista produccion con permiso y bloquea sin permiso', function () {
    $user = productionUser(['production.view']);

    $this->actingAs($user)
        ->get(route('production.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Production/Index', false)
            ->has('orders.data', 0)
        );

    $blocked = productionUser([]);

    $this->actingAs($blocked)
        ->get(route('production.index'))
        ->assertForbidden();
});

it('crea formula de produccion con insumos y etapas', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-F01', 'Insumo formula');
    $output = productionProduct('TERM-F01', 'Producto formula');

    $this->actingAs($user)
        ->post(route('production.formulas.store'), [
            'branch_id' => $user->branch_id,
            'output_product_id' => $output->id,
            'code' => 'FORM-001',
            'name' => 'Formula prueba',
            'yield_quantity' => 10,
            'expected_waste_percentage' => 5,
            'standard_labor_cost' => 25,
            'standard_overhead_cost' => 10,
            'items' => [[
                'input_product_id' => $input->id,
                'quantity' => 12,
                'unit_label' => 'M',
                'waste_percentage' => 2,
            ]],
            'stages' => [[
                'name' => 'Corte',
                'description' => 'Cortar materia prima',
                'estimated_minutes' => 30,
                'requires_confirmation' => true,
            ]],
        ])
        ->assertRedirect();

    $formula = ProductionFormula::query()->with(['items', 'stages'])->firstOrFail();

    expect($formula->items)->toHaveCount(1)
        ->and($formula->stages)->toHaveCount(1)
        ->and($formula->standard_labor_cost)->toBe('25.0000');
});

it('produce usando formula bom con multiples insumos, etapas y costeo real', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $inputA = productionProduct('MAT-BOM-A', 'Insumo A');
    $inputB = productionProduct('MAT-BOM-B', 'Insumo B');
    $output = productionProduct('TERM-BOM', 'Producto terminado BOM');
    $inputA->update(['purchase_price' => 4]);
    $inputB->update(['purchase_price' => 6]);

    foreach ([[$inputA, 100], [$inputB, 100]] as [$product, $quantity]) {
        ProductBranchStock::query()->create([
            'branch_id' => $user->branch_id,
            'product_id' => $product->id,
            'available_meters' => $quantity,
            'reserved_meters' => 0,
        ]);
    }

    $formula = ProductionFormula::query()->create([
        'branch_id' => $user->branch_id,
        'output_product_id' => $output->id,
        'code' => 'FORM-BOM',
        'name' => 'Formula BOM',
        'yield_quantity' => 10,
        'standard_labor_cost' => 30,
        'standard_overhead_cost' => 15,
        'is_active' => true,
    ]);
    $formula->items()->create([
        'input_product_id' => $inputA->id,
        'quantity' => 8,
        'unit_label' => 'M',
        'sort_order' => 1,
    ]);
    $formula->items()->create([
        'input_product_id' => $inputB->id,
        'quantity' => 3,
        'unit_label' => 'M',
        'sort_order' => 2,
    ]);
    $formula->stages()->create([
        'name' => 'Mezcla',
        'estimated_minutes' => 20,
        'sort_order' => 1,
    ]);

    $this->actingAs($user)
        ->post(route('production.store'), [
            'branch_id' => $user->branch_id,
            'order_number' => 'OP-BOM-001',
            'production_formula_id' => $formula->id,
            'output_meters' => 20,
            'waste_meters' => 1,
            'labor_cost' => 30,
            'overhead_cost' => 15,
        ])
        ->assertRedirect(route('production.index'));

    $order = ProductionOrder::query()->with(['inputs', 'stages'])->where('order_number', 'OP-BOM-001')->firstOrFail();

    expect($order->inputs)->toHaveCount(2)
        ->and($order->stages)->toHaveCount(1)
        ->and((float) ProductBranchStock::query()->where('product_id', $inputA->id)->value('available_meters'))->toBe(84.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $inputB->id)->value('available_meters'))->toBe(94.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $output->id)->value('available_meters'))->toBe(20.0)
        ->and($order->input_cost)->toBe('100.0000')
        ->and($order->total_cost)->toBe('145.0000')
        ->and($order->unit_cost)->toBe('7.2500');
});

it('actualiza etapas de una orden de produccion formal', function () {
    $user = productionUser(['production.view', 'production.manage']);
    $input = productionProduct('MAT-STAGE', 'Insumo etapa');
    $output = productionProduct('TERM-STAGE', 'Salida etapa');

    $order = ProductionOrder::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'input_product_id' => $input->id,
        'output_product_id' => $output->id,
        'order_number' => 'OP-STAGE-001',
        'produced_at' => now(),
        'input_meters' => 1,
        'output_meters' => 1,
        'status' => ProductionOrder::STATUS_COMPLETED,
    ]);
    $stage = $order->stages()->create([
        'name' => 'Empaque',
        'status' => ProductionOrderStage::STATUS_PENDING,
        'sort_order' => 1,
    ]);

    $this->actingAs($user)
        ->patch(route('production.stages.update', [$order, $stage]), [
            'status' => ProductionOrderStage::STATUS_COMPLETED,
            'actual_minutes' => 12,
            'notes' => 'Etapa verificada',
        ])
        ->assertRedirect();

    expect($stage->fresh()->status)->toBe(ProductionOrderStage::STATUS_COMPLETED)
        ->and($stage->fresh()->completed_at)->not->toBeNull();
});
