<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\ApprovalFlowRule;
use App\Modules\SystemSuperadmin\Models\ApprovalRequest;
use App\Modules\SystemSuperadmin\Models\AutomationRule;
use App\Modules\SystemSuperadmin\Models\AutomationRun;
use App\Modules\SystemSuperadmin\Models\BusinessCommercialFlowRule;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessIntegrationConnector;
use App\Modules\SystemSuperadmin\Models\BranchOperationPolicy;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CalculationFormula;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicDocumentTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicFormDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicFormFieldRule;
use App\Modules\SystemSuperadmin\Models\DynamicReportTemplate;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\OperationalTrace;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\ApprovalFlowService;
use App\Modules\SystemSuperadmin\Services\AutomationRuleService;
use App\Modules\SystemSuperadmin\Services\BusinessTransversalReadinessService;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use App\Modules\SystemSuperadmin\Services\DynamicEntityRegistry;
use App\Support\SystemCacheInvalidator;
use App\Support\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function transversalUser(bool $system = true): User
{
    $branch = Branch::query()->create([
        'name' => 'Sucursal transversal',
        'code' => 'TR-'.uniqid(),
        'barcode' => 'BR-TR-'.uniqid(),
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate([
        'name' => $system ? SystemRoles::SYSTEM_SUPERADMIN : 'superadmin',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('solo sistemasuperadmin puede ver la configuracion transversal', function () {
    $systemUser = transversalUser();
    $clientSuperadmin = transversalUser(false);

    $this->actingAs($systemUser)
        ->get(route('system-superadmin.transversal-config.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SystemSuperadmin/BusinessTransversal/Index', false)
            ->has('options')
            ->has('readiness.overall')
            ->has('readiness.engines')
            ->has('summary'));

    $this->actingAs($clientSuperadmin)
        ->get(route('system-superadmin.transversal-config.index'))
        ->assertForbidden();
});

it('calcula readiness transversal sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();

    CustomFieldDefinition::query()->create([
        'entity_type' => 'product',
        'code' => 'color_preparado',
        'label' => 'Color preparado',
        'type' => 'text',
        'visible_in_forms' => true,
        'is_active' => true,
    ]);

    $readiness = app(BusinessTransversalReadinessService::class)->evaluate();
    $fieldsEngine = collect($readiness['engines'])->firstWhere('code', 'dynamic_fields');
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($fieldsEngine['status'])->toBe('prepared')
        ->and($fieldsEngine['definitions_count'])->toBe(1)
        ->and($readiness['overall']['blocked'])->toBe(0)
        ->and($configuration['identity']['business_type'])->toBe('hardware_store')
        ->and($configuration['feature_flags']['dynamic_fields_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_fields'])->toBeFalse();

    $this->actingAs($user)
        ->get(route('system-superadmin.transversal-config.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SystemSuperadmin/BusinessTransversal/Index', false)
            ->where('readiness.overall.prepared', 1)
            ->where('readiness.overall.blocked', 0)
        );
});

it('bloquea readiness cuando una capacidad transversal esta activa sin su feature flag tecnico', function () {
    $user = transversalUser();

    BusinessProfile::query()->create([
        'name' => 'Perfil inconsistente para readiness',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'capabilities' => [
                'uses_dynamic_fields' => true,
            ],
            'feature_flags' => [
                'dynamic_fields_engine' => false,
            ],
        ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    SystemCacheInvalidator::bumpOperational();

    $readiness = app(BusinessTransversalReadinessService::class)->evaluate();
    $fieldsEngine = collect($readiness['engines'])->firstWhere('code', 'dynamic_fields');

    expect($fieldsEngine['status'])->toBe('blocked')
        ->and($readiness['overall']['blocked'])->toBe(1)
        ->and($readiness['overall']['can_apply_profile_safely'])->toBeFalse();
});

it('crea campos personalizados sin modificar el perfil activo', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'custom-fields'), [
            'entity_type' => 'product',
            'code' => 'talla',
            'label' => 'Talla',
            'type' => 'select',
            'options_csv' => 'S,M,L',
            'is_required' => true,
            'visible_in_forms' => true,
            'visible_in_documents' => true,
            'visible_in_reports' => false,
            'sort_order' => 5,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(CustomFieldDefinition::query()->where('code', 'talla')->first())
        ->not->toBeNull()
        ->options->toBe(['S', 'M', 'L'])
        ->visible_in_documents->toBeTrue();
});

it('crea atributos personalizados avanzados sin habilitarlos en ferreteria por defecto', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'custom-fields'), [
            'entity_type' => 'customer',
            'code' => 'historia_clinica',
            'label' => 'Historia clinica',
            'help_text' => 'Dato sensible visible solo para perfiles autorizados.',
            'placeholder' => 'Antecedentes, alergias o notas medicas',
            'type' => 'text',
            'group' => 'Datos medicos',
            'default_value_text' => '{"valor":"Sin antecedentes registrados"}',
            'format' => 'texto_largo',
            'is_required' => false,
            'visible_in_forms' => true,
            'visible_in_table' => false,
            'visible_in_documents' => false,
            'visible_in_reports' => true,
            'is_exportable' => false,
            'is_auditable' => true,
            'is_sensitive' => true,
            'is_encrypted' => true,
            'is_read_only' => false,
            'metadata_text' => '{"retencion":"historia_clinica"}',
            'sort_order' => 12,
            'is_active' => true,
        ])
        ->assertRedirect();

    $field = CustomFieldDefinition::query()->where('code', 'historia_clinica')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($field)->not->toBeNull()
        ->and($field->group)->toBe('Datos medicos')
        ->and($field->default_value)->toBe(['valor' => 'Sin antecedentes registrados'])
        ->and($field->visible_in_reports)->toBeTrue()
        ->and($field->is_sensitive)->toBeTrue()
        ->and($field->is_encrypted)->toBeTrue()
        ->and($configuration['feature_flags']['dynamic_fields_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_fields'])->toBeFalse();
});

it('bloquea codigos duplicados de atributos dentro de la misma entidad', function () {
    $user = transversalUser();
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'ci',
        'label' => 'CI',
        'type' => 'identity_document',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'custom-fields'), [
            'entity_type' => 'customer',
            'code' => 'ci',
            'label' => 'Documento duplicado',
            'type' => 'text',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('code');

    expect(CustomFieldDefinition::query()->where('entity_type', 'customer')->where('code', 'ci')->count())->toBe(1);
});

it('gestiona reglas de aprobacion y automatizacion sin activarlas en ferreteria por defecto', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'approval-flows'), [
            'code' => 'aprobar_descuento_alto',
            'name' => 'Aprobar descuento alto',
            'entity_type' => 'sale',
            'action' => 'high_discount',
            'trigger' => 'before_action',
            'conditions_text' => '{"discount_percent":15}',
            'approver_roles_csv' => 'superadmin,gerente',
            'approver_permissions_csv' => 'sales.manage',
            'min_approvals' => 1,
            'requires_reason' => true,
            'blocks_until_approved' => true,
            'expires_after_minutes' => 120,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'automation-rules'), [
            'code' => 'avisar_reserva_proxima',
            'name' => 'Avisar reserva proxima',
            'entity_type' => 'reservation',
            'trigger' => 'reservation_due',
            'conditions_text' => '{"minutes_before":60}',
            'actions_text' => '{"notify":["system"]}',
            'cooldown_minutes' => 60,
            'is_active' => true,
        ])
        ->assertRedirect();

    $configuration = BusinessProfileConfiguration::normalized([]);

    expect(ApprovalFlowRule::query()->where('code', 'aprobar_descuento_alto')->first())
        ->not->toBeNull()
        ->conditions->toBe(['discount_percent' => 15])
        ->approver_roles->toBe(['superadmin', 'gerente'])
        ->and(AutomationRule::query()->where('code', 'avisar_reserva_proxima')->first())
        ->not->toBeNull()
        ->actions->toBe(['notify' => ['system']])
        ->and($configuration['feature_flags']['approval_engine'])->toBeFalse()
        ->and($configuration['feature_flags']['automation_engine'])->toBeFalse()
        ->and(app(ApprovalFlowService::class)->requiredRule('sale', 'high_discount', ['discount_percent' => 15]))->toBeNull()
        ->and(app(AutomationRuleService::class)->matchingRules('reservation', 'reservation_due', ['minutes_before' => 60]))->toBe([]);
});

