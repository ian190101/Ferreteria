<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\SystemSetting;
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

it('muestra y actualiza configuracion general', function () {
    $user = settingsUser(['settings.manage']);
    $setting = SystemSetting::query()->create([
        'group' => 'performance',
        'key' => 'cache_ttl_minutes',
        'value' => ['value' => '5'],
        'description' => 'Cache',
    ]);

    $this->actingAs($user)
        ->get(route('settings.system.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/System/Index', false)
            ->has('settings', 1)
            ->where('settings.0.key', 'cache_ttl_minutes')
        );

    $this->actingAs($user)
        ->put(route('settings.system.update'), [
            'settings' => [[
                'key' => $setting->key,
                'value' => '10',
            ]],
        ])
        ->assertRedirect(route('settings.system.index'));

    expect($setting->refresh()->value['value'])->toBe('10');
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
        ->post(route('settings.system.backups.store'))
        ->assertRedirect(route('settings.system.index'));

    $backup = MaintenanceBackup::query()->firstOrFail();

    Storage::disk('local')->assertExists($backup->path);
    expect($backup->metadata['products'])->toBe(1);
});

it('exporta clientes en csv y bloquea sin permiso', function () {
    $user = settingsUser(['settings.manage']);
    $blocked = settingsUser([]);
    Customer::query()->create([
        'name' => 'Cliente export',
        'document_number' => '999',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('settings.system.exports.show', 'customers'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $this->actingAs($blocked)
        ->get(route('settings.system.index'))
        ->assertForbidden();
});
