<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Services\BusinessCurrencyService;
use App\Modules\SystemSuperadmin\Services\BusinessLicensePolicyService;
use App\Modules\SystemSuperadmin\Services\BusinessNotificationRuleService;
use App\Modules\SystemSuperadmin\Services\BusinessPriceListService;
use App\Modules\SystemSuperadmin\Services\BusinessPrinterProfileService;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('valida campos personalizados activos con mensajes de Laravel en espanol', function () {
    CustomFieldDefinition::query()->create([
        'entity_type' => 'product',
        'code' => 'talla',
        'label' => 'Talla',
        'type' => 'select',
        'options' => ['S', 'M', 'L'],
        'validation_rules' => ['in:S,M,L'],
        'is_required' => true,
        'visible_in_forms' => true,
        'is_active' => true,
    ]);

    $service = app(CustomFieldRuntimeService::class);

    expect($service->validateValues('product', ['talla' => 'M']))->toBe(['talla' => 'M']);

    $service->validateValues('product', ['talla' => 'XL']);
})->throws(ValidationException::class);

it('resuelve transiciones de estado con permisos finos', function () {
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'pendiente',
        'label' => 'Pendiente',
        'allowed_transitions' => ['cerrado'],
        'is_initial' => true,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'cerrado',
        'label' => 'Cerrado',
        'required_permission' => 'cerrar ordenes',
        'is_final' => true,
        'is_active' => true,
    ]);

    $permission = Permission::findOrCreate('cerrar ordenes', 'web');
    $role = Role::findOrCreate('tecnico jefe', 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    $service = app(BusinessStateTransitionService::class);

    expect($service->canTransition('service_order', 'pendiente', 'cerrado'))->toBeFalse()
        ->and($service->canTransition('service_order', 'pendiente', 'cerrado', $user))->toBeTrue()
        ->and($service->initialStates('service_order'))->toHaveCount(1);
});

it('convierte monedas hacia la moneda base y mantiene la base activa', function () {
    BusinessCurrency::query()->create([
        'code' => 'BOB',
        'name' => 'Boliviano',
        'symbol' => 'Bs',
        'exchange_rate_to_base' => 1,
        'rounding_decimals' => 1,
        'is_base' => true,
        'cash_enabled' => true,
        'is_active' => true,
    ]);
    BusinessCurrency::query()->create([
        'code' => 'USD',
        'name' => 'Dolar',
        'symbol' => '$',
        'exchange_rate_to_base' => 10,
        'rounding_decimals' => 1,
        'is_base' => false,
        'cash_enabled' => true,
        'is_active' => true,
    ]);

    $service = app(BusinessCurrencyService::class);

    expect($service->base()?->code)->toBe('BOB')
        ->and($service->convertToBase(12.55, 'USD'))->toBe(125.5)
        ->and($service->convertToBase(12.55, 'XXX'))->toBe(12.55);
});

it('resuelve impresoras y listas de precio priorizando sucursal especifica', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal QA transversal',
        'code' => 'QA-TR',
        'barcode' => 'BR-QA-TR',
        'is_active' => true,
    ]);

    PrinterProfile::query()->create([
        'code' => 'caja-global',
        'name' => 'Caja global',
        'area' => 'cashier',
        'paper_type' => 'thermal_80',
        'copies' => 1,
        'is_active' => true,
    ]);
    PrinterProfile::query()->create([
        'branch_id' => $branch->id,
        'code' => 'caja-sucursal',
        'name' => 'Caja sucursal',
        'area' => 'cashier',
        'paper_type' => 'thermal_80',
        'copies' => 2,
        'is_active' => true,
    ]);

    PriceList::query()->create([
        'code' => 'retail-global',
        'name' => 'Retail global',
        'channel' => 'store',
        'currency_code' => 'BOB',
        'is_active' => true,
    ]);
    PriceList::query()->create([
        'branch_id' => $branch->id,
        'code' => 'retail-sucursal',
        'name' => 'Retail sucursal',
        'channel' => 'store',
        'currency_code' => 'BOB',
        'is_active' => true,
    ]);

    expect(app(BusinessPrinterProfileService::class)->resolve('cashier', $branch->id)?->code)->toBe('caja-sucursal')
        ->and(app(BusinessPriceListService::class)->activeFor('store', $branch->id)->pluck('code')->all())
        ->toBe(['retail-sucursal', 'retail-global']);
});

it('evalua licencias y reglas de notificacion activas', function () {
    BusinessModuleLicense::query()->create([
        'module' => 'billing',
        'is_enabled' => false,
    ]);
    BusinessModuleLicense::query()->create([
        'module' => 'pos',
        'is_enabled' => true,
        'support_until' => now()->addDay(),
    ]);
    NotificationRule::query()->create([
        'code' => 'stock_bajo',
        'name' => 'Stock bajo',
        'trigger' => 'stock_low',
        'channels' => ['system'],
        'is_active' => true,
    ]);
    NotificationRule::query()->create([
        'code' => 'stock_bajo_inactivo',
        'name' => 'Stock bajo inactivo',
        'trigger' => 'stock_low',
        'channels' => ['system'],
        'is_active' => false,
    ]);

    expect(app(BusinessLicensePolicyService::class)->moduleEnabled('billing'))->toBeFalse()
        ->and(app(BusinessLicensePolicyService::class)->moduleEnabled('pos'))->toBeTrue()
        ->and(app(BusinessLicensePolicyService::class)->moduleEnabled('unknown'))->toBeTrue()
        ->and(app(BusinessNotificationRuleService::class)->activeFor('stock_low')->pluck('code')->all())
        ->toBe(['stock_bajo']);
});