it('gestiona conectores externos sin activarlos en ferreteria por defecto', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'integrations'), [
            'branch_id' => $user->branch_id,
            'code' => 'whatsapp_principal',
            'name' => 'WhatsApp principal',
            'provider' => 'whatsapp',
            'channel' => 'whatsapp',
            'direction' => 'outbound',
            'auth_type' => 'bearer_token',
            'base_url' => 'https://api.example.test',
            'rate_limit_per_minute' => 30,
            'timeout_seconds' => 15,
            'capabilities_csv' => 'send_message,send_template',
            'public_config_text' => '{"sandbox":true}',
            'secret_config_text' => '{"token":"secreto-no-visible"}',
            'last_status' => 'pending',
            'is_active' => true,
        ])
        ->assertRedirect();

    $connector = BusinessIntegrationConnector::query()->where('code', 'whatsapp_principal')->firstOrFail();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($connector->provider)->toBe('whatsapp')
        ->and($connector->capabilities)->toBe(['send_message', 'send_template'])
        ->and($connector->public_config)->toBe(['sandbox' => true])
        ->and($connector->decodedSecretConfig())->toBe(['token' => 'secreto-no-visible'])
        ->and($connector->getRawOriginal('secret_config'))->not->toContain('secreto-no-visible')
        ->and($connector->toArray())->not->toHaveKey('secret_config')
        ->and($configuration['capabilities']['uses_integrations'])->toBeFalse()
        ->and($configuration['feature_flags']['integrations_engine'])->toBeFalse();
});

it('gestiona politicas multi-sucursal sin activarlas en ferreteria por defecto', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'branch-policies'), [
            'branch_id' => $user->branch_id,
            'code' => 'transferencia_controlada',
            'name' => 'Transferencia controlada',
            'operation' => 'transfer_inventory',
            'scope' => 'source_destination',
            'enforcement_mode' => 'block',
            'applies_to_modules_csv' => 'inventory,transfers',
            'rules_text' => '{"require_user_branch_access":true,"allow_cross_branch":true,"max_transfer_quantity":500}',
            'permissions_text' => '{"manage":"inventory.transfers.manage","approve":"inventory.transfers.approve"}',
            'metadata_text' => '{"nota":"Regla preparada para multi-sucursal avanzada"}',
            'is_active' => true,
        ])
        ->assertRedirect();

    $policy = BranchOperationPolicy::query()->where('code', 'transferencia_controlada')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($policy)->not->toBeNull()
        ->and($policy->branch_id)->toBe($user->branch_id)
        ->and($policy->operation)->toBe('transfer_inventory')
        ->and($policy->applies_to_modules)->toBe(['inventory', 'transfers'])
        ->and($policy->rules)->toBe([
            'require_user_branch_access' => true,
            'allow_cross_branch' => true,
            'max_transfer_quantity' => 500,
        ])
        ->and($policy->permissions)->toBe([
            'manage' => 'inventory.transfers.manage',
            'approve' => 'inventory.transfers.approve',
        ])
        ->and($configuration['capabilities']['uses_advanced_multibranch'])->toBeFalse()
        ->and($configuration['feature_flags']['advanced_multibranch_engine'])->toBeFalse()
        ->and($configuration['multibranch']['enabled'])->toBeFalse();
});

