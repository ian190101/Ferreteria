<?php

use App\Models\User;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\BusinessAttachment;
use App\Modules\SystemSuperadmin\Models\BusinessCommercialFlowRule;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessIntegrationConnector;
use App\Modules\SystemSuperadmin\Models\BusinessModuleLicense;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\BranchOperationPolicy;
use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicDocumentTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\NotificationRule;
use App\Modules\SystemSuperadmin\Models\OperationalTrace;
use App\Modules\SystemSuperadmin\Models\PriceList;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessCurrencyService;
use App\Modules\SystemSuperadmin\Services\BusinessCommercialFlowRuleService;
use App\Modules\SystemSuperadmin\Services\AdvancedBranchPolicyService;
use App\Modules\SystemSuperadmin\Services\BusinessAttachmentService;
use App\Modules\SystemSuperadmin\Services\BusinessIntegrationConnectorService;
use App\Modules\SystemSuperadmin\Services\BusinessLicensePolicyService;
use App\Modules\SystemSuperadmin\Services\BusinessNotificationRuleService;
use App\Modules\SystemSuperadmin\Services\BusinessPriceListService;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessPrinterProfileService;
use App\Modules\SystemSuperadmin\Services\BusinessStateTransitionService;
use App\Modules\SystemSuperadmin\Services\CalculationFormulaService;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use App\Modules\SystemSuperadmin\Services\DataGovernanceService;
use App\Modules\SystemSuperadmin\Services\DynamicRelationshipService;
use App\Modules\SystemSuperadmin\Services\DynamicFormService;
use App\Modules\SystemSuperadmin\Services\DynamicTemplateService;
use App\Modules\SystemSuperadmin\Services\FieldPermissionService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
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
                'operational_traceability_engine' => true,
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

it('filtra valores sensibles por campo y audita accesos cuando el perfil lo exige', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal seguridad campo',
        'code' => 'FIELD-SEC',
        'barcode' => 'BR-FIELD-SEC',
        'is_active' => true,
    ]);

    BusinessProfile::query()->create([
        'name' => 'Perfil seguridad por campo',
        'business_type' => 'health_clinic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_fields' => true,
                'uses_field_level_permissions' => true,
                'uses_operational_traceability' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_fields_engine' => true,
                'field_permissions_engine' => true,
                'operational_traceability_engine' => true,
            ],
            'governance' => [
                'audit_sensitive_reads' => true,
            ],
            'traceability' => [
                'enabled' => true,
            ],
            'field_permissions' => [
                'default_sensitive_visibility' => 'deny',
                'rules' => [[
                    'entity' => 'customer',
                    'field' => 'historia_clinica',
                    'actions' => ['view', 'export'],
                    'roles' => ['auditor_clinico'],
                    'branches' => [$branch->id],
                    'effect' => 'allow',
                ]],
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'nombre_visible',
        'label' => 'Nombre visible',
        'type' => 'text',
        'is_exportable' => true,
        'is_active' => true,
    ]);
    $sensitive = CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'historia_clinica',
        'label' => 'Historia clinica',
        'type' => 'text',
        'is_exportable' => true,
        'is_sensitive' => true,
        'is_active' => true,
    ]);

    $role = Role::findOrCreate('auditor_clinico', 'web');
    $allowedUser = User::factory()->create(['branch_id' => $branch->id]);
    $blockedUser = User::factory()->create(['branch_id' => $branch->id]);
    $allowedUser->assignRole($role);

    $service = app(FieldPermissionService::class);
    $values = [
        'nombre_visible' => 'Maria Perez',
        'historia_clinica' => 'Diagnostico reservado',
        'campo_no_definido' => 'No debe salir',
    ];
    $context = ['entity_id' => 44, 'branch_id' => $branch->id];

    expect($service->filterValuesForSurface('customer', $values, 'export', $blockedUser, $context))->toBe([
        'nombre_visible' => 'Maria Perez',
    ])
        ->and($service->filterValuesForSurface('customer', $values, 'export', $allowedUser, $context))->toBe([
            'nombre_visible' => 'Maria Perez',
            'historia_clinica' => 'Diagnostico reservado',
        ]);

    $service->recordSensitiveFieldChange($sensitive, $allowedUser, $context, 'antes', 'despues');

    expect(OperationalTrace::query()->where('event', 'sensitive_field_exported')->where('entity_type', 'customer')->where('entity_id', 44)->count())->toBe(1)
        ->and(OperationalTrace::query()->where('event', 'sensitive_field_changed')->where('entity_type', 'customer')->where('entity_id', 44)->count())->toBe(1);
});

