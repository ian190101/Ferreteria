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

        return $next($request);
    }
}