it('evalua y registra aprobaciones solo cuando el perfil activa el motor', function () {
    $user = transversalUser();

    BusinessProfile::query()->create([
        'name' => 'Perfil con aprobaciones',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'feature_flags' => ['approval_engine' => true, 'automation_engine' => true],
            'approvals' => ['enabled' => true],
            'automations' => ['enabled' => true],
        ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    SystemCacheInvalidator::bumpOperational();

    $rule = ApprovalFlowRule::query()->create([
        'code' => 'aprobar_stock_negativo',
        'name' => 'Aprobar stock negativo',
        'entity_type' => 'sale',
        'action' => 'negative_stock',
        'trigger' => 'before_action',
        'conditions' => ['branch_id' => $user->branch_id],
        'approver_roles' => ['superadmin'],
        'min_approvals' => 1,
        'is_active' => true,
    ]);

    AutomationRule::query()->create([
        'code' => 'alerta_caja_sin_cerrar',
        'name' => 'Caja sin cerrar',
        'entity_type' => 'cash_session',
        'trigger' => 'cash_not_closed',
        'conditions' => ['branch_id' => $user->branch_id],
        'actions' => ['notify' => ['system']],
        'cooldown_minutes' => 10,
        'is_active' => true,
    ]);

    $approvalService = app(ApprovalFlowService::class);
    $matchedRule = $approvalService->requiredRule('sale', 'negative_stock', ['branch_id' => $user->branch_id]);
    $request = $approvalService->createRequest($rule, 'sale', 10, 'negative_stock', $user, 'Venta urgente', ['amount' => 100]);
    $approved = $approvalService->approve($request, $user, 'Autorizado por prueba');
    $automationRules = app(AutomationRuleService::class)->matchingRules('cash_session', 'cash_not_closed', ['branch_id' => $user->branch_id]);

    expect($matchedRule?->id)->toBe($rule->id)
        ->and($request)->toBeInstanceOf(ApprovalRequest::class)
        ->and($approved->status)->toBe(ApprovalRequest::STATUS_APPROVED)
        ->and($approved->approvals)->toHaveCount(1)
        ->and($automationRules)->toHaveCount(1)
        ->and($automationRules[0]->code)->toBe('alerta_caja_sin_cerrar');
});

it('resuelve solicitudes de aprobacion desde sistemasuperadmin con trazabilidad', function () {
    $user = transversalUser();

    $rule = ApprovalFlowRule::query()->create([
        'code' => 'aprobar_anulacion_test',
        'name' => 'Aprobar anulacion test',
        'entity_type' => 'sale',
        'action' => 'void_sale',
        'trigger' => 'before_action',
        'approver_roles' => ['superadmin'],
        'min_approvals' => 1,
        'is_active' => true,
    ]);

    $approvalRequest = ApprovalRequest::query()->create([
        'approval_flow_rule_id' => $rule->id,
        'entity_type' => 'sale',
        'entity_id' => 15,
        'action' => 'void_sale',
        'status' => ApprovalRequest::STATUS_PENDING,
        'requested_by' => $user->id,
        'reason' => 'Anulacion solicitada',
        'payload' => ['total' => 100],
        'approvals' => [],
    ]);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.approval-requests.approve', $approvalRequest), [
            'note' => 'Aprobado por gerencia',
        ])
        ->assertRedirect();

    $approvalRequest->refresh();

    expect($approvalRequest->status)->toBe(ApprovalRequest::STATUS_APPROVED)
        ->and($approvalRequest->resolved_by)->toBe($user->id)
        ->and($approvalRequest->resolution_note)->toBe('Aprobado por gerencia')
        ->and($approvalRequest->approvals)->toHaveCount(1);
});

