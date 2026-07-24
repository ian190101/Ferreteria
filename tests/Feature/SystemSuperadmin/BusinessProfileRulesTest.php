<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Models\BusinessProfileDraft;
use App\Modules\SystemSuperadmin\Models\BusinessProfilePreset;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Support\SystemRoles;
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

it('valida columnas y metodos avanzados antes de guardar un borrador', function () {
    $user = businessProfileUser(systemSuperadmin: true);
    $configuration = BusinessProfileConfiguration::defaults();
    $configuration['sales']['visible_columns'] = ['description', 'columna_invalida'];
    $configuration['sales']['allowed_payment_methods'] = ['cash', 'metodo_invalido'];

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
