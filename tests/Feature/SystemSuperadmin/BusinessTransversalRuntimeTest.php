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
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessCurrencyService;
use App\Modules\SystemSuperadmin\Services\BusinessLicensePolicyService;
use App\Modules\SystemSuperadmin\Services\BusinessNotificationRuleService;
use App\Modules\SystemSuperadmin\Services\BusinessPriceListService;
use App\Modules\SystemSuperadmin\Services\BusinessPrinterProfileService;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use Illuminate\Support\Facades\Crypt;
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

it('filtra atributos por superficie y oculta sensibles salvo permiso explicito', function () {
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'telefono_contacto',
        'label' => 'Telefono de contacto',
        'type' => 'phone',
        'visible_in_forms' => true,
        'visible_in_table' => true,
        'visible_in_documents' => true,
        'visible_in_reports' => true,
        'is_exportable' => true,
        'is_sensitive' => false,
        'is_active' => true,
    ]);
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'historia_clinica',
        'label' => 'Historia clinica',
        'type' => 'text',
        'visible_in_forms' => true,
        'visible_in_table' => false,
        'visible_in_documents' => false,
        'visible_in_reports' => true,
        'is_exportable' => false,
        'is_sensitive' => true,
        'is_active' => true,
    ]);

    $service = app(CustomFieldRuntimeService::class);

    expect(collect($service->definitionsForSurface('customer', 'table'))->pluck('code')->all())->toBe(['telefono_contacto'])
        ->and(collect($service->definitionsForSurface('customer', 'report'))->pluck('code')->all())->toBe(['telefono_contacto'])
        ->and(collect($service->definitionsForSurface('customer', 'report', true))->pluck('code')->all())->toBe(['historia_clinica', 'telefono_contacto'])
        ->and(collect($service->definitionsForSurface('customer', 'export'))->pluck('code')->all())->toBe(['telefono_contacto']);
});

it('valida limites, opciones y prepara valores cifrados de atributos', function () {
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'monto_garantia',
        'label' => 'Monto garantia',
        'type' => 'currency',
        'min_value' => 100,
        'max_value' => 10000,
        'is_required' => true,
        'is_active' => true,
    ]);
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'tipo_cliente',
        'label' => 'Tipo cliente',
        'type' => 'select',
        'options' => ['nuevo', 'recurrente'],
        'is_required' => true,
        'default_value' => ['valor' => 'nuevo'],
        'is_active' => true,
    ]);
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'pin_privado',
        'label' => 'PIN privado',
        'type' => 'text',
        'is_encrypted' => true,
        'is_active' => true,
    ]);

    $service = app(CustomFieldRuntimeService::class);
    $prepared = $service->prepareValuesForStorage('customer', [
        'monto_garantia' => 500,
        'pin_privado' => 'abc123',
    ]);

    expect($prepared['tipo_cliente'])->toBe('nuevo')
        ->and($prepared['pin_privado']['encrypted'])->toBeTrue()
        ->and($prepared['pin_privado']['value'])->not->toBe('abc123')
        ->and(json_decode(Crypt::decryptString($prepared['pin_privado']['value']), true))->toBe('abc123');

    $service->validateValues('customer', [
        'monto_garantia' => 50,
        'tipo_cliente' => 'bloqueado',
    ]);
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

it('resuelve workflows formales con permisos, validaciones y acciones', function () {
    $workflow = WorkflowDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'reparacion',
        'name' => 'Reparacion',
        'initial_state_code' => 'recibido',
        'final_state_codes' => ['entregado'],
        'is_default' => true,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'workflow_code' => 'reparacion',
        'code' => 'recibido',
        'label' => 'Recibido',
        'is_initial' => true,
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'workflow_code' => 'reparacion',
        'code' => 'diagnostico',
        'label' => 'Diagnostico',
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'workflow_code' => 'reparacion',
        'code' => 'entregado',
        'label' => 'Entregado',
        'is_final' => true,
        'is_active' => true,
    ]);
    WorkflowTransition::query()->create([
        'workflow_definition_id' => $workflow->id,
        'from_state_code' => 'recibido',
        'to_state_code' => 'diagnostico',
        'label' => 'Diagnosticar',
        'required_permission' => 'diagnosticar ordenes',
        'validations' => ['tecnico_asignado' => true],
        'actions' => ['crear_alerta' => true],
        'is_active' => true,
    ]);

    $permission = Permission::findOrCreate('diagnosticar ordenes', 'web');
    $role = Role::findOrCreate('tecnico diagnostico', 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    $service = app(BusinessStateTransitionService::class);
    $blocked = $service->transitionCheck('service_order', 'recibido', 'diagnostico');
    $allowed = $service->transitionCheck('service_order', 'recibido', 'diagnostico', $user);

    expect($service->defaultWorkflow('service_order')?->code)->toBe('reparacion')
        ->and($blocked['allowed'])->toBeFalse()
        ->and($allowed['allowed'])->toBeTrue()
        ->and($allowed['validations'])->toBe(['tecnico_asignado' => true])
        ->and($allowed['actions'])->toBe(['crear_alerta' => true])
        ->and($service->canTransition('service_order', 'recibido', 'entregado', $user))->toBeFalse()
        ->and(collect($service->availableTransitions('service_order', 'recibido', $user))->pluck('to_state_code')->all())->toBe(['diagnostico']);
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
