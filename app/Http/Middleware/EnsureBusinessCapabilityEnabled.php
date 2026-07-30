<?php

namespace App\Http\Middleware;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\SystemRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessCapabilityEnabled
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if ($request->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN)) {
            return $next($request);
        }

        $capabilities = preg_split('/[|,]/', $capability) ?: [$capability];
        $enabled = collect($capabilities)
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->contains(fn (string $value) => ActiveBusinessProfile::capable($value));

        abort_unless($enabled, 404);

        return $next($request);
    }
}
