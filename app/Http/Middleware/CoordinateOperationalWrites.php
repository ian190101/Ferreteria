<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OperationalWriteGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CoordinateOperationalWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs('system-superadmin.database-purge.execute', 'settings.system.backups.restore', 'settings.system.updates.prepare', 'settings.system.updates.migrate')) {
            return $next($request);
        }

        $gate = app(OperationalWriteGate::class);
        $gate->begin();
        try {
            return $next($request);
        } finally {
            $gate->finish();
        }
    }
}
