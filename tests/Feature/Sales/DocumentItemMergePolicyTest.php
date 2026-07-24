<?php

use App\Models\User;
use App\Modules\Sales\Services\DocumentItemMergePolicy;
use App\Modules\Settings\Models\SystemSetting;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function activeMergeProfile(array $sales = []): BusinessProfile
{
    Cache::flush();

    return BusinessProfile::query()->create([
        'name' => 'Perfil items',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'sales' => $sales,
        ]),
        'applied_at' => now(),
    ]);
}

function settingsUserForItemMerge(): User
{
    $permission = Permission::findOrCreate('settings.manage', 'web');
    $role = Role::findOrCreate('superadmin', 'web');
    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('permite el mismo producto con diferente medida por defecto', function () {
    activeMergeProfile();

    expect(app(DocumentItemMergePolicy::class)->summary())
        ->controlEnabled->toBeTrue()
        ->rule->toBe('same_product_unit_and_measure')
        ->allowSameProductDifferentMeasure->toBeTrue();
});

it('usa la regla del perfil cuando sistemasuperadmin oculta el ajuste al cliente', function () {
    activeMergeProfile([
        'item_merge_control_enabled' => false,
        'item_merge_rule' => 'same_product_and_unit',
    ]);

    SystemSetting::query()->updateOrCreate(
        ['key' => DocumentItemMergePolicy::SETTING_KEY],
        [
            'group' => 'ventas',
            'value' => ['allow_same_product_different_measure' => true],
            'description' => 'Prueba',
            'is_public' => true,
        ],
    );
    DocumentItemMergePolicy::forget();

    expect(app(DocumentItemMergePolicy::class)->summary())
        ->controlEnabled->toBeFalse()
        ->rule->toBe('same_product_and_unit')
        ->allowSameProductDifferentMeasure->toBeFalse();
});

it('permite al superadmin guardar la regla si el perfil lo habilita', function () {
    activeMergeProfile(['item_merge_control_enabled' => true]);
    $user = settingsUserForItemMerge();

    $this->actingAs($user)
        ->put(route('sales.settings.item-merge.update'), [
            'sales_item_merge' => [
                'allow_same_product_different_measure' => false,
            ],
        ])
        ->assertRedirect(route('sales.settings.index'));

    DocumentItemMergePolicy::forget();

    expect(app(DocumentItemMergePolicy::class)->allowSameProductDifferentMeasure())->toBeFalse();
});

it('bloquea al superadmin si sistemasuperadmin oculto la configuracion', function () {
    activeMergeProfile(['item_merge_control_enabled' => false]);
    $user = settingsUserForItemMerge();

    $this->actingAs($user)
        ->put(route('sales.settings.item-merge.update'), [
            'sales_item_merge' => [
                'allow_same_product_different_measure' => false,
            ],
        ])
        ->assertForbidden();
});
