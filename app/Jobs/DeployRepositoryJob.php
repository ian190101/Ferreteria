<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\SystemSuperadmin\Models\MaintenanceDeploymentRun;
use App\Modules\SystemSuperadmin\Services\GitVersioningService;
use App\Support\SystemErrorDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class DeployRepositoryJob implements ShouldQueue
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

    public function handle(GitVersioningService $service): void
    {
        if (MaintenanceDeploymentRun::query()->whereKey($this->runId)->where('status', 'pending')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $run = MaintenanceDeploymentRun::query()->findOrFail($this->runId);
        $plan = $run->result['request'] ?? null;
        $result = is_array($plan) ? $service->deployPlan($plan) : match ($run->operation) {
            'pull' => $service->pull(), 'reset' => $service->resetToRemote(), 'previous' => $service->revertPrevious(),
            default => ['successful' => false, 'message' => 'Operación de despliegue inválida.'],
        };
        $run->update(['status' => $result['successful'] ? 'completed' : 'failed', 'result' => $result]);
    }

    public function failed(?Throwable $exception): void
    {
        $details = $exception ? app(SystemErrorDetails::class)->describe($exception, 500) : null;
        MaintenanceDeploymentRun::query()->whereKey($this->runId)->whereIn('status', ['pending', 'running'])->update([
            'status' => 'failed', 'result' => json_encode(['successful' => false, 'message' => 'El trabajador se interrumpió. Revisa la recuperación y el estado del código y la base antes de reabrir el servicio.', 'error' => $details['technical_message'] ?? 'El proceso terminó sin comunicar una excepción.', 'system_error' => $details], JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }
}
