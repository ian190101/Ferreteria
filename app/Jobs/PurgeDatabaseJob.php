<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\MaintenancePurgeRun;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PurgeDatabaseJob implements ShouldQueue
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

    public function handle(DatabasePurgeService $service): void
    {
        // La reclamación atómica evita ejecutar de nuevo una entrega duplicada.
        if (MaintenancePurgeRun::query()->whereKey($this->runId)->where('status', 'pending')
            ->update(['status' => 'running', 'stage' => 'validating', 'updated_at' => now()]) !== 1) {
            return;
        }
        $run = MaintenancePurgeRun::query()->findOrFail($this->runId);
        try {
            $result = $service->executePurge($run->options, User::query()->findOrFail($run->user_id), function (string $stage, ?array $result = null) use ($run): void {
                $run->update(['stage' => $stage, 'status' => $stage === 'committed' ? 'committed' : 'running', ...($result !== null ? ['result' => $result] : [])]);
            });
            $run->update(['status' => 'completed', 'stage' => 'finished', 'result' => $result, 'message' => $result['message']]);
        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = MaintenancePurgeRun::query()->find($this->runId);
        if (! $run || $run->status === 'completed') {
            return;
        }
        $committed = $run->status === 'committed';
        $failureMessage = $exception instanceof ValidationException
            ? 'La purga no se completó: '.collect($exception->errors())->flatten()->implode(' ')
            : 'La purga no se completó. Revisa el registro de mantenimiento y calcula una nueva vista previa.';
        $run->update(['status' => $committed ? 'completed' : 'failed', 'stage' => $committed ? 'post_commit_incomplete' : 'failed',
            'message' => $committed
                ? 'El borrado se confirmó, pero una etapa posterior no terminó. Revisa su resultado antes de repetir acciones.'
                : $failureMessage]);
    }
}
