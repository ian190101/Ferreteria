<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileCompatibilityValidator;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use Database\Seeders\BusinessProfilePresetSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Support\RouteSecurityAuditService;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function businessProfileUser(array $permissions = [], bool $systemSuperadmin = false): User
{
    $branch = Branch::query()->create([
        'name' => 'Sucursal prueba perfil',
        'code' => 'BIZ-'.uniqid(),
        'barcode' => 'BR-BIZ-'.uniqid(),
        'is_active' => true,
    ]);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate([
        'name' => $systemSuperadmin ? SystemRoles::SYSTEM_SUPERADMIN : 'perfil-negocio-test',
        'guard_name' => 'web',
    ]);

    if ($permissions !== []) {
        $role->syncPermissions($permissions);
    }

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function activeBusinessProfile(array $overrides): BusinessProfile
{
    return BusinessProfile::query()->create([
        'name' => 'Perfil activo prueba',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($overrides),
        'applied_at' => now(),
    ]);
}

it('bloquea rutas secundarias cuando el perfil empresarial desactiva el modulo', function () {
    $user = businessProfileUser([
        'reports.view',
        'settings.manage',
        'expenses.view',
        'production.view',
        'sales.returns.view',
        'payment-promises.view',
        'inventory.reservations.view',
        'inventory.transfers.view',
    ]);
    activeBusinessProfile([
        'modules' => [
            'reports' => false,
            'exports' => false,
            'expenses' => false,
            'production' => false,
            'returns' => false,
            'payment_promises' => false,
            'reservations' => false,
            'transfers' => false,
        ],
    ]);

    $this->actingAs($user)->get(route('reports.index'))->assertNotFound();
    $this->actingAs($user)->get(route('exports.index'))->assertNotFound();
    $this->actingAs($user)->get(route('expenses.index'))->assertNotFound();
    $this->actingAs($user)->get(route('production.index'))->assertNotFound();
    $this->actingAs($user)->get(route('sales.returns.index'))->assertNotFound();
    $this->actingAs($user)->get(route('payments.promises.index'))->assertNotFound();
    $this->actingAs($user)->get(route('inventory.reservations.index'))->assertNotFound();
    $this->actingAs($user)->get(route('inventory.transfers.index'))->assertNotFound();
});

it('mantiene las rutas operativas protegidas por perfil empresarial y permisos', function () {
    $audit = app(RouteSecurityAuditService::class);

    expect($audit->routesMissingProfileGuard())->toBe([])
        ->and($audit->routesMissingAccessGuard())->toBe([]);
});

it('aplica cabeceras de seguridad transversales en respuestas autenticadas', function () {
    $user = businessProfileUser(['dashboard.view']);
    activeBusinessProfile([
        'modules' => [
            'dashboard' => true,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
        ->assertHeader('X-Download-Options', 'noopen');
});

it('oculta y bloquea datasets de modulos desactivados en exportaciones', function () {
    $user = businessProfileUser(['settings.manage', 'billing.view']);
    activeBusinessProfile([
        'modules' => [
            'exports' => true,
            'billing' => false,
            'inventory' => true,
        ],
        'billing' => [
            'enabled' => false,
        ],
    ]);

    $this->actingAs($user)
        ->get(route('exports.index'))
        ->assertOk()
        ->assertDontSee('Facturacion SIAT')
        ->assertDontSee('Configuracion SIAT');

    $request = Request::create(route('exports.download'), 'POST', [
        'format' => 'pdf',
        'modules' => ['billing_invoices'],
    ]);
    $request->setUserResolver(fn () => $user);

    expect(app(ExportDatasetService::class)->catalog($request))->not->toHaveKey('billing_invoices');

    app(ExportDatasetService::class)->build($request);
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Debe seleccionar al menos un modulo para exportar.');

it('solo permite entrar al configurador empresarial al rol sistemasuperadmin', function () {
    $normalUser = businessProfileUser();
    $systemUser = businessProfileUser(systemSuperadmin: true);

    $this->actingAs($normalUser)
        ->get(route('system-superadmin.business-profiles.index'))
        ->assertForbidden();

    $this->actingAs($systemUser)
        ->get(route('system-superadmin.business-profiles.index'))
        ->assertOk();
});

it('expone readiness de presets oficiales en el configurador maestro', function () {
    $systemUser = businessProfileUser(systemSuperadmin: true);

    $this->seed(BusinessProfilePresetSeeder::class);

    $this->actingAs($systemUser)
        ->get(route('system-superadmin.business-profiles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SystemSuperadmin/BusinessProfiles/Index', false)
            ->has('presets', 32)
            ->has('presetReadiness.Ferreteria con cotizacion y nota')
            ->where('presetReadiness.Ferreteria con cotizacion y nota.ready', true)
            ->where('presetReadiness.Alquiler equipos y herramientas.ready', true)
        );
});

it('valida columnas y metodos avanzados antes de guardar un borrador', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $configuration = BusinessProfileConfiguration::defaults();
    $configuration['sales']['visible_columns'] = ['description', 'columna_invalida'];
    $configuration['sales']['allowed_payment_methods'] = ['cash', 'metodo_invalido'];
    $configuration['field_permissions']['rules'] = [[
        'entity' => 'customer',
        'field' => 'historia_clinica',
        'actions' => ['leer_sin_permiso'],
        'effect' => 'allow',
    ]];

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.drafts.store'), [
            'name' => 'Borrador invalido',
            'business_type' => 'mixed',
            'configuration' => $configuration,
        ])
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHasErrors([
            'configuration.sales.visible_columns.1',
            'configuration.sales.allowed_payment_methods.1',
            'configuration.field_permissions.rules.0.actions.0',
        ]);
});

it('permite guardar presets personalizados y crear borradores sin tocar produccion', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $configuration = BusinessProfileConfiguration::defaults();
    $configuration['sales']['workflow'] = 'pos';
    $configuration['sales']['quotation_mode'] = 'disabled';

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.presets.store'), [
            'name' => 'Preset tienda rapida',
            'business_type' => 'store',
            'description' => 'Venta directa para tienda de prueba.',
            'configuration' => $configuration,
        ])
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHas('success');

    $preset = BusinessProfilePreset::query()->where('name', 'Preset tienda rapida')->firstOrFail();

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.presets.draft', $preset))
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHas('success');

    expect(BusinessProfileDraft::query()->where('name', 'Preset tienda rapida - borrador')->exists())->toBeTrue()
        ->and(BusinessProfile::query()->where('name', 'Preset tienda rapida')->exists())->toBeFalse();
});

