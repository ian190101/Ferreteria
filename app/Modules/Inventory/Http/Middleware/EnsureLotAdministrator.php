<?php

namespace App\Modules\Inventory\Http\Middleware;

use App\Modules\Inventory\Services\LotAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLotAdministrator
{
    public function __construct(private readonly LotAuthorization $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && $this->authorization->isAdministrator($request->user()), 403);

        return $next($request);
    }
}