it('bloquea resolver dos veces una solicitud de aprobacion', function () {
    $user = transversalUser();

    $rule = ApprovalFlowRule::query()->create([
        'code' => 'aprobar_descuento_doble_test',
        'name' => 'Aprobar descuento doble test',
        'entity_type' => 'sale',
        'action' => 'high_discount',
        'trigger' => 'before_action',
        'min_approvals' => 1,
        'is_active' => true,
    ]);

    $approvalRequest = ApprovalRequest::query()->create([
        'approval_flow_rule_id' => $rule->id,
        'entity_type' => 'sale',
        'entity_id' => 0,
        'action' => 'high_discount',
        'status' => ApprovalRequest::STATUS_APPROVED,
        'requested_by' => $user->id,
        'resolved_by' => $user->id,
        'resolved_at' => now(),
        'resolution_note' => 'Ya resuelto',
        'approvals' => [['user_id' => $user->id, 'name' => $user->name, 'approved_at' => now()->toIso8601String()]],
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.approval-requests.reject', $approvalRequest), [
            'note' => 'Intento posterior',
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('note');

    expect($approvalRequest->fresh()->status)->toBe(ApprovalRequest::STATUS_APPROVED);
});

it('ejecuta automatizaciones activas con historial y cooldown', function () {
    $user = transversalUser();

    BusinessProfile::query()->create([
        'name' => 'Perfil con automatizaciones',
        'business_type' => 'hardware_store',
        'status' => 'active',
            'configuration' => BusinessProfileConfiguration::normalized([
                'capabilities' => ['uses_operational_traceability' => true],
                'feature_flags' => ['automation_engine' => true, 'operational_traceability_engine' => true],
                'automations' => ['enabled' => true],
                'traceability' => ['enabled' => true],
            ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    SystemCacheInvalidator::bumpOperational();

    $rule = AutomationRule::query()->create([
        'code' => 'recordar_caja_abierta_test',
        'name' => 'Recordar caja abierta test',
        'entity_type' => 'cash_session',
        'trigger' => 'cash_not_closed',
        'conditions' => ['branch_id' => $user->branch_id],
        'actions' => ['notify' => ['system']],
        'cooldown_minutes' => 30,
        'is_active' => true,
    ]);

    $service = app(AutomationRuleService::class);
    $runs = $service->execute('cash_session', 'cash_not_closed', ['branch_id' => $user->branch_id], 20);
    $secondRuns = $service->execute('cash_session', 'cash_not_closed', ['branch_id' => $user->branch_id], 20);

    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toBeInstanceOf(AutomationRun::class)
        ->and($runs[0]->status)->toBe(AutomationRun::STATUS_COMPLETED)
        ->and($runs[0]->actions)->toBe(['notify' => ['system']])
        ->and($rule->fresh()->last_run_at)->not->toBeNull()
        ->and($secondRuns)->toBe([])
        ->and(AutomationRun::query()->where('automation_rule_id', $rule->id)->count())->toBe(1)
        ->and(OperationalTrace::query()->where('entity_type', 'cash_session')->where('entity_id', 20)->where('event', 'automation_executed')->exists())->toBeTrue();
});

it('registra trazabilidad operativa solo cuando el perfil activa la capacidad', function () {
    $user = transversalUser();

    $rule = ApprovalFlowRule::query()->create([
        'code' => 'aprobar_descuento_trace_test',
        'name' => 'Aprobar descuento trazable',
        'entity_type' => 'sale',
        'action' => 'high_discount',
        'trigger' => 'before_action',
        'approver_roles' => ['superadmin'],
        'min_approvals' => 1,
        'is_active' => true,
    ]);

    BusinessProfile::query()->create([
        'name' => 'Perfil aprobaciones sin trazabilidad',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'feature_flags' => ['approval_engine' => true],
            'approvals' => ['enabled' => true],
            'traceability' => ['enabled' => true],
        ]),
        'applied_at' => now()->subMinute(),
        'applied_by' => $user->id,
    ]);
    SystemCacheInvalidator::bumpOperational();

    $service = app(ApprovalFlowService::class);
    $requestWithoutCapability = $service->createRequest($rule, 'sale', 30, 'high_discount', $user, 'Sin capacidad', ['discount' => 15]);
    $service->approve($requestWithoutCapability, $user, 'Aprobado sin trazabilidad');

    expect(OperationalTrace::query()->count())->toBe(0);

    BusinessProfile::query()->create([
        'name' => 'Perfil aprobaciones con trazabilidad',
        'business_type' => 'hardware_store',
        'status' => 'active',
            'configuration' => BusinessProfileConfiguration::normalized([
                'capabilities' => ['uses_operational_traceability' => true],
                'feature_flags' => ['approval_engine' => true, 'operational_traceability_engine' => true],
                'approvals' => ['enabled' => true],
                'traceability' => ['enabled' => true],
            ]),
        'applied_at' => now(),
        'applied_by' => $user->id,
    ]);
    SystemCacheInvalidator::bumpOperational();

    $requestWithCapability = $service->createRequest($rule, 'sale', 31, 'high_discount', $user, 'Con capacidad', ['discount' => 20]);
    $approved = $service->approve($requestWithCapability, $user, 'Aprobado con trazabilidad');

    expect($approved->status)->toBe(ApprovalRequest::STATUS_APPROVED)
        ->and(OperationalTrace::query()->where('entity_type', 'sale')->where('entity_id', 31)->orderBy('id')->pluck('event')->all())->toBe([
            'approval_requested',
            'approval_approved',
        ]);
});

it('crea flujos, estados y transiciones configurables sin cambiar ferreteria', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'workflows'), [
            'entity_type' => 'service_order',
            'code' => 'reparacion',
            'name' => 'Flujo de reparacion',
            'description' => 'Recepcion, diagnostico, reparacion y entrega.',
            'initial_state_code' => 'recibido',
            'final_state_codes_csv' => 'entregado,cancelado',
            'settings_text' => '{"requiere_tecnico":true}',
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    foreach ([
        ['recibido', 'Recibido', true, false, 'initial'],
        ['diagnostico', 'Diagnostico', false, false, 'intermediate'],
        ['entregado', 'Entregado', false, true, 'final'],
    ] as [$code, $label, $initial, $final, $type]) {
        $this->actingAs($user)
            ->post(route('system-superadmin.transversal-config.store', 'states'), [
                'entity_type' => 'service_order',
                'workflow_code' => 'reparacion',
                'code' => $code,
                'label' => $label,
                'state_type' => $type,
                'is_initial' => $initial,
                'is_final' => $final,
                'entry_validations_text' => '{"cliente_requerido":true}',
                'actions_text' => '{"notificar":false}',
                'exit_actions_text' => '{"registrar_bitacora":true}',
                'is_active' => true,
            ])
            ->assertRedirect();
    }

    $workflow = WorkflowDefinition::query()->where('code', 'reparacion')->first();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'workflow-transitions'), [
            'workflow_definition_id' => $workflow->id,
            'from_state_code' => 'recibido',
            'to_state_code' => 'diagnostico',
            'label' => 'Enviar a diagnostico',
            'required_permission' => 'diagnosticar ordenes',
            'validations_text' => '{"tecnico_asignado":true}',
            'actions_text' => '{"crear_alerta":true}',
            'requires_reason' => false,
            'is_reversible' => true,
            'sort_order' => 1,
            'is_active' => true,
        ])
        ->assertRedirect();

    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($workflow)->not->toBeNull()
        ->and($workflow->is_default)->toBeTrue()
        ->and(WorkflowTransition::query()->where('from_state_code', 'recibido')->exists())->toBeTrue()
        ->and($configuration['feature_flags']['dynamic_workflows_engine'])->toBeFalse();
});

it('bloquea transiciones hacia estados inexistentes dentro del flujo', function () {
    $user = transversalUser();
    $workflow = WorkflowDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'qa',
        'name' => 'QA',
        'is_active' => true,
    ]);
    BusinessStateDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'recibido',
        'label' => 'Recibido',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'workflow-transitions'), [
            'workflow_definition_id' => $workflow->id,
            'from_state_code' => 'recibido',
            'to_state_code' => 'fantasma',
            'label' => 'Invalida',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('from_state_code');

    expect(WorkflowTransition::query()->count())->toBe(0);
});

