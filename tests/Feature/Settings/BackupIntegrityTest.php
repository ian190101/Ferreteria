<?php

declare(strict_types=1);

use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Services\MaintenanceBackupService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperationalScenario;

it('restaura realmente el respaldo completo en una base temporal y compara datos e integridad', function () {
    Storage::fake('local');
    $scenario = OperationalScenario::create();
    $scenario['supplier']->update(['name' => "Proveedor ' Ñ\n; -- \\ prueba"]);
    $before = OperationalScenario::snapshot();
    $service = app(MaintenanceBackupService::class);
    $backup = $service->generate('sql', $scenario['user']);

    expect($service->verifyReadable($backup)['ok'])->toBeTrue()
        ->and($backup->metadata['manifest']['purchases']['rows'])->toBe(2)
        ->and($backup->metadata['manifest']['purchase_payments']['rows'])->toBe(2);
    $result = $service->verifyRecovery($backup);

    expect($result['ok'])->toBeTrue()
        ->and($backup->fresh()->metadata['recovery_verification']['ok'])->toBeTrue()
        ->and(OperationalScenario::snapshot())->toBe($before)
        ->and(DB::connection('backup_verification')->getSchemaBuilder()->getTables())->toBe([])
        ->and((int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled)->toBe(1);
    DB::purge('backup_verification');
});

it('detiene la generacion si falla la escritura y no registra un respaldo inexistente', function () {
    $scenario = OperationalScenario::create();
    $before = OperationalScenario::snapshot();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    expect(fn () => app(MaintenanceBackupService::class)->generate('sql', $scenario['user']))->toThrow(RuntimeException::class);
    expect(MaintenanceBackup::query()->count())->toBe(0)
        ->and(OperationalScenario::snapshot())->toBe($before);
});

it('detecta archivos alterados y rechaza la restauracion antes de modificar datos', function () {
    Storage::fake('local');
    $scenario = OperationalScenario::create();
    $service = app(MaintenanceBackupService::class);
    $backup = $service->generate('sql', $scenario['user']);
    Storage::disk('local')->append($backup->path, '-- alteración posterior');
    $before = OperationalScenario::snapshot();

    expect($service->verifyReadable($backup)['ok'])->toBeFalse()
        ->and(fn () => $service->verifyRecovery($backup))->toThrow(ValidationException::class)
        ->and(OperationalScenario::snapshot())->toBe($before);
});

it('genera nombres unicos aunque los respaldos se soliciten en el mismo segundo', function () {
    Storage::fake('local');
    $scenario = OperationalScenario::create();
    $this->travelTo(now()->startOfSecond());
    $service = app(MaintenanceBackupService::class);
    $first = $service->generate('json', $scenario['user']);
    $second = $service->generate('json', $scenario['user']);

    expect($first->path)->not->toBe($second->path)
        ->and($service->verifyReadable($first)['ok'])->toBeTrue()
        ->and($service->verifyReadable($second)['ok'])->toBeTrue();
});

it('incluye mas de cinco mil registros en el respaldo JSON sin truncar tablas', function () {
    Storage::fake('local');
    $scenario = OperationalScenario::create();
    for ($offset = 0; $offset < 5001; $offset += 500) {
        $rows = [];
        for ($index = $offset; $index < min(5001, $offset + 500); $index++) {
            $rows[] = ['name' => 'Producto masivo '.$index, 'sku' => 'QA-MASIVO-'.$index,
                'barcode' => 'QA-MASIVO-'.$index, 'inventory_tracking_mode' => 'global', 'is_active' => true];
        }
        DB::table('products')->insert($rows);
    }
    $backup = app(MaintenanceBackupService::class)->generate('json', $scenario['user']);
    $data = json_decode(Storage::disk('local')->get($backup->path), true, 512, JSON_THROW_ON_ERROR);

    expect(count($data['tables']['products']))->toBe(5003)
        ->and($backup->metadata['manifest']['products']['rows'])->toBe(5003);
});