it('cachea catalogos dinamicos e invalida con la version operativa del sistema', function () {
    Cache::flush();

    BusinessProfile::query()->create([
        'name' => 'Perfil cache runtime dinamico',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['reports' => true],
            'capabilities' => [
                'uses_dynamic_entities' => true,
                'uses_dynamic_fields' => true,
                'uses_report_templates' => true,
            ],
            'feature_flags' => [
                'dynamic_entities_engine' => true,
                'dynamic_fields_engine' => true,
                'report_templates_engine' => true,
            ],
            'performance' => [
                'cache_profile_minutes' => 30,
                'cache_capabilities_minutes' => 60,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $field = CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'dato_cacheado',
        'label' => 'Dato cacheado v1',
        'type' => 'text',
        'is_active' => true,
    ]);
    $template = DynamicReportTemplate::query()->create([
        'code' => 'reporte_cacheado',
        'name' => 'Reporte cacheado v1',
        'module' => 'customers',
        'entity_type' => 'customer',
        'columns' => ['name'],
        'is_active' => true,
    ]);

    $fields = app(CustomFieldRuntimeService::class);
    $templates = app(DynamicTemplateService::class);

    expect(collect($fields->definitionsFor('customer'))->firstWhere('code', 'dato_cacheado')?->label)->toBe('Dato cacheado v1')
        ->and($templates->resolveReport('reporte_cacheado')?->name)->toBe('Reporte cacheado v1');

    $field->update(['label' => 'Dato cacheado v2']);
    $template->update(['name' => 'Reporte cacheado v2']);

    expect(collect($fields->definitionsFor('customer'))->firstWhere('code', 'dato_cacheado')?->label)->toBe('Dato cacheado v1')
        ->and($templates->resolveReport('reporte_cacheado')?->name)->toBe('Reporte cacheado v1');

    SystemCacheInvalidator::bumpOperational();

    expect(collect($fields->definitionsFor('customer'))->firstWhere('code', 'dato_cacheado')?->label)->toBe('Dato cacheado v2')
        ->and($templates->resolveReport('reporte_cacheado')?->name)->toBe('Reporte cacheado v2');
});

it('aplica gobierno de datos para exportacion sensible y anonimizado controlado', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal gobierno datos',
        'code' => 'GOV-DATA',
        'barcode' => 'BR-GOV-DATA',
        'is_active' => true,
    ]);

    $plainUser = User::factory()->create(['branch_id' => $branch->id]);
    $governanceUser = User::factory()->create(['branch_id' => $branch->id]);
    $role = Role::findOrCreate('gobierno datos', 'web');
    $role->givePermissionTo(Permission::findOrCreate('data-governance.manage', 'web'));
    $role->givePermissionTo(Permission::findOrCreate('exports.sensitive', 'web'));
    $governanceUser->assignRole($role);

    $service = app(DataGovernanceService::class);

    expect($service->enabled())->toBeFalse()
        ->and($service->canExportEntity('customer', $plainUser))->toBeTrue()
        ->and($service->anonymizationAllowed('customer'))->toBeFalse();

    BusinessProfile::query()->create([
        'name' => 'Perfil gobierno datos',
        'business_type' => 'health_clinic',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_data_governance' => true,
                'uses_operational_traceability' => true,
            ],
            'feature_flags' => [
                'data_governance_engine' => true,
                'operational_traceability_engine' => true,
            ],
            'governance' => [
                'allow_anonymization' => true,
                'sensitive_entities' => ['customer'],
                'export_sensitive_requires_permission' => true,
                'retention_policy' => 'legal_hold',
            ],
            'traceability' => ['enabled' => true],
        ]),
        'applied_at' => now(),
        'applied_by' => $governanceUser->id,
    ]);
    Cache::flush();

    DynamicEntity::query()->create([
        'code' => 'customer',
        'label' => 'Cliente sensible',
        'mode' => 'optional',
        'is_visible' => true,
        'is_sensitive' => true,
        'retention_policy' => 'health_record',
        'is_active' => true,
    ]);

    $customer = \App\Modules\Customers\Models\Customer::query()->create([
        'name' => 'Paciente Sensible',
        'document_number' => '1234567',
        'phone' => '70000000',
        'email' => 'paciente@example.com',
        'address' => 'Direccion privada',
        'is_active' => true,
    ]);

    expect($service->enabled())->toBeTrue()
        ->and($service->isSensitiveEntity('customer'))->toBeTrue()
        ->and($service->retentionPolicyFor('customer'))->toBe('health_record')
        ->and($service->canExportEntity('customer', $plainUser))->toBeFalse()
        ->and($service->canExportEntity('customer', $governanceUser))->toBeTrue();

    $anon = $service->anonymizeCustomer($customer, $governanceUser, 'Solicitud formal del titular');

    expect($anon->name)->toBe('Cliente anonimizado #'.$customer->id)
        ->and($anon->document_number)->toBeNull()
        ->and($anon->phone)->toBeNull()
        ->and($anon->email)->toBeNull()
        ->and($anon->address)->toBeNull()
        ->and($anon->is_active)->toBeFalse()
        ->and($anon->interactions()->where('type', 'data_governance')->exists())->toBeTrue()
        ->and(OperationalTrace::query()->where('entity_type', 'customer')->where('entity_id', $customer->id)->where('event', 'customer_anonymized')->exists())->toBeTrue();
});