it('crea entidades configurables sin activar motores nuevos ni cambiar ferreteria', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'entities'), [
            'code' => 'paciente',
            'label' => 'Paciente',
            'plural_label' => 'Pacientes',
            'base_entity' => 'customer',
            'module' => 'customers',
            'icon' => 'Stethoscope',
            'mode' => 'optional',
            'is_visible' => true,
            'is_editable' => true,
            'is_required' => false,
            'is_exportable' => false,
            'is_reportable' => true,
            'is_auditable' => true,
            'is_sensitive' => true,
            'retention_policy' => 'health_record',
            'permissions_text' => '{"view":["doctor"],"edit":["doctor"]}',
            'settings_text' => '{"documentos":["ficha_medica"]}',
            'sort_order' => 10,
            'is_active' => true,
        ])
        ->assertRedirect();

    $entity = DynamicEntity::query()->where('code', 'paciente')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($entity)->not->toBeNull()
        ->and($entity->base_entity)->toBe('customer')
        ->and($entity->is_sensitive)->toBeTrue()
        ->and(app(DynamicEntityRegistry::class)->isUsable('paciente'))->toBeTrue()
        ->and($configuration['identity']['business_type'])->toBe('hardware_store')
        ->and($configuration['feature_flags']['dynamic_entities_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_entities'])->toBeFalse();
});

it('crea relaciones entre entidades sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();
    DynamicEntity::query()->create([
        'code' => 'vehiculo',
        'label' => 'Vehiculo',
        'base_entity' => 'customer',
        'mode' => 'optional',
        'retention_policy' => 'standard',
        'is_visible' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'relationships'), [
            'source_entity_type' => 'customer',
            'target_entity_type' => 'vehiculo',
            'code' => 'vehiculos_cliente',
            'label' => 'Vehiculos del cliente',
            'type' => 'one_to_many',
            'inverse_label' => 'Cliente propietario',
            'is_required' => false,
            'allows_multiple' => true,
            'min_items' => 0,
            'max_items' => 5,
            'cascade_behavior' => 'readonly_history',
            'permissions_text' => '{"view":["admin"],"attach":["tecnico"]}',
            'metadata_text' => '{"documentos":["orden_servicio"]}',
            'sort_order' => 3,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'relationships'), [
            'source_entity_type' => 'customer',
            'target_entity_type' => 'product',
            'code' => 'producto_favorito',
            'label' => 'Producto favorito',
            'type' => 'one_to_one',
            'allows_multiple' => true,
            'cascade_behavior' => 'restrict',
            'is_active' => true,
        ])
        ->assertRedirect();

    $relationship = DynamicRelationshipDefinition::query()->where('code', 'vehiculos_cliente')->first();
    $singleRelationship = DynamicRelationshipDefinition::query()->where('code', 'producto_favorito')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($relationship)->not->toBeNull()
        ->and($relationship->source_entity_type)->toBe('customer')
        ->and($relationship->target_entity_type)->toBe('vehiculo')
        ->and($relationship->allows_multiple)->toBeTrue()
        ->and($relationship->permissions)->toBe(['view' => ['admin'], 'attach' => ['tecnico']])
        ->and($relationship->metadata)->toBe(['documentos' => ['orden_servicio']])
        ->and($singleRelationship->allows_multiple)->toBeFalse()
        ->and($configuration['feature_flags']['dynamic_relationships_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_relationships'])->toBeFalse();
});

it('bloquea relaciones con entidad destino inexistente o mismo origen', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'relationships'), [
            'source_entity_type' => 'customer',
            'target_entity_type' => 'fantasma',
            'code' => 'relacion_invalida',
            'label' => 'Relacion invalida',
            'type' => 'one_to_many',
            'cascade_behavior' => 'restrict',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('target_entity_type');

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'relationships'), [
            'source_entity_type' => 'customer',
            'target_entity_type' => 'customer',
            'code' => 'cliente_cliente',
            'label' => 'Cliente consigo mismo',
            'type' => 'one_to_one',
            'cascade_behavior' => 'restrict',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('target_entity_type');

    expect(DynamicRelationshipDefinition::query()->count())->toBe(0);
});

it('crea definiciones de adjuntos sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'attachments'), [
            'entity_type' => 'customer',
            'code' => 'ci_escaneado',
            'label' => 'CI escaneado',
            'purpose' => 'identity_document',
            'allowed_extensions_csv' => 'jpg,png,pdf',
            'allowed_mime_types_csv' => 'image/jpeg,image/png,application/pdf',
            'max_file_mb' => 8,
            'storage_disk' => 'local',
            'path_prefix' => 'business-attachments/clientes',
            'is_required' => true,
            'is_sensitive' => true,
            'requires_signed_url' => true,
            'audit_downloads' => true,
            'visible_in_documents' => false,
            'visible_in_reports' => false,
            'permissions_text' => '{"view":["admin"],"download":["admin"]}',
            'metadata_text' => '{"lado":"frontal"}',
            'retention_policy' => 'sensitive',
            'sort_order' => 2,
            'is_active' => true,
        ])
        ->assertRedirect();

    $definition = AttachmentDefinition::query()->where('code', 'ci_escaneado')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($definition)->not->toBeNull()
        ->and($definition->entity_type)->toBe('customer')
        ->and($definition->allowed_extensions)->toBe(['jpg', 'png', 'pdf'])
        ->and($definition->allowed_mime_types)->toBe(['image/jpeg', 'image/png', 'application/pdf'])
        ->and($definition->is_sensitive)->toBeTrue()
        ->and($definition->permissions)->toBe(['view' => ['admin'], 'download' => ['admin']])
        ->and($definition->metadata)->toBe(['lado' => 'frontal'])
        ->and($configuration['feature_flags']['attachments_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_attachments'])->toBeFalse();
});

