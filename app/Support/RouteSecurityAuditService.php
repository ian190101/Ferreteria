<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;

class RouteSecurityAuditService
{
    private ?Collection $routes = null;

    /**
     * Prefijos operativos que deben respetar perfil/capacidad empresarial.
     *
     * @var array<string, string>
     */
    private array $profileGuardedPrefixes = [
        'alerts' => 'alerts',
        'banks' => 'banks',
        'billing' => 'billing',
        'cash' => 'cash',
        'customers' => 'customers',
        'expenses' => 'expenses',
        'exports' => 'exports',
        'human-resources' => 'human-resources',
        'inventory' => 'inventory',
        'payments' => 'payments',
        'pos' => 'pos',
        'printing' => 'printing',
        'production' => 'production',
        'purchases' => 'purchases',
        'reports' => 'reports',
        'reservations' => 'reservations',
        'restaurant' => 'restaurant',
        'sales' => 'sales',
        'service-orders' => 'service-orders',
        'dashboard' => 'dashboard',
    ];

    /**
     * Prefijos de administracion protegidos por permisos/rol, no por perfil de negocio.
     *
     * @var array<int, string>
     */
    private array $administrativePrefixes = [
        'audit',
        'branches',
        'settings',
        'system-superadmin',
        'users',
    ];

    public function routesMissingProfileGuard(): array
    {
        return $this->applicationRoutes()
            ->filter(fn (Route $route) => $this->requiresProfileGuard($route))
            ->filter(fn (Route $route) => ! $this->hasAnyMiddleware($route, ['business_feature', 'business_capability', 'system_superadmin']))
            ->map(fn (Route $route) => $this->routeSignature($route))
            ->values()
            ->all();
    }

    public function routesMissingAccessGuard(): array
    {
        return $this->applicationRoutes()
            ->filter(fn (Route $route) => $this->requiresAccessGuard($route))
            ->filter(fn (Route $route) => ! $this->hasAnyMiddleware($route, ['permission', 'role', 'role_or_permission', 'system_superadmin']))
            ->map(fn (Route $route) => $this->routeSignature($route))
            ->values()
            ->all();
    }

    private function applicationRoutes(): Collection
    {
        return $this->routes ??= collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route) => ! str_starts_with($route->uri(), '_'))
            ->values();
    }

    private function requiresProfileGuard(Route $route): bool
    {
        return array_key_exists($this->firstSegment($route), $this->profileGuardedPrefixes);
    }

    private function requiresAccessGuard(Route $route): bool
    {
        $segment = $this->firstSegment($route);

        return array_key_exists($segment, $this->profileGuardedPrefixes)
            || in_array($segment, $this->administrativePrefixes, true);
    }

    private function hasAnyMiddleware(Route $route, array $needles): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(function (string $middleware) use ($needles) {
                return collect($needles)
                    ->contains(fn (string $needle) => str_starts_with($middleware, $needle));
            });
    }

    private function firstSegment(Route $route): string
    {
        return explode('/', trim($route->uri(), '/'))[0] ?? '';
    }

    private function routeSignature(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri().' ['.($route->getName() ?? 'sin_nombre').']';
    }
}
