<?php

namespace App\Http\Middleware;

use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\SystemRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($request->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN)) {
            return $next($request);
        }

        $features = preg_split('/[|,]/', $feature) ?: [$feature];
        $enabled = collect($features)
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->contains(fn (string $value) => ActiveBusinessProfile::enabled($value));

        abort_unless($enabled, 404);

        return $next($request);
    }
}
