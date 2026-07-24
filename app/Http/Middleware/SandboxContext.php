<?php

namespace App\Http\Middleware;

use App\Modules\SystemSuperadmin\Models\BusinessProfileSandboxSession;
use App\Modules\SystemSuperadmin\Services\BusinessProfileSandboxService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SandboxContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $sandboxId = $request->session()->get('business_full_sandbox_id');

        if (! $sandboxId) {
            return $next($request);
        }

        $session = BusinessProfileSandboxSession::query()
            ->whereKey($sandboxId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $session) {
            $request->session()->forget('business_full_sandbox_id');

            return $next($request);
        }

        app(BusinessProfileSandboxService::class)->activateConnection($session);
        app()->instance('business_full_sandbox', $session);

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        return $request->is(
            'login',
            'logout',
            'register',
            'forgot-password',
            'reset-password/*',
            'system-superadmin/business-profiles/sandbox-full/*'
        );
    }
}
