<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function usersAdmin(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'usuarios-test-admin', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Central',
        'code' => 'USERS',
        'barcode' => 'BR-USERS',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea roles con permisos asignados', function () {
    $admin = usersAdmin(['users.view', 'users.manage', 'sales.view']);

    $this->actingAs($admin)
        ->post(route('users.roles.store'), [
            'name' => 'vendedor-test',
            'permissions' => ['sales.view'],
        ])
        ->assertRedirect(route('users.roles.index'));

    expect(Role::query()->where('name', 'vendedor-test')->firstOrFail()->hasPermissionTo('sales.view'))->toBeTrue();
});

it('crea usuarios con sucursal principal, multiples sucursales y rol', function () {
    $admin = usersAdmin(['users.view', 'users.manage']);
    $role = Role::query()->create(['name' => 'operador-test', 'guard_name' => 'web']);
    $branch = Branch::query()->create([
        'name' => 'Sucursal usuarios',
        'code' => 'USR-2',
        'barcode' => 'BR-USR-2',
        'is_active' => true,
    ]);
    $secondaryBranch = Branch::query()->create([
        'name' => 'Sucursal secundaria',
        'code' => 'USR-3',
        'barcode' => 'BR-USR-3',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id, $secondaryBranch->id],
            'name' => 'Operador',
            'email' => 'operador@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'is_active' => true,
            'roles' => ['operador-test'],
        ])
        ->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'operador@example.com')->firstOrFail();

    expect($user->branch_id)->toBe($branch->id)
        ->and($user->accessibleBranches()->pluck('branches.id')->all())->toEqualCanonicalizing([$branch->id, $secondaryBranch->id])
        ->and($user->hasRole('operador-test'))->toBeTrue();
});
