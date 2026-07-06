<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function branchesAdmin(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'sucursales-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Admin Branch',
        'code' => 'BRADMIN',
        'barcode' => 'BR-ADMIN',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea sucursal con branding y configuracion de tema', function () {
    $admin = branchesAdmin(['branches.view', 'branches.manage']);

    $this->actingAs($admin)
        ->post(route('branches.store'), [
            'name' => 'Sucursal Norte',
            'code' => 'NORTE',
            'barcode' => 'BR-NORTE',
            'phone' => '70000001',
            'secondary_phone' => '70000002',
            'point_of_sale_name' => 'Norte',
            'address' => 'Av. Norte',
            'is_active' => true,
            'setting' => [
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'logo_path' => 'logos/norte.png',
                'theme_mode' => 'dark',
            ],
        ])
        ->assertRedirect(route('branches.index'));

    $branch = Branch::query()->where('code', 'NORTE')->firstOrFail();

    expect($branch->setting)->toBeInstanceOf(BranchSetting::class)
        ->and($branch->setting->primary_color)->toBe('#123456')
        ->and($branch->setting->theme_mode)->toBe('dark');
});

it('actualiza branding de sucursal', function () {
    $admin = branchesAdmin(['branches.view', 'branches.manage']);
    $branch = Branch::query()->create([
        'name' => 'Sucursal Sur',
        'code' => 'SUR',
        'barcode' => 'BR-SUR',
        'is_active' => true,
    ]);
    $branch->setting()->create([
        'primary_color' => '#111111',
        'secondary_color' => '#222222',
        'theme_mode' => 'system',
    ]);

    $this->actingAs($admin)
        ->put(route('branches.update', $branch), [
            'name' => 'Sucursal Sur Editada',
            'code' => 'SUR',
            'barcode' => 'BR-SUR',
            'phone' => null,
            'secondary_phone' => null,
            'point_of_sale_name' => 'Sur',
            'address' => 'Av. Sur',
            'is_active' => true,
            'setting' => [
                'primary_color' => '#abcdef',
                'secondary_color' => '#fedcba',
                'logo_path' => null,
                'theme_mode' => 'light',
            ],
        ])
        ->assertRedirect(route('branches.index'));

    expect($branch->refresh()->name)->toBe('Sucursal Sur Editada')
        ->and($branch->setting->refresh()->primary_color)->toBe('#abcdef')
        ->and($branch->setting->theme_mode)->toBe('light');
});
