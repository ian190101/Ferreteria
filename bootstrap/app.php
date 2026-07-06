<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ForceHttpsForProxy;
use App\Support\UserHomeRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../app/Modules/Alerts/routes/web.php',
            __DIR__.'/../app/Modules/Banks/routes/web.php',
            __DIR__.'/../app/Modules/Branches/routes/web.php',
            __DIR__.'/../app/Modules/Cash/routes/web.php',
            __DIR__.'/../app/Modules/Customers/routes/web.php',
            __DIR__.'/../app/Modules/Expenses/routes/web.php',
            __DIR__.'/../app/Modules/Exports/routes/web.php',
            __DIR__.'/../app/Modules/Inventory/routes/web.php',
            __DIR__.'/../app/Modules/Payments/routes/web.php',
            __DIR__.'/../app/Modules/Production/routes/web.php',
            __DIR__.'/../app/Modules/Purchases/routes/web.php',
            __DIR__.'/../app/Modules/Sales/routes/web.php',
            __DIR__.'/../app/Modules/Settings/routes/web.php',
            __DIR__.'/../app/Modules/Users/routes/web.php',
            __DIR__.'/../app/Modules/Audit/routes/web.php',
            __DIR__.'/../app/Modules/Reports/routes/web.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : null,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        $middleware->web(prepend: [
            ForceHttpsForProxy::class,
        ]);

        $middleware->redirectUsersTo(fn (Request $request) => UserHomeRoute::pathFor($request->user()));

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
