<?php

declare(strict_types=1);

use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\MaintenanceScenario;

it('no modifica la numeracion de ventas al purgar proveedores', function () {
    $user = MaintenanceScenario::user();
    $sequence = MaintenanceScenario::sequence($user->branch_id);
    Supplier::query()->create(['name' => 'Proveedor a depurar', 'is_active' => true]);
    $options = MaintenanceScenario::purgeOptions($user, ['suppliers'], ['reset_sequences' => true]);

    app(DatabasePurgeService::class)->executePurge($options, $user);

    expect(Supplier::query()->count())->toBe(0)->and($sequence->fresh()->next_number)->toBe(17);
});

it('impide reiniciar un correlativo fuera del alcance cuando conserva documentos en papelera', function () {
    $user = MaintenanceScenario::user();
    $sequence = MaintenanceScenario::sequence($user->branch_id);
    $sale = MaintenanceScenario::sale($user);
    $sale->delete();
    $options = MaintenanceScenario::purgeOptions($user, ['suppliers'], ['reset_sequences' => true, 'sequence_ids' => [$sequence->id]]);

    expect(fn () => app(DatabasePurgeService::class)->executePurge($options, $user))->toThrow(ValidationException::class);
    expect($sequence->fresh()->next_number)->toBe(17)->and($sale->fresh()->trashed())->toBeTrue();
});

it('vaciar la papelera nunca reinicia secuencias aunque el cliente lo solicite', function () {
    $user = MaintenanceScenario::user();
    $sequence = MaintenanceScenario::sequence($user->branch_id);
    MaintenanceScenario::sale($user)->delete();
    $active = MaintenanceScenario::sale($user, 'QA-NV-000002');
    $options = MaintenanceScenario::purgeOptions($user, ['sales'], ['only_soft_deleted' => true, 'reset_sequences' => true]);
    app(DatabasePurgeService::class)->executePurge($options, $user);

    expect($sequence->fresh()->next_number)->toBe(17);
    $this->assertDatabaseHas('sales', ['id' => $active->id]);
});

it('reinicia solo la secuencia elegida y emite el siguiente documento por el flujo HTTP sin duplicados', function () {
    $user = MaintenanceScenario::user();
    $sequence = MaintenanceScenario::sequence($user->branch_id);
    $otherBranch = Branch::query()->create(['name' => 'Otra sucursal', 'code' => 'QA-MAINT-2', 'barcode' => 'QA-MAINT-2', 'is_active' => true]);
    $otherSequence = MaintenanceScenario::sequence($otherBranch->id, 'QA-OTRA-');
    MaintenanceScenario::sale($user);
    MaintenanceScenario::sale($user, 'QA-OTRA-000001', $otherBranch->id);
    $options = MaintenanceScenario::purgeOptions($user, ['sales'], ['reset_sequences' => true, 'sequence_ids' => [$sequence->id]]);
    app(DatabasePurgeService::class)->executePurge($options, $user);

    expect($sequence->fresh()->next_number)->toBe(1)->and($otherSequence->fresh()->next_number)->toBe(17);
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create(['name' => 'Perfil emisión QA', 'business_type' => 'mixed', 'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'sales' => ['workflow' => 'direct_sale', 'quotation_mode' => 'optional', 'customer_required' => false, 'customer_mode' => 'optional'],
            'cash' => ['required_to_sell' => false],
        ]), 'applied_at' => now()]);
    SystemCacheInvalidator::bumpOperational();
    $type = SaleType::query()->create(['name' => 'Contado QA', 'is_active' => true]);
    $currency = Currency::query()->create(['name' => 'Bolivianos QA', 'code' => 'BOB', 'symbol' => 'Bs', 'exchange_rate_to_bob' => 1, 'is_base' => true, 'is_active' => true]);
    $product = Product::query()->create(['name' => 'Producto emisión', 'sku' => 'QA-EMISION', 'barcode' => 'QA-EMISION', 'base_unit' => 'M',
        'allowed_units' => ['M'], 'sale_price' => 10, 'inventory_tracking_mode' => 'global', 'is_active' => true]);
    ProductBranchStock::query()->create(['branch_id' => $user->branch_id, 'product_id' => $product->id, 'available_meters' => 10, 'reserved_meters' => 0]);

    $this->actingAs($user)->post(route('sales.store'), [
        'document_type' => 'sale_note', 'branch_id' => $user->branch_id, 'sale_type_id' => $type->id, 'currency_id' => $currency->id,
        'advance_mode' => 'none', 'sold_at' => now()->format('Y-m-d H:i:s'),
        'items' => [['product_id' => $product->id, 'description' => 'Emisión posterior', 'unit_label' => 'M', 'meters' => 1, 'unit_price' => 10, 'discount_amount' => 0]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('sales', ['receipt_number' => 'QA-NV-000001', 'branch_id' => $user->branch_id]);
    expect($sequence->fresh()->next_number)->toBe(2)->and($otherSequence->fresh()->next_number)->toBe(17);
});
