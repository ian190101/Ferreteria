<?php

declare(strict_types=1);

use App\Jobs\RestoreMaintenanceBackupJob;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Services\RecoveryJournal;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\Support\MaintenanceScenario;

it('registra la restauracion sin mantener abierta la peticion HTTP', function () {
    Queue::fake();
    $root = storage_path('qa/recovery-http-'.bin2hex(random_bytes(8)));
    $journal = new RecoveryJournal($root);
    app()->instance(RecoveryJournal::class, $journal);
    $this->beforeApplicationDestroyed(function () use ($root): void {
        $resolved = realpath($root);
        if ($resolved && str_starts_with($resolved, realpath(storage_path('qa')).DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($resolved);
        }
    });
    $user = MaintenanceScenario::user();
    foreach (['settings.manage', 'production.rollback.manage'] as $name) {
        $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $backup = MaintenanceBackup::query()->create(['user_id' => $user->id, 'disk' => 'local', 'path' => 'backups/qa.sql',
        'status' => 'generated', 'size_bytes' => 1, 'metadata' => ['format_version' => 2, 'format' => 'sql']]);
    $this->actingAs($user)->post(route('settings.system.backups.restore', $backup), ['confirmation' => 'RESTAURAR'])
        ->assertRedirect(route('settings.system.index'))->assertSessionHas('success');
    $entry = $journal->recent()[0];
    expect($entry['status'])->toBe('pending')->and($entry['backup_id'])->toBe($backup->id);
    Queue::assertPushed(RestoreMaintenanceBackupJob::class, fn ($job) => $job->runId === $entry['id'] && $job->queue === 'maintenance');
    $this->getJson(route('settings.system.recovery-runs'))->assertOk()->assertJsonPath('runs.0.status', 'pending');
});
