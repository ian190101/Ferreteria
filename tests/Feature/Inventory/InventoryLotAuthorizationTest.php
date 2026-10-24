<?php

use App\Support\SystemRoles;
use Tests\Support\ManagedLotScenario;

it('niega edición y anulación a un operador aunque pueda gestionar bobinas', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $operator = ManagedLotScenario::user($branch, 'operador-lotes', ['inventory.coils.manage']);

    $this->actingAs($operator)->patch(route('inventory.lots.update', [$product, $lot]), [
        'lot_code' => 'LT2026B01',
    ])->assertForbidden();
    $this->actingAs($operator)->post(route('inventory.lots.void', [$product, $lot]), [
        'void_reason' => 'Prueba',
    ])->assertForbidden();
    expect($lot->fresh()->lot_code)->toBe('LT2026A01');
});

it('reconoce únicamente los dos roles administradores', function () {
    $branch = ManagedLotScenario::branch();
    $businessAdmin = ManagedLotScenario::user($branch, SystemRoles::BUSINESS_ADMIN);
    $systemAdmin = ManagedLotScenario::user($branch, SystemRoles::SYSTEM_SUPERADMIN);
    $authorization = app(\App\Modules\Inventory\Services\LotAuthorization::class);
    expect($authorization->isAdministrator($businessAdmin))->toBeTrue()
        ->and($authorization->isAdministrator($systemAdmin))->toBeTrue();
});
