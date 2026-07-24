<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Models\MaintenanceBackup;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function settingsUser(array $permissions): User
{
    $suffix = strtoupper(substr(uniqid(), -6));

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'sistema-test-'.$suffix, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Sistema central',
        'code' => 'SYS-'.$suffix,
        'barcode' => 'BR-SYS-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('muestra la seccion de respaldos del sistema', function () {
    $user = settingsUser(['settings.manage']);

    $this->actingAs($user)
        ->get(route('settings.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/System/Index', false)
            ->has('backups.data', 0)
        );
});

it('genera backup operativo en disco local', function () {
    Storage::fake('local');

    $user = settingsUser(['settings.manage']);
    Product::query()->create([
        'name' => 'Producto backup',
        'sku' => 'BACK-001',
        'barcode' => 'PR-BACK-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('settings.system.backups.store'), ['format' => 'json'])
        ->assertRedirect(route('settings.system.index'));

    $backup = MaintenanceBackup::query()->firstOrFail();

    Storage::disk('local')->assertExists($backup->path);
    expect($backup->metadata['format'])->toBe('json');
});

it('bloquea sistema sin permiso', function () {
    $blocked = settingsUser([]);

    $this->actingAs($blocked)
        ->get(route('settings.system.index'))
        ->assertForbidden();
});
