<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\DynamicEntity;
use App\Modules\SystemSuperadmin\Models\DynamicRelationshipDefinition;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
use App\Modules\SystemSuperadmin\Models\WorkflowDefinition;
use App\Modules\SystemSuperadmin\Models\WorkflowTransition;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\CustomFieldRuntimeService;
use App\Modules\SystemSuperadmin\Services\DynamicEntityRegistry;
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
            ->has('summary'));

    $this->actingAs($clientSuperadmin)
        ->get(route('system-superadmin.transversal-config.index'))
        ->assertForbidden();
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
