<?php

namespace App\Http\Middleware;

use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use App\Support\SystemRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSystemSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN), 403);

        if ($this->isBlockedInsideFullSandbox($request)) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'El configurador maestro no esta disponible dentro de la demo completa. Sal de la demo para cambiar la configuracion del negocio.');
        }

        return $next($request);
    }

    private function isBlockedInsideFullSandbox(Request $request): bool
    {
        $sandboxId = $request->session()->get('business_full_sandbox_id');

        if (! $sandboxId) {
            return false;
        }

        $session = app(BusinessProfileSandboxService::class)
            ->activeSessionById($sandboxId, (int) $request->user()->id);

        if (! $session) {
            $request->session()->forget('business_full_sandbox_id');

            return false;
        }

        if ($request->is(
            'system-superadmin/business-profiles/sandbox-full/leave',
            'system-superadmin/business-profiles/sandbox-full/discard'
        )) {
            return false;
        }

        return $request->is('system-superadmin/business-profiles*')
            || $request->is('system-superadmin/transversal-config*');
    }
}
