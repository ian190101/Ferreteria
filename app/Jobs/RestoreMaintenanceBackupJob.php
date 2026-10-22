<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Services\ProductionUpdateService;
use App\Modules\Settings\Services\RecoveryJournal;
use App\Support\SystemRoles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class RestoreMaintenanceBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId)
    {
        $this->onConnection(config('maintenance.queue_connection', 'maintenance'));
        $this->onQueue('maintenance');
    }

    public function handle(ProductionUpdateService $updates, RecoveryJournal $journal): void
    {
        if (! $journal->claim($this->runId)) {
            return;
        }
        $entry = $journal->read($this->runId);
        try {
            $actor = User::query()->find($entry['user_id']);
            if ($entry['source_database'] !== DB::connection()->getDatabaseName() || ! $actor
                || (! $actor->hasRole(SystemRoles::SYSTEM_SUPERADMIN) && ! $actor->can('production.rollback.manage'))) {
                throw new RuntimeException('El contexto de la restauración ya no es válido.');
            }
            $backup = MaintenanceBackup::query()->findOrFail($entry['backup_id']);
            $result = $updates->rollbackToBackup($backup, fn (string $stage) => $journal->update($this->runId, ['stage' => $stage]));
            $journal->update($this->runId, ['status' => 'completed', 'stage' => 'finished', 'result' => $result, 'message' => $result['message']]);
            $journal->cancelOtherPending($this->runId, $entry['source_database']);
        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $journal = app(RecoveryJournal::class);
        $entry = $journal->read($this->runId);
        if ($entry['status'] === 'completed') {
            return;
        }
        $changed = in_array($entry['stage'], ['restoring', 'verifying_source', 'finalizing'], true);
        $journal->update($this->runId, ['status' => 'failed', 'message' => $changed
            ? 'La restauración se interrumpió después de iniciar la sustitución de datos. Mantén el servicio en mantenimiento y revisa la recuperación; no se afirma una reversión automática.'
            : 'La restauración no superó sus verificaciones previas. No se inició la sustitución de datos.']);
        if ($changed && ! app()->maintenanceMode()->active()) {
            app()->maintenanceMode()->activate(['time' => time(), 'retry' => 60, 'status' => 503]);
        }
    }
}
