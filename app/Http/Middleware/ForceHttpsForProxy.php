<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ForceHttpsForProxy
{
    /**
     * Cloudflare termina HTTPS y reenvia al servidor local por HTTP.
     * Normalizamos el request antes de que Laravel genere rutas o redirecciones.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldForceHttps($request)) {
            $rootUrl = 'https://'.$request->getHost();

            URL::forceRootUrl($rootUrl);
            URL::forceScheme('https');

            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Port', '443');
        }

        return $next($request);
    }

    private function shouldForceHttps(Request $request): bool
    {
        $host = $request->getHost();
        $forwardedProto = strtolower((string) $request->headers->get('X-Forwarded-Proto'));
        $visitor = strtolower((string) $request->headers->get('CF-Visitor'));

        return $request->isSecure()
            || $forwardedProto === 'https'
            || str_contains($visitor, '"scheme":"https"')
            || str_ends_with($host, '.trycloudflare.com');
    }
}
