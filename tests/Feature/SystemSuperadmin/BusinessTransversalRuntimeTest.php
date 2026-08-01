<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\BusinessAttachment;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessCurrencyService;
use App\Modules\SystemSuperadmin\Services\BusinessAttachmentService;
use App\Modules\SystemSuperadmin\Services\BusinessLicensePolicyService;
use App\Modules\SystemSuperadmin\Services\BusinessNotificationRuleService;
use App\Modules\SystemSuperadmin\Services\BusinessPriceListService;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessPrinterProfileService;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use App\Modules\SystemSuperadmin\Services\DynamicRelationshipService;
use App\Modules\SystemSuperadmin\Services\DynamicFormService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

it('aplica permisos por campo sensible desde el perfil activo', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal permisos campo',
        'code' => 'FIELD-PERM',
        'barcode' => 'BR-FIELD-PERM',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'name' => 'Sucursal sin permiso campo',
        'code' => 'FIELD-PERM-2',
        'barcode' => 'BR-FIELD-PERM-2',
        'is_active' => true,
    ]);
    BusinessProfile::query()->create([
        'name' => 'Perfil permisos por campo',
        'business_type' => 'health_clinic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_fields' => true,
                'uses_field_level_permissions' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_fields_engine' => true,
                'field_permissions_engine' => true,
            ],
            'field_permissions' => [
                'default_sensitive_visibility' => 'deny',
                'rules' => [
                    [
                        'entity' => 'customer',
                        'field' => 'historia_clinica',
                        'actions' => ['view'],
                        'roles' => ['medico'],
                        'branches' => [$branch->id],
                        'flows' => ['consulta'],
                        'states' => ['en_atencion'],
                        'effect' => 'allow',
                    ],
                    [
                        'entity' => 'customer',
                        'field' => 'historia_clinica',
                        'actions' => ['edit'],
                        'roles' => ['director_medico'],
                        'branches' => [$branch->id],
                        'effect' => 'allow',
                    ],
                ],
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'historia_clinica',
        'label' => 'Historia clinica',
        'type' => 'text',
        'visible_in_forms' => true,
        'visible_in_reports' => true,
        'is_exportable' => true,
        'is_sensitive' => true,
        'is_active' => true,
    ]);

    $medicoRole = Role::findOrCreate('medico', 'web');
    $directorRole = Role::findOrCreate('director_medico', 'web');
    $medico = User::factory()->create(['branch_id' => $branch->id]);
    $director = User::factory()->create(['branch_id' => $branch->id]);
    $sinPermiso = User::factory()->create(['branch_id' => $branch->id]);
    $otraSucursal = User::factory()->create(['branch_id' => $otherBranch->id]);
    $medico->assignRole($medicoRole);
    $director->assignRole($directorRole);

    $service = app(CustomFieldRuntimeService::class);
    $context = ['branch_id' => $branch->id, 'flow' => 'consulta', 'state' => 'en_atencion'];

    expect(collect($service->definitionsForSurface('customer', 'report', false, $sinPermiso, $context))->pluck('code')->all())->toBe([])
        ->and(collect($service->definitionsForSurface('customer', 'report', false, $otraSucursal, ['branch_id' => $otherBranch->id, 'flow' => 'consulta', 'state' => 'en_atencion']))->pluck('code')->all())->toBe([])
        ->and(collect($service->definitionsForSurface('customer', 'report', false, $medico, $context))->pluck('code')->all())->toBe(['historia_clinica'])
        ->and(collect($service->editableDefinitionsFor('customer', $medico, $context))->pluck('code')->all())->toBe([])
        ->and(collect($service->editableDefinitionsFor('customer', $director, ['branch_id' => $branch->id]))->pluck('code')->all())->toBe(['historia_clinica']);
});

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

