<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeDatabaseJob;
use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\SystemSuperadmin\Http\Requests\ExecuteDatabasePurgeRequest;
use App\Modules\SystemSuperadmin\Http\Requests\PreviewDatabasePurgeRequest;
use App\Modules\SystemSuperadmin\Models\MaintenancePurgeRun;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DatabasePurgeController extends Controller
{
    public function index(DatabasePurgeService $purgeService): Response
    {
        return Inertia::render('SystemSuperadmin/DatabasePurge/Index', [
            'stats' => $purgeService->getStats(),
            'modulesCatalog' => $purgeService->modulesCatalog(),
            'protectedTables' => $purgeService->protectedSystemTables(),
            'recentRuns' => $this->recentRuns(),
        ]);
    }

    public function purge(ExecuteDatabasePurgeRequest $request, DatabasePurgeService $purgeService): RedirectResponse
    {
        $purgeService->validatePreview($request->validated(), $request->user());
        $run = MaintenancePurgeRun::query()->create(['user_id' => $request->user()->id, 'options' => $request->validated(), 'status' => 'pending', 'stage' => 'queued']);
        try {
            if (config('maintenance.purge_inline')) {
                // El entorno local puede operar sin trabajador usando el mismo trabajo y sus validaciones.
                set_time_limit(1800);
                PurgeDatabaseJob::dispatchSync($run->id);
            } else {
                PurgeDatabaseJob::dispatch($run->id);
            }
        } catch (\Throwable $exception) {
            if ($run->fresh()->status === 'pending') {
                $run->update(['status' => 'failed', 'stage' => 'dispatch_failed', 'message' => 'No se pudo enviar la solicitud al trabajador. Calcula otra vista previa antes de intentar nuevamente.']);
            }
            throw $exception;
        }
        $run->refresh();
        $result = $run->result ?? [];

        return redirect()
            ->route('system-superadmin.database-purge.index')
            ->with($run->status === 'completed' && ($result['identifier_reset']['ok'] ?? true) ? 'success' : 'warning',
                $run->status === 'completed' ? $run->message : 'Solicitud de purga registrada. Consulta su progreso; todavía no se ha informado un borrado completado.');
    }

    public function preview(PreviewDatabasePurgeRequest $request, DatabasePurgeService $purgeService): JsonResponse
    {
        return response()->json($purgeService->preview($request->validated(), $request->user()));
    }

    public function runs(): JsonResponse
    {
        return response()->json(['runs' => $this->recentRuns()]);
    }

    private function recentRuns(): Collection
    {
        return MaintenancePurgeRun::query()->latest()->limit(10)->get(['id', 'status', 'stage', 'message', 'result', 'created_at', 'updated_at']);
    }

    public function backup(Request $request, MaintenanceBackupService $backupService): RedirectResponse
    {
        $backup = $backupService->generate('sql', $request->user(), [
            'reason' => 'manual_purge_screen',
        ]);

        return redirect()
            ->route('system-superadmin.database-purge.index')
            ->with('success', "Copia de seguridad SQL #{$backup->id} generada correctamente.");
    }
}
