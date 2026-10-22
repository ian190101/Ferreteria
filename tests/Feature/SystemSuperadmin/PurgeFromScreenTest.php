<?php

declare(strict_types=1);

use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\SystemSuperadmin\Models\MaintenancePurgeRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\MaintenanceScenario;

it('mantiene la ejecucion en cola cuando el modo directo esta desactivado', function () {
    config(['maintenance.purge_inline' => false, 'maintenance.queue_connection' => 'maintenance']);
    Queue::fake();
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales']);

    $this->actingAs($user)->post(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertRedirect()->assertSessionHas('warning')->assertSessionMissing('success');

    Queue::assertPushedOn('maintenance', App\Jobs\PurgeDatabaseJob::class);
    expect(MaintenancePurgeRun::query()->sole()->status)->toBe('pending')
        ->and(DB::table('sales')->where('id', $sale->id)->exists())->toBeTrue();
});

it('confirma desde la pantalla y elimina sin un trabajador en modo directo', function () {
    config(['maintenance.purge_inline' => true, 'maintenance.queue_connection' => 'maintenance']);
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales']);

    $this->actingAs($user)->post(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertRedirect(route('system-superadmin.database-purge.index'))->assertSessionHas('success');

    $run = MaintenancePurgeRun::query()->sole();
    expect($run->status)->toBe('completed')
        ->and($run->result['total_deleted'])->toBe(1)
        ->and(DB::table('sales')->where('id', $sale->id)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'maintenance')->count())->toBe(0);
    $this->assertDatabaseHas('maintenance_logs', ['event' => 'database_purged']);
});

it('crea y ensaya el respaldo al purgar desde la pantalla en modo directo', function () {
    Storage::fake('local');
    config(['maintenance.purge_inline' => true, 'maintenance.queue_connection' => 'maintenance']);
    $user = MaintenanceScenario::user();
    MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales'], ['create_backup' => true]);

    $this->actingAs($user)->post(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertRedirect()->assertSessionHas('success');

    $run = MaintenancePurgeRun::query()->sole();
    $backup = App\Modules\Settings\Models\MaintenanceBackup::query()->findOrFail($run->result['backup_id']);
    expect($run->status)->toBe('completed')
        ->and($backup->metadata['recovery_verification']['ok'])->toBeTrue()
        ->and(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'maintenance')->count())->toBe(0);
});

it('conserva los datos y muestra el motivo cuando falla el respaldo desde la pantalla', function () {
    config(['maintenance.purge_inline' => true, 'maintenance.queue_connection' => 'maintenance']);
    $user = MaintenanceScenario::user();
    $sale = MaintenanceScenario::sale($user);
    $options = MaintenanceScenario::purgeOptions($user, ['sales'], ['create_backup' => true]);
    $backup = Mockery::mock(MaintenanceBackupService::class);
    $backup->shouldReceive('generate')->once()->andThrow(ValidationException::withMessages(['backup' => 'No se pudo verificar el respaldo de prueba.']));
    app()->instance(MaintenanceBackupService::class, $backup);

    $this->actingAs($user)->post(route('system-superadmin.database-purge.execute'), [...$options, 'confirmation' => 'BORRAR'])
        ->assertSessionHasErrors('backup');

    $run = MaintenancePurgeRun::query()->sole();
    expect($run->status)->toBe('failed')
        ->and($run->message)->toContain('No se pudo verificar el respaldo de prueba.')
        ->and(DB::table('sales')->where('id', $sale->id)->exists())->toBeTrue();
});
