<?php

declare(strict_types=1);

use App\Jobs\DeployRepositoryJob;
use App\Modules\Settings\Services\ProductionUpdateService;
use App\Modules\SystemSuperadmin\Models\MaintenanceDeploymentRun;
use App\Modules\SystemSuperadmin\Services\GitVersioningService;
use App\Support\OperationalWriteGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaintenanceScenario;

it('registra la actualizacion sin ejecutar Git en la peticion y conserva el fallo del trabajador', function () {
    Queue::fake();
    $user = MaintenanceScenario::user();
    $service = Mockery::mock(GitVersioningService::class);
    $service->shouldReceive('getRepositoryStatus')->once()->andReturn(['is_available' => true, 'updates_enabled' => true]);
    $service->shouldReceive('resetToRemote')->once()->andReturn(['successful' => false, 'message' => 'Descarga fallida sin cambios locales.']);
    app()->instance(GitVersioningService::class, $service);
    $this->actingAs($user)->from(route('system-superadmin.git-versioning.index'))
        ->post(route('system-superadmin.git-versioning.reset'))->assertRedirect()->assertSessionHas('success');
    $run = MaintenanceDeploymentRun::query()->sole();
    expect($run->status)->toBe('pending')->and($run->result)->toBeNull();
    Queue::assertPushed(DeployRepositoryJob::class, fn ($job) => $job->runId === $run->id && $job->queue === 'maintenance');
    (new DeployRepositoryJob($run->id))->handle($service);
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->result['successful'])->toBeFalse();
    (new DeployRepositoryJob($run->id))->handle($service);
    expect($run->fresh()->status)->toBe('failed');
});

it('bloquea la actualizacion si la instalacion no tiene el pipeline configurado', function () {
    Queue::fake();
    $service = Mockery::mock(GitVersioningService::class);
    $service->shouldReceive('getRepositoryStatus')->once()->andReturn(['is_available' => false]);
    app()->instance(GitVersioningService::class, $service);
    $this->actingAs(MaintenanceScenario::user())->post(route('system-superadmin.git-versioning.pull'))->assertStatus(422);
    Queue::assertNothingPushed();
    expect(MaintenanceDeploymentRun::query()->count())->toBe(0);
});

it('rechaza el codigo de salida fallido de migraciones sin registrar exito', function () {
    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(1);
    expect(fn () => app(ProductionUpdateService::class)->applyMigrations())->toThrow(RuntimeException::class, 'migraciones fallaron');
    $this->assertDatabaseMissing('maintenance_logs', ['event' => 'migrations_applied']);
});

it('el verificador de publicacion rechaza una revision diferente', function () {
    config(['deployment.commit' => 'publicada']);
    $this->artisan('deployment:verify', ['--expected-commit' => 'otra'])->assertExitCode(1);
});

it('permite preparar el respaldo dentro del mantenimiento exclusivo y libera la barrera tras un fallo', function () {
    $gate = app(OperationalWriteGate::class);
    expect($gate->exclusive(fn () => $gate->exclusive(fn () => 'preparado')))->toBe('preparado');
    expect(fn () => $gate->exclusive(fn () => $gate->exclusive(fn () => throw new RuntimeException('Fallo de ensayo'))))
        ->toThrow(RuntimeException::class, 'Fallo de ensayo');
    expect($gate->exclusive(fn () => 'liberado'))->toBe('liberado');
});

it('publica realmente el trabajo despues de la peticion sin perderlo al liberar la barrera compartida', function () {
    config(['maintenance.queue_connection' => 'maintenance']);
    $service = Mockery::mock(GitVersioningService::class);
    $service->shouldReceive('getRepositoryStatus')->once()->andReturn(['is_available' => true, 'updates_enabled' => true]);
    app()->instance(GitVersioningService::class, $service);
    $this->actingAs(MaintenanceScenario::user())->post(route('system-superadmin.git-versioning.reset'))->assertRedirect();
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'maintenance']);
});
