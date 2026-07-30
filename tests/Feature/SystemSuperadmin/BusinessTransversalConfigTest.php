<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\BusinessCurrency;
use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Models\BusinessStateDefinition;
use App\Modules\SystemSuperadmin\Models\CustomFieldDefinition;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use App\Modules\SystemSuperadmin\Models\ReservableResource;
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

it('actualiza y desactiva recursos reservables con trazabilidad', function () {
    $user = transversalUser();
    $branch = Branch::query()->first();
    $resource = ReservableResource::query()->create([
        'branch_id' => $branch->id,
        'type' => 'table',
        'code' => 'M-01',
        'name' => 'Mesa 1',
        'capacity' => 4,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('system-superadmin.transversal-config.update', ['resources', $resource->id]), [
            'branch_id' => $branch->id,
            'type' => 'table',
            'code' => 'M-01',
            'name' => 'Mesa principal',
            'capacity' => 6,
            'is_active' => true,
        ])
        ->assertRedirect();

    expect($resource->refresh()->name)->toBe('Mesa principal')
        ->and($resource->capacity)->toBe(6);

    $this->actingAs($user)
        ->delete(route('system-superadmin.transversal-config.destroy', ['resources', $resource->id]))
        ->assertRedirect();

    expect($resource->refresh()->is_active)->toBeFalse();
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
