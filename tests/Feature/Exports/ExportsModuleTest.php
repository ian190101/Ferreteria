<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use App\Support\SystemRoles;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function exportUser(array $permissions, string $roleName = 'exportaciones-test'): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Exportaciones central',
        'code' => 'EXP',
        'barcode' => 'BR-EXP',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);
    $user->accessibleBranches()->sync([$branch->id]);

    return $user;
}

function activeExportProfile(array $configuration): void
{
    BusinessProfile::query()->update(['status' => 'archived']);
    BusinessProfile::query()->create([
        'name' => 'Perfil exportaciones',
        'business_type' => 'mixed',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
    SystemCacheInvalidator::bumpOperational();
}

it('oculta modulos de facturacion en exportaciones cuando el perfil no los activa', function () {
    activeExportProfile([
        'modules' => [
            'exports' => true,
            'billing' => false,
        ],
        'billing' => [
            'enabled' => false,
            'invoice_flow' => 'billing_disabled',
        ],
    ]);
    $user = exportUser(['settings.manage', 'billing.view']);

    $this->actingAs($user)
        ->get(route('exports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Exports/Index', false)
            ->missing('catalog.billing_invoices')
            ->missing('catalog.billing_settings')
        );
});

it('permite al usuario tecnico ver catalogo completo de exportacion para soporte', function () {
    activeExportProfile([
        'modules' => [
            'exports' => false,
            'billing' => false,
        ],
        'billing' => [
            'enabled' => false,
            'invoice_flow' => 'billing_disabled',
        ],
    ]);
    Permission::firstOrCreate(['name' => 'settings.manage', 'guard_name' => 'web']);
    $systemRole = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $systemRole->givePermissionTo('settings.manage');
    $user = exportUser(['settings.manage'], SystemRoles::SYSTEM_SUPERADMIN);

    $this->actingAs($user)
        ->get(route('exports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Exports/Index', false)
            ->has('catalog')
        );
});

it('muestra datasets por rubro solo si el perfil y permisos los activan', function () {
    activeExportProfile([
        'modules' => [
            'exports' => true,
            'restaurant_tables' => true,
            'service_orders' => false,
            'reservations' => true,
            'production' => false,
        ],
        'capabilities' => [
            'uses_pos' => true,
            'uses_tables' => true,
            'uses_kitchen_orders' => true,
            'uses_reservations' => true,
        ],
    ]);
    $user = exportUser(['settings.manage', 'restaurant.view', 'reservations.view', 'service-orders.view', 'production.view']);

    $this->actingAs($user)
        ->get(route('exports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Exports/Index', false)
            ->has('catalog.restaurant_summary')
            ->has('catalog.reservations_summary')
            ->missing('catalog.service_orders_summary')
            ->missing('catalog.production_summary')
        );
});
