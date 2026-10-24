<?php

use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemRoles;
use Tests\Support\ManagedLotScenario;

it('conserva apagada la regla antigua y normaliza false textual', function () {
    $configuration = BusinessProfileConfiguration::normalized([
        'capabilities' => ['uses_lots' => true],
        'inventory' => ['lot_tracking_enabled' => 'false'],
    ]);

    expect($configuration['inventory']['lot_tracking_enabled'])->toBeFalse()
        ->and($configuration['sales']['reserve_lots_on_quotation'])->toBeFalse()
        ->and(app(LotTrackingPolicy::class)->enabled($configuration))->toBeFalse();
});

it('reserva cambios del perfil activo al rol oculto de sistemas', function () {
    $branch = ManagedLotScenario::branch();
    ManagedLotScenario::profile(false);
    $admin = ManagedLotScenario::user($branch, SystemRoles::BUSINESS_ADMIN, ['inventory.coils.manage']);

    $this->actingAs($admin)->put(route('system-superadmin.business-profiles.active.update'), [
        'configuration' => ['inventory' => ['lot_tracking_enabled' => true]],
    ])->assertForbidden();
    $this->actingAs($admin)->post(route('system-superadmin.business-profiles.save-and-apply'), [
        'configuration' => ['inventory' => ['lot_tracking_enabled' => true]],
    ])->assertForbidden();
});