it('crea formularios por flujo sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();
    CustomFieldDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'diagnostico_final',
        'label' => 'Diagnostico final',
        'type' => 'text',
        'visible_in_forms' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'forms'), [
            'entity_type' => 'service_order',
            'code' => 'cierre_orden',
            'name' => 'Cierre de orden de servicio',
            'flow' => 'service_order_close',
            'workflow_code' => 'reparacion',
            'state_code' => 'listo',
            'surface' => 'form',
            'description' => 'Datos requeridos al cerrar la orden.',
            'submit_label' => 'Cerrar orden',
            'layout_text' => '{"columns":2,"sections":["Diagnostico","Entrega"]}',
            'permissions_text' => '{"edit":["tecnico"]}',
            'validations_text' => '{"requires_state":"listo"}',
            'metadata_text' => '{"documento":"orden_servicio"}',
            'sort_order' => 4,
            'is_active' => true,
        ])
        ->assertRedirect();

    $form = DynamicFormDefinition::query()->where('code', 'cierre_orden')->first();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'form-fields'), [
            'dynamic_form_definition_id' => $form->id,
            'field_code' => 'diagnostico_final',
            'label_override' => 'Diagnostico de cierre',
            'help_text' => 'Debe explicar que se realizo antes de entregar.',
            'placeholder' => 'Detalle tecnico',
            'is_required' => true,
            'is_visible' => true,
            'is_read_only' => false,
            'validation_rules_text' => '["min:5"]',
            'visibility_conditions_text' => '{"state":"listo"}',
            'required_conditions_text' => '{"delivery":true}',
            'options_override_csv' => '',
            'sort_order' => 1,
            'is_active' => true,
        ])
        ->assertRedirect();

    $fieldRule = DynamicFormFieldRule::query()->where('field_code', 'diagnostico_final')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($form)->not->toBeNull()
        ->and($form->entity_type)->toBe('service_order')
        ->and($form->layout)->toBe(['columns' => 2, 'sections' => ['Diagnostico', 'Entrega']])
        ->and($fieldRule)->not->toBeNull()
        ->and($fieldRule->is_required)->toBeTrue()
        ->and($fieldRule->validation_rules)->toBe(['min:5'])
        ->and($configuration['feature_flags']['dynamic_forms_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_forms'])->toBeFalse();
});

it('bloquea campos de formulario que no pertenecen a la entidad del formulario', function () {
    $user = transversalUser();
    $form = DynamicFormDefinition::query()->create([
        'entity_type' => 'service_order',
        'code' => 'cierre_orden',
        'name' => 'Cierre de orden',
        'surface' => 'form',
        'is_active' => true,
    ]);
    CustomFieldDefinition::query()->create([
        'entity_type' => 'customer',
        'code' => 'ci',
        'label' => 'CI',
        'type' => 'identity_document',
        'visible_in_forms' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'form-fields'), [
            'dynamic_form_definition_id' => $form->id,
            'field_code' => 'ci',
            'is_required' => true,
            'is_visible' => true,
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('field_code');

    expect(DynamicFormFieldRule::query()->count())->toBe(0);
});

it('crea plantillas documentales y reportes 2 sin activar ferreteria', function () {
    $user = transversalUser();
    $branch = Branch::query()->findOrFail($user->branch_id);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'document-templates'), [
            'branch_id' => $branch->id,
            'document_type' => 'loan_contract',
            'entity_type' => 'customer',
            'code' => 'contrato_prestamo_base',
            'name' => 'Contrato de prestamo base',
            'paper_type' => 'letter',
            'printer_area' => 'cashier',
            'copies' => 2,
            'layout_text' => '{"font_size":11,"show_logo":true}',
            'fields_csv' => 'cliente,monto,interes,garantia,cuotas,firma_cliente',
            'columns_csv' => 'concepto,valor',
            'legal_text' => 'Documento interno sujeto a revision.',
            'terms_text' => 'El cliente acepta las condiciones.',
            'permissions_text' => '{"view":["admin"],"print":["cajero"]}',
            'metadata_text' => '{"version":"2.0"}',
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'report-templates'), [
            'code' => 'ventas_margen_cliente',
            'name' => 'Ventas y margen por cliente',
            'module' => 'sales',
            'entity_type' => 'customer',
            'description' => 'Reporte configurable para ventas por cliente.',
            'columns_csv' => 'cliente,total,margen,ticket_promedio',
            'filters_text' => '{"date_range":true,"branch":true}',
            'groupings_csv' => 'cliente',
            'metrics_text' => '{"total":"sum","margen":"sum"}',
            'permissions_text' => '{"view":["gerente"],"export":["gerente"]}',
            'metadata_text' => '{"rubro":"retail"}',
            'cache_ttl_minutes' => 30,
            'is_exportable' => true,
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $document = DynamicDocumentTemplate::query()->where('code', 'contrato_prestamo_base')->first();
    $report = DynamicReportTemplate::query()->where('code', 'ventas_margen_cliente')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($document)->not->toBeNull()
        ->and($document->document_type)->toBe('loan_contract')
        ->and($document->fields)->toBe(['cliente', 'monto', 'interes', 'garantia', 'cuotas', 'firma_cliente'])
        ->and($document->permissions)->toBe(['view' => ['admin'], 'print' => ['cajero']])
        ->and($report)->not->toBeNull()
        ->and($report->columns)->toBe(['cliente', 'total', 'margen', 'ticket_promedio'])
        ->and($report->is_exportable)->toBeTrue()
        ->and($configuration['feature_flags']['document_engine_v2'])->toBeFalse()
        ->and($configuration['feature_flags']['report_templates_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_document_templates'])->toBeFalse()
        ->and($configuration['capabilities']['uses_report_templates'])->toBeFalse();
});

it('bloquea plantillas documentales con tipos de documento invalidos', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'document-templates'), [
            'document_type' => 'documento_fantasma',
            'code' => 'fantasma',
            'name' => 'Documento fantasma',
            'paper_type' => 'letter',
            'copies' => 1,
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('document_type');

    expect(DynamicDocumentTemplate::query()->count())->toBe(0);
});

it('crea formulas de calculo sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'calculation-formulas'), [
            'entity_type' => 'production_order',
            'code' => 'obra_material_base',
            'name' => 'Material base por m2',
            'description' => 'Calcula material por superficie y factor.',
            'result_type' => 'decimal',
            'expression_text' => '{"op":"multiply","args":[{"var":"m2"},{"var":"factor"}]}',
            'variables_text' => '[{"code":"m2","label":"Metros cuadrados"},{"code":"factor","label":"Factor"}]',
            'precision' => 3,
            'is_active' => true,
        ])
        ->assertRedirect();

    $formula = CalculationFormula::query()->where('code', 'obra_material_base')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($formula)->not->toBeNull()
        ->and($formula->expression['op'])->toBe('multiply')
        ->and($formula->variables[0]['code'])->toBe('m2')
        ->and($configuration['feature_flags']['formula_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_formula_calculations'])->toBeFalse()
        ->and($configuration['capabilities']['uses_construction_calculations'])->toBeFalse();
});

