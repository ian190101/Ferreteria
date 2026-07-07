<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordWasChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user?->force_password_change
            && ! $request->routeIs('password.force.*')
            && ! $request->routeIs('logout')
        ) {
            return redirect()->route('password.force.edit');
        }

        return $next($request);
    }
}
