<?php

declare(strict_types=1);

use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\ProductionLogService;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use Illuminate\Support\Facades\DB;
use Tests\Support\MaintenanceScenario;

it('exige un respaldo en produccion incluso si el cliente lo desmarca y conserva los datos si falla', function () {
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales'], ['create_backup' => false]);
    $backupService = Mockery::mock(MaintenanceBackupService::class);
    $backupService->shouldReceive('generate')->once()->with('sql', $user, Mockery::type('array'))
        ->andThrow(new RuntimeException('No hay espacio para escribir el respaldo'));
    $backupService->shouldNotReceive('verifyRecovery');
    $service = new DatabasePurgeService($backupService, app(ProductionLogService::class));
    app()->instance('env', 'production');
    try {
        expect(fn () => $service->executePurge($options, $user))->toThrow(RuntimeException::class, 'No hay espacio');
        expect(DB::table('sales')->where('id', $sale->id)->exists())->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
});

it('bloquea la reutilizacion de identificadores con referencias en trazas protegidas', function () {
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    DB::table('operational_traces')->insert(['entity_type' => 'sale', 'entity_id' => $sale->id,
        'event' => 'qa_reference', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $preview = app(DatabasePurgeService::class)->preview(['scope' => 'full_reset', 'reset_auto_increment' => true], $user);
    expect($preview['can_execute'])->toBeFalse()
        ->and(implode(' ', $preview['blockers']))->toContain('trazas protegidas')
        ->and(DB::table('sales')->where('id', $sale->id)->exists())->toBeTrue();
});