it('resuelve relaciones dinamicas solo cuando el perfil activo las habilita', function () {
    DynamicEntity::query()->create([
        'code' => 'vehiculo',
        'label' => 'Vehiculo',
        'base_entity' => 'customer',
        'mode' => 'optional',
        'retention_policy' => 'standard',
        'is_visible' => true,
        'is_active' => true,
    ]);
    $definition = DynamicRelationshipDefinition::query()->create([
        'source_entity_type' => 'customer',
        'target_entity_type' => 'vehiculo',
        'code' => 'vehiculos_cliente',
        'label' => 'Vehiculos del cliente',
        'type' => 'one_to_many',
        'allows_multiple' => true,
        'cascade_behavior' => 'restrict',
        'is_active' => true,
    ]);

    $service = app(DynamicRelationshipService::class);
    expect($service->definitionsFor('customer'))->toBe([]);

    BusinessProfile::query()->create([
        'name' => 'Perfil relaciones dinamicas',
        'business_type' => 'auto_mechanic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_relationships' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_relationships_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $value = $service->attach($definition, 15, 'ABC-123', ['placa' => 'ABC-123']);

    expect(collect($service->definitionsFor('customer'))->pluck('code')->all())->toBe(['vehiculos_cliente'])
        ->and($value->source_entity_type)->toBe('customer')
        ->and($value->target_entity_type)->toBe('vehiculo')
        ->and($value->payload)->toBe(['placa' => 'ABC-123'])
        ->and(collect($service->valuesFor('customer', 15))->pluck('target_record_id')->all())->toBe(['ABC-123']);
});

it('bloquea multiples destinos cuando la relacion dinamica no lo permite', function () {
    DynamicEntity::query()->create([
        'code' => 'garantia',
        'label' => 'Garantia',
        'base_entity' => 'customer',
        'mode' => 'optional',
        'retention_policy' => 'financial',
        'is_visible' => true,
        'is_active' => true,
    ]);
    $definition = DynamicRelationshipDefinition::query()->create([
        'source_entity_type' => 'customer',
        'target_entity_type' => 'garantia',
        'code' => 'garantia_principal',
        'label' => 'Garantia principal',
        'type' => 'one_to_one',
        'allows_multiple' => false,
        'cascade_behavior' => 'restrict',
        'is_active' => true,
    ]);
    BusinessProfile::query()->create([
        'name' => 'Perfil relacion unica',
        'business_type' => 'loans',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_relationships' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_relationships_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $service = app(DynamicRelationshipService::class);
    $service->attach($definition, 99, 'GAR-1');
    $service->attach($definition, 99, 'GAR-2');
})->throws(ValidationException::class);

it('guarda adjuntos solo cuando el perfil activo habilita el motor', function () {
    Storage::fake('local');

    $definition = AttachmentDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'foto_ci',
        'label' => 'Foto CI',
        'purpose' => 'identity_document',
        'allowed_extensions' => ['jpg'],
        'allowed_mime_types' => ['image/jpeg'],
        'max_file_mb' => 2,
        'storage_disk' => 'local',
        'path_prefix' => 'business-attachments/clientes',
        'is_sensitive' => true,
        'requires_signed_url' => true,
        'audit_downloads' => true,
        'is_active' => true,
    ]);

    $service = app(BusinessAttachmentService::class);
    expect($service->definitionsFor('customer'))->toBe([]);

    BusinessProfile::query()->create([
        'name' => 'Perfil adjuntos',
        'business_type' => 'health_clinic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_attachments' => true,
            ],
            'feature_flags' => [
                'attachments_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $file = UploadedFile::fake()->create('ci.jpg', 300, 'image/jpeg');
    $attachment = $service->store($definition, 123, $file, User::factory()->create(), ['lado' => 'frontal']);

    Storage::disk('local')->assertExists($attachment->path);

    expect(collect($service->definitionsFor('customer'))->pluck('code')->all())->toBe(['foto_ci'])
        ->and($attachment->entity_type)->toBe('customer')
        ->and($attachment->record_id)->toBe('123')
        ->and($attachment->is_sensitive)->toBeTrue()
        ->and($attachment->payload)->toBe(['lado' => 'frontal'])
        ->and($attachment->checksum)->not->toBeNull()
        ->and($service->attachmentsFor('customer', 123))->toBe([])
        ->and(collect($service->attachmentsFor('customer', 123, true))->pluck('id')->all())->toBe([$attachment->id]);
});

it('bloquea adjuntos con extension no permitida', function () {
    Storage::fake('local');

    $definition = AttachmentDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'contrato_pdf',
        'label' => 'Contrato PDF',
        'purpose' => 'contract',
        'allowed_extensions' => ['pdf'],
        'allowed_mime_types' => ['application/pdf'],
        'max_file_mb' => 2,
        'storage_disk' => 'local',
        'path_prefix' => 'business-attachments/contratos',
        'is_active' => true,
    ]);
    BusinessProfile::query()->create([
        'name' => 'Perfil adjuntos PDF',
        'business_type' => 'loans',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => ['uses_attachments' => true],
            'feature_flags' => ['attachments_engine' => true],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    app(BusinessAttachmentService::class)->store($definition, 1, UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'), User::factory()->create());
})->throws(ValidationException::class);

it('resuelve formularios por flujo solo cuando el perfil activo habilita el motor', function () {
    CustomFieldDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'diagnostico_final',
        'label' => 'Diagnostico final',
        'type' => 'text',
        'visible_in_forms' => true,
        'is_active' => true,
    ]);
    CustomFieldDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'costo_interno',
        'label' => 'Costo interno',
        'type' => 'currency',
        'visible_in_forms' => true,
        'is_sensitive' => true,
        'is_active' => true,
    ]);
    $form = DynamicFormDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'cierre_orden',
        'name' => 'Cierre de orden',
        'flow' => 'service_order_close',
        'surface' => 'form',
        'submit_label' => 'Cerrar orden',
        'is_active' => true,
    ]);
    DynamicFormFieldRule::query()->create([
        'dynamic_form_definition_id' => $form->id,
        'field_code' => 'diagnostico_final',
        'label_override' => 'Diagnostico de cierre',
        'is_required' => true,
        'validation_rules' => ['min:5'],
        'is_active' => true,
    ]);
    DynamicFormFieldRule::query()->create([
        'dynamic_form_definition_id' => $form->id,
        'field_code' => 'costo_interno',
        'is_required' => true,
        'is_active' => true,
    ]);

    $service = app(DynamicFormService::class);
    $context = ['flow' => 'service_order_close', 'surface' => 'form'];

    expect($service->formsFor('service_order', $context))->toBe([]);

    BusinessProfile::query()->create([
        'name' => 'Perfil formularios dinamicos',
        'business_type' => 'technical_service',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_fields' => true,
                'uses_dynamic_forms' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_fields_engine' => true,
                'dynamic_forms_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $resolved = $service->resolve('service_order', 'cierre_orden', null, $context);

    expect($resolved)->not->toBeNull()
        ->and($resolved['submit_label'])->toBe('Cerrar orden')
        ->and(collect($resolved['fields'])->pluck('code')->all())->toBe(['diagnostico_final'])
        ->and($service->rulesForValidation('service_order', 'cierre_orden', null, $context)['diagnostico_final'])->toContain('required')
        ->and($service->validate('service_order', 'cierre_orden', ['diagnostico_final' => 'Trabajo finalizado'], null, $context))->toBe(['diagnostico_final' => 'Trabajo finalizado']);

    $service->validate('service_order', 'cierre_orden', ['diagnostico_final' => 'abc'], null, $context);
})->throws(ValidationException::class);

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