it('bloquea formulas de calculo con operadores no permitidos', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'calculation-formulas'), [
            'entity_type' => 'production_order',
            'code' => 'formula_insegura',
            'name' => 'Formula insegura',
            'result_type' => 'decimal',
            'expression_text' => '{"op":"eval","args":[{"var":"monto"}]}',
            'variables_text' => '[{"code":"monto","label":"Monto"}]',
            'precision' => 2,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('expression_text');

    expect(CalculationFormula::query()->where('code', 'formula_insegura')->exists())->toBeFalse();
});

it('crea reglas comerciales POS sin activar el perfil actual de ferreteria', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'commercial-flows'), [
            'code' => 'pos_retail_base',
            'name' => 'POS retail base',
            'business_type' => 'supermarket',
            'sales_workflow' => 'pos',
            'pos_mode' => 'barcode',
            'channel' => 'pos',
            'document_type' => 'ticket',
            'customer_mode' => 'optional',
            'cash_policy' => 'required',
            'inventory_timing' => 'payment',
            'payment_policy' => 'single_or_mixed',
            'allows_discount' => true,
            'allows_price_override' => false,
            'allows_credit' => false,
            'allows_advance' => false,
            'allows_split_payment' => false,
            'allows_mixed_payment' => true,
            'settings_text' => '{"quick_buttons":true,"show_images":false}',
            'is_active' => true,
        ])
        ->assertRedirect();

    $rule = BusinessCommercialFlowRule::query()->where('code', 'pos_retail_base')->first();
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($rule)->not->toBeNull()
        ->and($rule->sales_workflow)->toBe('pos')
        ->and($rule->cash_policy)->toBe('required')
        ->and($rule->settings['quick_buttons'])->toBeTrue()
        ->and($configuration['feature_flags']['commercial_flow_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_pos'])->toBeFalse()
        ->and($configuration['capabilities']['uses_commercial_flow_rules'])->toBeFalse()
        ->and($configuration['sales']['workflow'])->toBe('quotation_to_sale_note');
});

it('bloquea reglas comerciales de restaurante sin modo POS compatible', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'commercial-flows'), [
            'code' => 'mesa_mal_configurada',
            'name' => 'Mesa mal configurada',
            'business_type' => 'restaurant',
            'sales_workflow' => 'restaurant_table',
            'pos_mode' => 'retail',
            'channel' => 'table',
            'document_type' => 'ticket',
            'customer_mode' => 'optional',
            'cash_policy' => 'required',
            'inventory_timing' => 'payment',
            'payment_policy' => 'single_or_mixed',
            'requires_resource' => true,
            'allows_mixed_payment' => true,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('pos_mode');

    expect(BusinessCommercialFlowRule::query()->where('code', 'mesa_mal_configurada')->exists())->toBeFalse();
});

it('bloquea definiciones de adjuntos con extensiones o mime fuera de politica', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'attachments'), [
            'entity_type' => 'customer',
            'code' => 'archivo_invalido',
            'label' => 'Archivo invalido',
            'purpose' => 'evidence',
            'allowed_extensions_csv' => 'exe',
            'allowed_mime_types_csv' => 'application/x-msdownload',
            'max_file_mb' => 10,
            'storage_disk' => 'local',
            'path_prefix' => 'business-attachments',
            'retention_policy' => 'standard',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('allowed_extensions_csv');

    expect(AttachmentDefinition::query()->count())->toBe(0);
});

