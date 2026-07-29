<?php

namespace App\Http\Middleware;

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
        if (! $request->session()->has('business_full_sandbox_id')) {
            return false;
        }

        if (! $request->is('system-superadmin/business-profiles*')) {
            return false;
        }

        return ! $request->is(
            'system-superadmin/business-profiles/sandbox-full/leave',
            'system-superadmin/business-profiles/sandbox-full/discard'
        );
    }
}
