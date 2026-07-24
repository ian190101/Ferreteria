<?php

use App\Models\Audit;
use App\Models\User;
use App\Support\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function auditViewer(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'auditoria-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('muestra registros de auditoria a usuarios autorizados', function () {
    $user = auditViewer(['audit.view']);

    Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $user->id,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $user->id,
        'old_values' => json_encode(['name' => 'Anterior']),
        'new_values' => json_encode(['name' => 'Nuevo']),
        'ip_address' => '127.0.0.1',
        'url' => 'http://127.0.0.1',
        'user_agent' => 'Pest',
    ]);

    $this->actingAs($user)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index', false)
            ->has('audits.data', 1)
        );
});

it('bloquea auditoria sin permiso', function () {
    $user = auditViewer([]);

    $this->actingAs($user)
        ->get(route('audit.index'))
        ->assertForbidden();
});

it('oculta auditoria generada por el usuario interno sistemasuperadmin', function () {
    $viewer = auditViewer(['audit.view']);
    $viewer->assignRole(Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']));

    $systemRole = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $master = User::factory()->create([
        'name' => 'Mr. Robot Bolivia',
        'email' => 'mrrobotbolivia@gmail.com',
    ]);
    $master->assignRole($systemRole);

    Audit::query()->create([
        'user_type' => User::class,
        'user_id' => $master->id,
        'event' => 'updated',
        'auditable_type' => User::class,
        'auditable_id' => $master->id,
        'old_values' => json_encode(['name' => 'Anterior']),
        'new_values' => json_encode(['name' => 'Nuevo']),
        'ip_address' => '127.0.0.1',
        'url' => 'http://127.0.0.1',
        'user_agent' => 'Pest',
    ]);

    $this->actingAs($viewer)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertDontSee('mrrobotbolivia@gmail.com')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index', false)
            ->has('audits.data', 0)
        );
});