it('permite campos personalizados sobre entidades dinamicas activas y los oculta al desactivarlas', function () {
    $user = transversalUser();
    $entity = DynamicEntity::query()->create([
        'code' => 'vehiculo',
        'label' => 'Vehiculo',
        'plural_label' => 'Vehiculos',
        'base_entity' => 'customer',
        'module' => 'service_orders',
        'mode' => 'optional',
        'is_visible' => true,
        'is_editable' => true,
        'is_auditable' => true,
        'retention_policy' => 'standard',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('system-superadmin.transversal-config.store', 'custom-fields'), [
            'entity_type' => 'vehiculo',
            'code' => 'placa',
            'label' => 'Placa',
            'type' => 'text',
            'is_required' => true,
            'visible_in_forms' => true,
            'visible_in_documents' => true,
            'visible_in_reports' => true,
            'sort_order' => 1,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect(CustomFieldDefinition::query()->where('entity_type', 'vehiculo')->where('code', 'placa')->exists())->toBeTrue()
        ->and(app(CustomFieldRuntimeService::class)->definitionsFor('vehiculo'))->toHaveCount(1);

    $this->actingAs($user)
        ->delete(route('system-superadmin.transversal-config.destroy', ['entities', $entity->id]))
        ->assertRedirect();

    expect($entity->refresh()->is_active)->toBeFalse()
        ->and(app(DynamicEntityRegistry::class)->isUsable('vehiculo'))->toBeFalse()
        ->and(app(CustomFieldRuntimeService::class)->definitionsFor('vehiculo'))->toHaveCount(0);
});

it('bloquea crear entidades dinamicas con codigos reservados del sistema', function () {
    $user = transversalUser();

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'entities'), [
            'code' => 'product',
            'label' => 'Producto duplicado',
            'mode' => 'optional',
            'retention_policy' => 'standard',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('code');

    expect(DynamicEntity::query()->where('code', 'product')->exists())->toBeFalse();
});

it('actualiza y desactiva recursos reservables con trazabilidad', function () {
    $user = transversalUser();
    $branch = Branch::query()->findOrFail($user->branch_id);
    $worker = Worker::query()->create([
        'branch_id' => $branch->id,
        'name' => 'Tecnico responsable',
        'position' => 'Tecnico',
        'salary_amount' => 0,
        'salary_frequency' => 'monthly',
        'is_active' => true,
    ]);
    $resource = ReservableResource::query()->create([
        'branch_id' => $branch->id,
        'type' => 'table',
        'code' => 'M-01',
        'name' => 'Mesa 1',
        'capacity' => 4,
        'status' => 'available',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('system-superadmin.transversal-config.update', ['resources', $resource->id]), [
            'branch_id' => $branch->id,
            'assigned_worker_id' => $worker->id,
            'type' => 'table',
            'code' => 'M-01',
            'name' => 'Mesa principal',
            'capacity' => 6,
            'status' => 'maintenance',
            'location' => 'Salon norte',
            'maintenance_starts_at' => '2026-08-01 08:00:00',
            'maintenance_ends_at' => '2026-08-01 10:00:00',
            'availability_rules_text' => '{"days":[1,2,3,4,5],"time_ranges":[{"start":"08:00","end":"18:00"}],"buffer_minutes":15}',
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($resource->refresh()->name)->toBe('Mesa principal')
        ->and($resource->capacity)->toBe(6)
        ->and($resource->assigned_worker_id)->toBe($worker->id)
        ->and($resource->status)->toBe('maintenance')
        ->and($resource->location)->toBe('Salon norte')
        ->and($resource->availability_rules['buffer_minutes'])->toBe(15);

    $this->actingAs($user)
        ->delete(route('system-superadmin.transversal-config.destroy', ['resources', $resource->id]))
        ->assertRedirect();

    expect($resource->refresh()->is_active)->toBeFalse();
});

it('bloquea responsable de otra sucursal al configurar recursos reservables', function () {
    $user = transversalUser();
    $branch = Branch::query()->findOrFail($user->branch_id);
    $otherBranch = Branch::query()->create([
        'name' => 'Otra sucursal',
        'code' => 'OT-'.uniqid(),
        'barcode' => 'BR-OT-'.uniqid(),
        'is_active' => true,
    ]);
    $worker = Worker::query()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Tecnico externo',
        'position' => 'Tecnico',
        'salary_amount' => 0,
        'salary_frequency' => 'monthly',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.transversal-config.index'))
        ->post(route('system-superadmin.transversal-config.store', 'resources'), [
            'branch_id' => $branch->id,
            'assigned_worker_id' => $worker->id,
            'type' => 'technician',
            'code' => 'TEC-EXT',
            'name' => 'Tecnico cruzado',
            'status' => 'available',
            'is_active' => true,
        ])
        ->assertRedirect(route('system-superadmin.transversal-config.index'))
        ->assertSessionHasErrors('assigned_worker_id');
});

it('gestiona estados, monedas e impresoras base', function () {
    $user = transversalUser();

    $this->actingAs($user)->post(route('system-superadmin.transversal-config.store', 'states'), [
        'entity_type' => 'service_order',
        'code' => 'diagnostico',
        'label' => 'Diagnostico',
        'color' => '#0ea5e9',
        'is_initial' => true,
        'is_final' => false,
        'allowed_transitions_csv' => 'en_reparacion,listo',
        'sort_order' => 1,
        'is_active' => true,
    ])->assertRedirect();

    $this->actingAs($user)->post(route('system-superadmin.transversal-config.store', 'currencies'), [
        'code' => 'EUR',
        'name' => 'Euro',
        'symbol' => 'EUR',
        'exchange_rate_to_base' => 7.5,
        'rounding_decimals' => 2,
        'is_base' => false,
        'cash_enabled' => true,
        'is_active' => true,
    ])->assertRedirect();

    $this->actingAs($user)->post(route('system-superadmin.transversal-config.store', 'printers'), [
        'branch_id' => null,
        'code' => 'cocina',
        'name' => 'Impresora cocina',
        'area' => 'kitchen',
        'paper_type' => 'thermal_80',
        'thermal_width_mm' => 80,
        'copies' => 1,
        'auto_print' => true,
        'is_active' => true,
    ])->assertRedirect();

    expect(BusinessStateDefinition::query()->where('code', 'diagnostico')->exists())->toBeTrue()
        ->and(BusinessCurrency::query()->where('code', 'EUR')->exists())->toBeTrue()
        ->and(PrinterProfile::query()->where('code', 'cocina')->value('auto_print'))->toBeTrue();
});

it('bloquea configuracion transversal dentro de demo completa para evitar bucles', function () {
    $user = transversalUser();
    $session = BusinessProfileSandboxSession::query()->create([
        'user_id' => $user->id,
        'name' => 'Demo transversal QA',
        'payload' => [],
        'status' => 'active',
        'expires_at' => now()->addHour(),
        'last_activity_at' => now(),
    ]);

    $this->withSession(['business_full_sandbox_id' => $session->id])
        ->actingAs($user)
        ->get(route('system-superadmin.transversal-config.index'))
        ->assertRedirect(route('dashboard'));
});