it('bloquea datasets sensibles en exportaciones sin permiso especial', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal export sensible',
        'code' => 'EXPORT-SENSITIVE',
        'barcode' => 'BR-EXPORT-SENSITIVE',
        'is_active' => true,
    ]);

    BusinessProfile::query()->create([
        'name' => 'Perfil export sensible',
        'business_type' => 'health_clinic',
        'status' => 'active',
            'configuration' => BusinessProfileConfiguration::normalized([
                'modules' => ['exports' => true],
                'capabilities' => ['uses_data_governance' => true],
                'feature_flags' => ['data_governance_engine' => true],
                'governance' => [
                    'sensitive_entities' => ['customer', 'salary'],
                    'export_sensitive_requires_permission' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    foreach (['customers.view', 'payroll.view', 'settings.manage', 'exports.sensitive'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $basicRole = Role::findOrCreate('exportador basico', 'web');
    $basicRole->syncPermissions(['customers.view', 'payroll.view', 'settings.manage']);
    $sensitiveRole = Role::findOrCreate('exportador sensible', 'web');
    $sensitiveRole->syncPermissions(['customers.view', 'payroll.view', 'settings.manage', 'exports.sensitive']);

    $basicUser = User::factory()->create(['branch_id' => $branch->id]);
    $sensitiveUser = User::factory()->create(['branch_id' => $branch->id]);
    $basicUser->assignRole($basicRole);
    $sensitiveUser->assignRole($sensitiveRole);

    $basicRequest = Request::create('/exports');
    $basicRequest->setUserResolver(fn () => $basicUser);
    $sensitiveRequest = Request::create('/exports');
    $sensitiveRequest->setUserResolver(fn () => $sensitiveUser);

    expect(app(ExportDatasetService::class)->catalog($basicRequest))->not->toHaveKeys(['customers', 'payroll'])
        ->and(app(ExportDatasetService::class)->catalog($sensitiveRequest))->toHaveKeys(['customers', 'payroll']);
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

it('resuelve plantillas documentales y reportes solo con motores activos', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal documentos dinamicos',
        'code' => 'DOC-DYN',
        'barcode' => 'BR-DOC-DYN',
        'is_active' => true,
    ]);
    DynamicDocumentTemplate::query()->create([
        'branch_id' => $branch->id,
        'document_type' => 'loan_contract',
        'entity_type' => 'customer',
        'code' => 'contrato_base',
        'name' => 'Contrato base',
        'paper_type' => 'letter',
        'fields' => ['cliente', 'monto', 'garantia'],
        'columns' => ['concepto', 'valor'],
        'is_default' => true,
        'is_active' => true,
    ]);
    DynamicReportTemplate::query()->create([
        'code' => 'prestamos_mora',
        'name' => 'Prestamos en mora',
        'module' => 'finance',
        'entity_type' => 'customer',
        'columns' => ['cliente', 'monto', 'dias_mora'],
        'filters' => ['date_range' => true],
        'metrics' => ['monto' => 'sum'],
        'cache_ttl_minutes' => 15,
        'is_default' => true,
        'is_active' => true,
    ]);

    $service = app(DynamicTemplateService::class);

    expect($service->documentTemplatesFor('loan_contract', $branch->id))->toBe([])
        ->and($service->reportTemplatesFor('finance', 'customer'))->toBe([]);

    BusinessProfile::query()->create([
        'name' => 'Perfil documentos y reportes dinamicos',
        'business_type' => 'loans',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['reports' => true],
            'capabilities' => [
                'uses_document_templates' => true,
                'uses_report_templates' => true,
            ],
            'feature_flags' => [
                'document_engine_v2' => true,
                'report_templates_engine' => true,
            ],
            'documents' => [
                'active' => ['loan_contract'],
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    expect($service->resolveDocument('loan_contract', $branch->id)?->code)->toBe('contrato_base')
        ->and($service->documentTemplatesFor('sale_note', $branch->id))->toBe([])
        ->and($service->resolveReport('prestamos_mora')?->columns)->toBe(['cliente', 'monto', 'dias_mora'])
        ->and(collect($service->reportTemplatesFor('finance', 'customer'))->pluck('code')->all())->toBe(['prestamos_mora']);
});

it('expone reportes configurables en exportaciones solo con motor activo y permiso de plantilla', function () {
    DynamicReportTemplate::query()->create([
        'code' => 'clientes_contacto',
        'name' => 'Clientes contacto',
        'module' => 'customers',
        'entity_type' => 'customer',
        'description' => 'Reporte configurable de contactos de clientes.',
        'columns' => ['name', 'phone'],
        'permissions' => ['export' => ['reports.clientes.export']],
        'metadata' => ['column_labels' => ['phone' => 'Celular cliente']],
        'is_exportable' => true,
        'is_active' => true,
    ]);

    $branch = Branch::query()->create([
        'name' => 'Sucursal reportes dinamicos',
        'code' => 'REPORT-DYN',
        'barcode' => 'BR-REPORT-DYN',
        'is_active' => true,
    ]);

    \App\Modules\Customers\Models\Customer::query()->create([
        'name' => 'Cliente Reporte',
        'phone' => '70001122',
        'is_active' => true,
    ]);

    foreach (['customers.view', 'reports.clientes.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $roleBase = Role::findOrCreate('reportes cliente base', 'web');
    $roleBase->syncPermissions(['customers.view']);
    $roleExport = Role::findOrCreate('reportes cliente exporta', 'web');
    $roleExport->syncPermissions(['customers.view', 'reports.clientes.export']);

    $basicUser = User::factory()->create(['branch_id' => $branch->id]);
    $allowedUser = User::factory()->create(['branch_id' => $branch->id]);
    $basicUser->assignRole($roleBase);
    $allowedUser->assignRole($roleExport);

    $basicRequest = Request::create('/exports');
    $basicRequest->setUserResolver(fn () => $basicUser);
    $allowedRequest = Request::create('/exports', 'GET', [
        'modules' => ['report_template__clientes_contacto'],
        'fields' => ['report_template__clientes_contacto' => ['phone']],
    ]);
    $allowedRequest->setUserResolver(fn () => $allowedUser);

    $service = app(ExportDatasetService::class);

    expect($service->catalog($basicRequest))->not->toHaveKey('report_template__clientes_contacto');

    BusinessProfile::query()->create([
        'name' => 'Perfil reportes configurables exportables',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => [
                'exports' => true,
                'reports' => true,
                'customers' => true,
            ],
            'capabilities' => [
                'uses_report_templates' => true,
            ],
            'feature_flags' => [
                'report_templates_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $catalog = $service->catalog($allowedRequest);
    $export = $service->build($allowedRequest);

    expect($service->catalog($basicRequest))->not->toHaveKey('report_template__clientes_contacto')
        ->and($catalog)->toHaveKey('report_template__clientes_contacto')
        ->and($catalog['report_template__clientes_contacto']['fields'])->toBe(['name' => 'Nombre', 'phone' => 'Celular cliente'])
        ->and($export['sections'][0]['title'])->toBe('Clientes contacto')
        ->and($export['sections'][0]['headers'])->toBe(['Celular cliente'])
        ->and($export['sections'][0]['rows'])->toBe([['70001122']]);
});

it('evalua formulas controladas solo cuando el motor esta activo', function () {
    CalculationFormula::query()->create([
        'entity_type' => 'production_order',
        'code' => 'obra_material_base',
        'name' => 'Material base por m2',
        'result_type' => 'decimal',
        'expression' => [
            'op' => 'multiply',
            'args' => [
                ['var' => 'm2'],
                ['var' => 'factor'],
            ],
        ],
        'variables' => [
            ['code' => 'm2', 'label' => 'Metros cuadrados'],
            ['code' => 'factor', 'label' => 'Factor'],
        ],
        'precision' => 3,
        'is_active' => true,
    ]);

    $service = app(CalculationFormulaService::class);

    expect($service->formulasFor('production_order'))->toBe([]);

    BusinessProfile::query()->create([
        'name' => 'Perfil formulas controladas',
        'business_type' => 'construction',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_formula_calculations' => true,
            ],
            'feature_flags' => [
                'formula_engine' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    expect(collect($service->formulasFor('production_order'))->pluck('code')->all())->toBe(['obra_material_base'])
        ->and($service->evaluate('obra_material_base', ['m2' => 12.5, 'factor' => 1.2], 'production_order'))->toBe(15.0);
});

it('bloquea formulas controladas si falta una variable requerida', function () {
    BusinessProfile::query()->create([
        'name' => 'Perfil formulas activas',
        'business_type' => 'construction',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => ['uses_formula_calculations' => true],
            'feature_flags' => ['formula_engine' => true],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    CalculationFormula::query()->create([
        'entity_type' => 'production_order',
        'code' => 'division_segura',
        'name' => 'Division segura',
        'result_type' => 'decimal',
        'expression' => ['op' => 'divide', 'args' => [['var' => 'monto'], ['var' => 'dias']]],
        'variables' => [['code' => 'monto'], ['code' => 'dias']],
        'precision' => 2,
        'is_active' => true,
    ]);

    app(CalculationFormulaService::class)->evaluate('division_segura', ['monto' => 100], 'production_order');
})->throws(ValidationException::class);

it('resuelve reglas comerciales POS solo cuando el motor esta activo', function () {
    BusinessCommercialFlowRule::query()->create([
        'code' => 'pos_servicio_base',
        'name' => 'POS servicio base',
        'business_type' => 'services',
        'sales_workflow' => 'service_sale',
        'pos_mode' => 'services',
        'channel' => 'counter',
        'document_type' => 'sale_note',
        'customer_mode' => 'required',
        'cash_policy' => 'required',
        'inventory_timing' => 'manual',
        'payment_policy' => 'single_or_mixed',
        'requires_responsible' => true,
        'requires_service_order' => true,
        'allows_discount' => true,
        'allows_mixed_payment' => true,
        'is_active' => true,
    ]);

    $service = app(BusinessCommercialFlowRuleService::class);

    expect($service->rulesFor('service_sale'))->toHaveCount(0)
        ->and($service->resolve('pos_servicio_base'))->toBeNull();

    BusinessProfile::query()->create([
        'name' => 'Perfil comercial activo',
        'business_type' => 'services',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['pos' => true, 'services' => true, 'service_orders' => true],
            'capabilities' => [
                'uses_pos' => true,
                'uses_services' => true,
                'uses_service_orders' => true,
                'uses_dynamic_pos' => true,
                'uses_commercial_flow_rules' => true,
            ],
            'feature_flags' => ['commercial_flow_engine' => true],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $rule = $service->resolve('pos_servicio_base');

    expect($rule)->not->toBeNull()
        ->and(collect($service->rulesFor('service_sale', 'counter'))->pluck('code')->all())->toBe(['pos_servicio_base']);
});

it('valida contexto operativo de reglas comerciales POS', function () {
    BusinessProfile::query()->create([
        'name' => 'Perfil comercial activo',
        'business_type' => 'services',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => ['pos' => true, 'services' => true, 'service_orders' => true],
            'capabilities' => [
                'uses_pos' => true,
                'uses_services' => true,
                'uses_service_orders' => true,
                'uses_dynamic_pos' => true,
                'uses_commercial_flow_rules' => true,
            ],
            'feature_flags' => ['commercial_flow_engine' => true],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $rule = BusinessCommercialFlowRule::query()->create([
        'code' => 'servicio_restricto',
        'name' => 'Servicio restringido',
        'sales_workflow' => 'service_sale',
        'pos_mode' => 'services',
        'channel' => 'counter',
        'document_type' => 'sale_note',
        'customer_mode' => 'required',
        'cash_policy' => 'required',
        'inventory_timing' => 'manual',
        'payment_policy' => 'single',
        'requires_responsible' => true,
        'requires_service_order' => true,
        'allows_discount' => false,
        'allows_price_override' => false,
        'allows_credit' => false,
        'allows_advance' => false,
        'allows_split_payment' => false,
        'allows_mixed_payment' => false,
        'is_active' => true,
    ]);

    $service = app(BusinessCommercialFlowRuleService::class);
    $errors = $service->validateContext($rule, [
        'cash_session_open' => false,
        'discount_amount' => 5,
        'payments' => [
            ['method' => 'cash', 'amount' => 10],
            ['method' => 'qr', 'amount' => 5],
        ],
    ]);
    $ok = $service->validateContext($rule, [
        'customer_id' => 1,
        'cash_session_open' => true,
        'responsible_id' => 2,
        'service_order_id' => 3,
        'payments' => [
            ['method' => 'cash', 'amount' => 15],
        ],
    ]);

    expect($errors)->toContain('Este flujo requiere cliente antes de continuar.')
        ->and($errors)->toContain('Este flujo requiere caja abierta.')
        ->and($errors)->toContain('Este flujo requiere responsable, tecnico, mesero o vendedor asignado.')
        ->and($errors)->toContain('Este flujo requiere orden de servicio vinculada.')
        ->and($errors)->toContain('Este flujo no permite descuentos.')
        ->and($errors)->toContain('Este flujo no permite pago dividido.')
        ->and($errors)->toContain('Este flujo no permite pago mixto.')
        ->and($ok)->toBe([]);
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

it('resuelve conectores externos solo con motor y canal activos', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal integraciones',
        'code' => 'INT-RUNTIME',
        'barcode' => 'BR-INT-RUNTIME',
        'is_active' => true,
    ]);

    $connector = BusinessIntegrationConnector::query()->create([
        'branch_id' => $branch->id,
        'code' => 'maps_osrm_principal',
        'name' => 'OSRM principal',
        'provider' => 'maps_routing',
        'channel' => 'maps',
        'direction' => 'outbound',
        'auth_type' => 'none',
        'base_url' => 'https://router.project-osrm.org',
        'rate_limit_per_minute' => 60,
        'timeout_seconds' => 10,
        'capabilities' => ['route_distance'],
        'public_config' => ['profile' => 'driving'],
        'secret_config' => ['token' => 'no-debe-salir'],
        'is_active' => true,
    ]);

    $service = app(BusinessIntegrationConnectorService::class);

    expect($service->connectorsFor('maps', $branch->id))->toBe([])
        ->and($service->resolve('maps_osrm_principal', $branch->id))->toBeNull();

    BusinessProfile::query()->create([
        'name' => 'Perfil integraciones activo',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => ['uses_integrations' => true],
            'feature_flags' => ['integrations_engine' => true],
            'integrations' => [
                'enabled' => true,
                'channels' => [
                    'maps' => true,
                    'whatsapp' => false,
                ],
            ],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $resolved = $service->resolve('maps_osrm_principal', $branch->id);

    expect(collect($service->connectorsFor('maps', $branch->id))->pluck('code')->all())->toBe(['maps_osrm_principal'])
        ->and($resolved?->id)->toBe($connector->id)
        ->and($service->secretConfig($resolved))->toBe(['token' => 'no-debe-salir'])
        ->and($service->connectorsFor('whatsapp', $branch->id))->toBe([]);
});

it('evalua politicas multi-sucursal solo cuando el perfil activo habilita el motor', function () {
    $origen = Branch::query()->create([
        'name' => 'Sucursal origen avanzada',
        'code' => 'MBA-ORIGEN',
        'barcode' => 'BR-MBA-ORIGEN',
        'is_active' => true,
    ]);
    $destino = Branch::query()->create([
        'name' => 'Sucursal destino avanzada',
        'code' => 'MBA-DESTINO',
        'barcode' => 'BR-MBA-DESTINO',
        'is_active' => true,
    ]);

    $user = User::factory()->create(['branch_id' => $origen->id]);
    $user->accessibleBranches()->sync([$origen->id, $destino->id]);
    Cache::flush();

    BranchOperationPolicy::query()->create([
        'branch_id' => $origen->id,
        'code' => 'transferencia_avanzada_test',
        'name' => 'Transferencia avanzada test',
        'operation' => 'transfer_inventory',
        'scope' => 'source_destination',
        'enforcement_mode' => BranchOperationPolicy::ENFORCEMENT_BLOCK,
        'applies_to_modules' => ['inventory', 'transfers'],
        'rules' => [
            'require_user_branch_access' => true,
            'allow_cross_branch' => true,
            'allowed_destination_branch_ids' => [$destino->id],
            'max_transfer_quantity' => 100,
        ],
        'permissions' => ['manage' => 'inventory.transfers.manage'],
        'is_active' => true,
    ]);

    $service = app(AdvancedBranchPolicyService::class);

    expect($service->enabled())->toBeFalse()
        ->and($service->policiesFor('transfer_inventory', $origen->id))->toBe([])
        ->and($service->transferDecision($user, $origen->id, $destino->id, 150)['allowed'])->toBeTrue();

    BusinessProfile::query()->create([
        'name' => 'Perfil multi-sucursal avanzada',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => ['uses_advanced_multibranch' => true],
            'feature_flags' => ['advanced_multibranch_engine' => true],
            'multibranch' => ['enabled' => true],
        ]),
        'applied_at' => now(),
    ]);
    Cache::flush();

    $allowed = $service->transferDecision($user, $origen->id, $destino->id, 50);
    $blocked = $service->transferDecision($user, $origen->id, $destino->id, 150);

    expect($service->enabled())->toBeTrue()
        ->and(collect($service->policiesFor('transfer_inventory', $origen->id))->pluck('code')->all())->toBe(['transferencia_avanzada_test'])
        ->and($allowed['allowed'])->toBeTrue()
        ->and($allowed['errors'])->toBe([])
        ->and($blocked['allowed'])->toBeFalse()
        ->and($blocked['errors'])->toContain('La cantidad supera el limite configurado para transferencias.');
});
