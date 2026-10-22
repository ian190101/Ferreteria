<?php

namespace App\Modules\SystemSuperadmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\DeployRepositoryJob;
use App\Modules\SystemSuperadmin\Http\Requests\GitCommitRequest;
use App\Modules\SystemSuperadmin\Models\MaintenanceDeploymentRun;
use App\Modules\SystemSuperadmin\Services\GitVersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GitVersioningController extends Controller
{
    public function index(GitVersioningService $service): Response
    {
        return Inertia::render('SystemSuperadmin/GitVersioning/Index', [
            'gitStatus' => $service->getRepositoryStatus(),
            'deploymentRuns' => MaintenanceDeploymentRun::query()->latest()->limit(10)->get(),
        ]);
    }

    public function status(GitVersioningService $service): JsonResponse
    {
        return response()->json([...$service->getRepositoryStatus(), 'deployment_runs' => MaintenanceDeploymentRun::query()->latest()->limit(10)->get()]);
    }

    public function fetch(Request $request, GitVersioningService $service): JsonResponse|RedirectResponse
    {
        $result = $service->fetchRemote();

        return $this->operationResponse($request, $result);
    }

    public function commit(GitCommitRequest $request, GitVersioningService $service): JsonResponse|RedirectResponse
    {
        $result = $service->commit(
            $request->validated('message'),
            $request->boolean('push'),
            $request->validated('files')
        );

        return $this->operationResponse($request, $result);
    }

    public function inspect(Request $request, GitVersioningService $service): JsonResponse
    {
        $data = $request->validate(['ref' => ['required', 'string', 'max:250'], 'offset' => ['sometimes', 'integer', 'min:0', 'max:100000']]);

        try {
            return response()->json($service->inspect($data['ref'], (int) ($data['offset'] ?? 0)));
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['ref' => $exception->getMessage()]);
        }
    }

    public function push(Request $request, GitVersioningService $service): JsonResponse|RedirectResponse
    {
        return $this->operationResponse($request, $service->push());
    }

    public function preview(Request $request, GitVersioningService $service): JsonResponse
    {
        $data = $request->validate(['operation' => ['required', 'in:pull,reset,previous,revision,branch'], 'ref' => ['nullable', 'string', 'max:250']]);
        try {
            $plan = $service->previewDeployment($data['operation'], $data['ref'] ?? '');
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['ref' => $exception->getMessage()]);
        }
        $token = Crypt::encryptString(json_encode(['user_id' => (string) $request->user()->id, 'plan' => $plan, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));

        return response()->json(['plan' => $plan, 'token' => $token]);
    }

    public function deploy(Request $request, GitVersioningService $service): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:30000']]);
        try {
            $payload = json_decode(Crypt::decryptString($data['token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['token' => 'La vista previa no es auténtica. Genera otra.']);
        }
        abort_unless(($payload['user_id'] ?? null) === (string) $request->user()->id && ($payload['plan']['expires_at'] ?? 0) >= now()->timestamp, 422, 'La vista previa venció o pertenece a otro usuario.');
        $status = $service->getRepositoryStatus();
        abort_unless(($status['updates_enabled'] ?? false), 422, 'Configura el reinicio supervisado y habilita el despliegue antes de actualizar.');
        $key = 'git-deployment-token:'.hash('sha256', $data['token']);
        abort_unless(Cache::add($key, true, 600), 409, 'Esta solicitud ya fue enviada. Consulta su ejecución.');
        $run = MaintenanceDeploymentRun::query()->create(['user_id' => $request->user()->id, 'operation' => $payload['plan']['operation'], 'status' => 'pending', 'result' => ['request' => $payload['plan']]]);
        try {
            DeployRepositoryJob::dispatch($run->id);
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'result' => ['message' => 'No se pudo iniciar el trabajador de mantenimiento.']]);
            throw $exception;
        }

        return response()->json(['message' => 'Solicitud registrada. El resultado aparecerá en el historial de despliegues.', 'run_id' => $run->id], 202);
    }

    private function operationResponse(Request $request, array $result): JsonResponse|RedirectResponse
    {
        if (! $result['successful']) {
            // El manejador global presenta el diagnóstico técnico y conserva el estado HTTP.
            throw ValidationException::withMessages(['git' => $result['message'].' '.($result['error'] ?? '')]);
        }

        return $request->expectsJson() ? response()->json($result) : back()->with('success', $result['message']);
    }

    public function pull(Request $request, GitVersioningService $service): RedirectResponse
    {
        return $this->queueUpdate('pull', $service);
    }

    public function resetToRemote(GitVersioningService $service): RedirectResponse
    {
        return $this->queueUpdate('reset', $service);
    }

    public function revertPrevious(GitVersioningService $service): RedirectResponse
    {
        return $this->queueUpdate('previous', $service);
    }

    private function queueUpdate(string $operation, GitVersioningService $service): RedirectResponse
    {
        $status = $service->getRepositoryStatus();
        abort_unless(($status['is_available'] ?? false) && ($status['updates_enabled'] ?? false), 422, 'Configura el flujo completo de despliegue antes de actualizar.');
        $run = MaintenanceDeploymentRun::query()->create(['user_id' => auth()->id(), 'operation' => $operation, 'status' => 'pending']);
        try {
            DeployRepositoryJob::dispatch($run->id);
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'result' => ['successful' => false, 'message' => 'No se pudo iniciar la actualización.']]);
            throw $exception;
        }

        return back()->with('success', 'Solicitud de actualización registrada. Consulta su resultado; todavía no se ha informado una actualización completada.');
    }
}