it('bloquea eliminar presets del sistema', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $preset = BusinessProfilePreset::query()->create([
        'name' => 'Preset protegido',
        'business_type' => 'hardware_store',
        'description' => 'Preset base del sistema.',
        'is_system' => true,
        'configuration' => BusinessProfileConfiguration::defaults(),
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->delete(route('system-superadmin.business-profiles.presets.destroy', $preset))
        ->assertStatus(422);

    expect(BusinessProfilePreset::query()->whereKey($preset->id)->exists())->toBeTrue();
});

it('revalida compatibilidad backend antes de aplicar un borrador', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $active = activeBusinessProfile([
        'modules' => ['billing' => false],
        'billing' => ['enabled' => false, 'invoice_flow' => 'billing_disabled'],
    ]);
    $configuration = BusinessProfileConfiguration::normalized([
        'modules' => [
            'billing' => true,
            'cash' => true,
        ],
        'sales' => [
            'customer_mode' => 'hidden',
        ],
        'billing' => [
            'enabled' => true,
            'invoice_flow' => 'direct_invoice',
        ],
    ]);

    $draft = BusinessProfileDraft::query()->create([
        'name' => 'Borrador fiscal incompatible',
        'business_type' => 'supermarket',
        'configuration' => $configuration,
        'source_profile_id' => $active->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.drafts.apply', $draft))
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHasErrors('configuration');

    expect($draft->refresh()->status)->not->toBe('applied')
        ->and(BusinessProfile::query()->where('status', 'active')->latest('applied_at')->first()?->id)->toBe($active->id);
});

it('bloquea aplicar un borrador si el checklist de activacion tiene pendientes criticos', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $active = activeBusinessProfile([]);
    $configuration = BusinessProfileConfiguration::normalized([]);

    $draft = BusinessProfileDraft::query()->create([
        'name' => 'Borrador sin datos minimos',
        'business_type' => 'hardware_store',
        'configuration' => $configuration,
        'source_profile_id' => $active->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.drafts.apply', $draft))
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHasErrors('configuration');

    expect($draft->refresh()->status)->not->toBe('applied')
        ->and(BusinessProfile::query()->where('status', 'active')->latest('applied_at')->first()?->id)->toBe($active->id);
});

it('aplica un borrador cuando pasa compatibilidad y checklist sin cambiar defaults de ferreteria', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $active = activeBusinessProfile([]);
    ProductUnit::query()->create(['name' => 'Unidad', 'symbol' => 'u', 'kind' => 'count', 'is_active' => true]);
    Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    Product::query()->create([
        'name' => 'Producto base perfil',
        'sku' => 'PERFIL-BASE',
        'barcode' => 'PERFIL-BASE-BAR',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'unidad',
        'sale_price' => 10,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $configuration = BusinessProfileConfiguration::normalized([]);
    $configuration['identity']['commercial_name'] = 'Ferreteria QA';

    $draft = BusinessProfileDraft::query()->create([
        'name' => 'Borrador ferreteria valido',
        'business_type' => 'hardware_store',
        'configuration' => $configuration,
        'source_profile_id' => $active->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('system-superadmin.business-profiles.index'))
        ->post(route('system-superadmin.business-profiles.drafts.apply', $draft))
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionHas('success');

    $applied = BusinessProfile::query()
        ->where('status', 'active')
        ->orderByDesc('applied_at')
        ->orderByDesc('id')
        ->firstOrFail();

    expect($draft->refresh()->status)->toBe('applied')
        ->and($applied->name)->toBe('Borrador ferreteria valido')
        ->and($applied->configuration['identity']['commercial_name'])->toBe('Ferreteria QA')
        ->and(ActiveBusinessProfile::navigationPayload()['commercialName'])->toBe('Ferreteria QA')
        ->and($applied->configuration['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and($applied->configuration['feature_flags']['dynamic_entities_engine'])->toBeFalse()
        ->and($applied->configuration['capabilities']['uses_dynamic_entities'])->toBeFalse();
});

it('guarda y reinicia una demo sandbox aislada por usuario', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $otherUser = businessProfileUser(systemSuperadmin: true);
    $session = app(BusinessProfileSandboxService::class)->sessionFor($user->id);

    $payload = $session->payload;
    $payload['products'] = [[
        'id' => 999,
        'name' => 'Producto demo persistido',
        'unit' => 'unidad',
        'stock' => 12,
        'price' => 5,
        'status' => 'Activo',
    ]];
    $payload['audit'] = ['Producto creado solo dentro del sandbox.'];

    $this->actingAs($user)
        ->putJson(route('system-superadmin.business-profiles.sandbox.update', $session), [
            'payload' => $payload,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Demo sandbox guardada sin afectar produccion.');

    expect(BusinessProfileSandboxSession::query()->find($session->id)->payload['products'][0]['name'])->toBe('Producto demo persistido');

    $this->actingAs($otherUser)
        ->putJson(route('system-superadmin.business-profiles.sandbox.update', $session), [
            'payload' => $payload,
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson(route('system-superadmin.business-profiles.sandbox.reset', $session))
        ->assertOk()
        ->assertJsonPath('message', 'Demo sandbox reiniciada desde datos reales actuales.');
});

it('entra y descarta la demo completa con base temporal aislada', function () {
    $user = businessProfileUser(systemSuperadmin: true);

    if (DB::getDriverName() !== 'mysql') {
        $this->actingAs($user)
            ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
            ->assertRedirect()
            ->assertSessionHas('error', 'La demo completa requiere MySQL o MariaDB para clonar una base aislada.');

        return;
    }

    $this->actingAs($user)
        ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('business_full_sandbox_id');

    $session = BusinessProfileSandboxSession::query()
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->latest('id')
        ->firstOrFail();

    expect($session->database_name)->not->toBeNull()
        ->and(collect(DB::select("SHOW DATABASES LIKE '{$session->database_name}'"))->isNotEmpty())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('system-superadmin.business-profiles.sandbox-full.discard'))
        ->assertRedirect(route('system-superadmin.business-profiles.index'))
        ->assertSessionMissing('business_full_sandbox_id');

    expect(collect(DB::select("SHOW DATABASES LIKE '{$session->database_name}'"))->isEmpty())->toBeTrue();
});

it('bloquea el configurador maestro dentro de la demo completa para evitar demos anidadas', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $session = BusinessProfileSandboxSession::query()->create([
        'user_id' => $user->id,
        'name' => 'Demo completa QA',
        'payload' => [],
        'status' => 'active',
        'expires_at' => now()->addHour(),
        'last_activity_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['business_full_sandbox_id' => $session->id])
        ->get(route('system-superadmin.business-profiles.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning', 'El configurador maestro no esta disponible dentro de la demo completa. Sal de la demo para cambiar la configuracion del negocio.');
});

it('limpia una demo completa expirada y permite volver al configurador maestro', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $expiredSession = BusinessProfileSandboxSession::query()->create([
        'user_id' => $user->id,
        'name' => 'Demo completa vencida QA',
        'payload' => [],
        'status' => 'active',
        'expires_at' => now()->subMinute(),
        'last_activity_at' => now()->subHours(9),
    ]);

    $this->actingAs($user)
        ->withSession(['business_full_sandbox_id' => $expiredSession->id])
        ->get(route('system-superadmin.business-profiles.index'))
        ->assertOk()
        ->assertSessionMissing('business_full_sandbox_id');
});

it('renueva la vigencia de una demo sandbox activa por actividad', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $session = BusinessProfileSandboxSession::query()->create([
        'user_id' => $user->id,
        'name' => 'Demo completa activa QA',
        'payload' => [],
        'status' => 'active',
        'expires_at' => now()->addMinutes(10),
        'last_activity_at' => now()->subHour(),
    ]);

    $resolved = app(BusinessProfileSandboxService::class)->activeSessionById($session->id, $user->id);

    expect($resolved)->not->toBeNull()
        ->and($resolved->expires_at->greaterThan(now()->addHours(7)))->toBeTrue();
});

it('permite crear y visualizar cotizaciones y notas de venta dentro de la demo completa sin generar 404', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('La demo completa requiere MySQL o MariaDB.');
    }

    $user = businessProfileUser(['dashboard.view', 'sales.view', 'sales.manage'], systemSuperadmin: true);
    activeBusinessProfile([
        'modules' => [
            'dashboard' => true,
            'sales_notes' => true,
            'quotes' => true,
            'cash' => false,
        ],
        'sales' => [
            'workflow' => 'quotation_to_sale_note',
            'quotation_mode' => 'required',
            'customer_mode' => 'optional',
            'inventory_discount_timing' => 'sale_note',
        ],
        'cash' => [
            'required_to_sell' => false,
        ],
    ]);

    $saleType = SaleType::query()->create(['name' => 'Ocasionales demo', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos demo',
        'code' => 'BOB-DEMO',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Producto cotizacion sandbox',
        'sku' => 'SANDBOX-COT',
        'barcode' => 'SANDBOX-COT-BAR',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'unidad',
        'allowed_units' => ['unidad'],
        'sale_price' => 12,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 20,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->post(route('system-superadmin.business-profiles.sandbox-full.enter'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('business_full_sandbox_id');

    $response = $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'quotation',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_mode' => 'none',
            'receipt_number' => 'COT-SANDBOX-001',
            'customer_name' => 'Cliente sandbox',
            'customer_document' => '123',
            'customer_contact' => '70000000',
            'terms' => 'Cotizacion sandbox.',
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'Producto cotizacion sandbox',
                'unit_label' => 'unidad',
                'display_quantity' => 2,
                'display_unit_label' => 'unidad',
                'calculation_mode' => 'direct',
                'meters' => 2,
                'unit_price' => 12,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sandboxSale = Sale::query()->where('receipt_number', 'COT-SANDBOX-001')->firstOrFail();

    $this->actingAs($user)
        ->get(route('sales.show', $sandboxSale))
        ->assertOk();

    $noteResponse = $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'source_quotation_id' => $sandboxSale->id,
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_mode' => 'none',
            'receipt_number' => 'NV-SANDBOX-001',
            'customer_name' => 'Cliente sandbox',
            'customer_document' => '123',
            'customer_contact' => '70000000',
            'terms' => 'Nota sandbox.',
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'Producto cotizacion sandbox',
                'unit_label' => 'unidad',
                'display_quantity' => 2,
                'display_unit_label' => 'unidad',
                'calculation_mode' => 'direct',
                'meters' => 2,
                'unit_price' => 12,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $sandboxNote = Sale::query()->where('receipt_number', 'NV-SANDBOX-001')->firstOrFail();

    expect($noteResponse->headers->get('Location'))->toBe(route('sales.show', $sandboxNote));

    $this->actingAs($user)
        ->get(route('sales.show', $sandboxNote))
        ->assertOk();
});

it('permite abrir cotizaciones cuando el perfil tiene solo el modulo de cotizaciones activo', function () {
    $user = businessProfileUser(['sales.view', 'sales.manage']);
    activeBusinessProfile([
        'modules' => [
            'sales_notes' => false,
            'quotes' => true,
        ],
        'sales' => [
            'workflow' => 'quotation_to_sale_note',
            'quotation_mode' => 'required',
            'customer_mode' => 'optional',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('sales.create', ['type' => 'quotation']))
        ->assertOk();
});

it('normaliza el nucleo de negocio 2 sin activar motores nuevos en ferreteria por defecto', function () {
    $configuration = BusinessProfileConfiguration::normalized([]);

    expect($configuration['schema_version'])->toBe(2)
        ->and($configuration['identity']['business_type'])->toBe('hardware_store')
        ->and($configuration['modules']['quotes'])->toBeTrue()
        ->and($configuration['modules']['sales_notes'])->toBeTrue()
        ->and($configuration['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and($configuration['feature_flags']['business_profile_v2_core'])->toBeTrue()
        ->and($configuration['feature_flags']['dynamic_entities_engine'])->toBeFalse()
        ->and($configuration['capabilities']['uses_dynamic_entities'])->toBeFalse()
        ->and($configuration['entities']['defaults_locked'])->toBeTrue()
        ->and($configuration['fields']['max_fields_per_entity'])->toBe(80)
        ->and($configuration['governance']['soft_delete_by_default'])->toBeTrue()
        ->and($configuration['attachments']['enabled'])->toBeFalse()
        ->and($configuration['migration']['preserve_legacy_behavior'])->toBeTrue()
        ->and($configuration['performance']['paginated_dynamic_lists'])->toBeTrue();
});

it('expone feature flags del perfil activo sin cambiar el comportamiento operativo de ferreteria', function () {
    activeBusinessProfile([]);
    Cache::flush();

    $payload = ActiveBusinessProfile::payload();

    expect($payload['businessType'])->toBe('mixed')
        ->and($payload['schemaVersion'])->toBe(2)
        ->and($payload['modules']['quotes'])->toBeTrue()
        ->and($payload['modules']['sales_notes'])->toBeTrue()
        ->and($payload['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and($payload['feature_flags']['business_profile_v2_core'])->toBeTrue()
        ->and(ActiveBusinessProfile::featureEnabled('business_profile_v2_core'))->toBeTrue()
        ->and(ActiveBusinessProfile::featureEnabled('dynamic_entities_engine'))->toBeFalse()
        ->and(ActiveBusinessProfile::capable('uses_dynamic_entities'))->toBeFalse();
});

it('bloquea capacidades avanzadas si el motor tecnico correspondiente no esta habilitado', function () {
    $configuration = BusinessProfileConfiguration::normalized([
        'capabilities' => [
            'uses_dynamic_entities' => true,
        ],
        'feature_flags' => [
            'dynamic_entities_engine' => false,
        ],
    ]);

    $result = app(BusinessProfileCompatibilityValidator::class)->validate('mixed', $configuration);

    expect($result['valid'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('dynamic_entities_engine');
});

it('permite capacidades avanzadas cuando su feature flag tecnico esta habilitado', function () {
    $configuration = BusinessProfileConfiguration::normalized([
        'capabilities' => [
            'uses_dynamic_entities' => true,
            'uses_dynamic_fields' => true,
        ],
        'feature_flags' => [
            'dynamic_entities_engine' => true,
            'dynamic_fields_engine' => true,
        ],
    ]);

    $result = app(BusinessProfileCompatibilityValidator::class)->validate('mixed', $configuration);

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});
