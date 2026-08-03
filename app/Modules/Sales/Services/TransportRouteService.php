<?php

namespace App\Modules\Sales\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Sales\Models\TransportRouteCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TransportRouteService
{
    public function geocode(string $query, string $throttleKey): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            throw ValidationException::withMessages([
                'query' => 'Ingresa al menos 3 caracteres para buscar una direccion.',
            ]);
        }

        $cacheKey = 'transport:geocode:'.md5(mb_strtolower($query).'|'.$this->countryCodes());
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $this->ensureThrottle("transport-geocode:{$throttleKey}");

        $response = Http::acceptJson()
            ->withHeaders($this->headers())
            ->timeout(8)
            ->retry(1, 250)
            ->get(rtrim($this->nominatimUrl(), '/').'/search', [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => 5,
                'countrycodes' => $this->countryCodes(),
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'query' => 'No se pudo consultar Nominatim en este momento.',
            ]);
        }

        $results = collect($response->json())
            ->map(fn (array $place) => [
                'label' => (string) ($place['display_name'] ?? ''),
                'latitude' => round((float) ($place['lat'] ?? 0), 7),
                'longitude' => round((float) ($place['lon'] ?? 0), 7),
                'type' => $place['type'] ?? null,
                'importance' => isset($place['importance']) ? round((float) $place['importance'], 4) : null,
            ])
            ->filter(fn (array $place) => filled($place['label']) && $this->validCoordinates($place['latitude'], $place['longitude']))
            ->values()
            ->all();

        Cache::put($cacheKey, $results, now()->addDays($this->geocodeCacheDays()));

        return $results;
    }

    public function routeForBranch(Branch $branch, float $destinationLatitude, float $destinationLongitude, string $throttleKey): array
    {
        if (! $this->validCoordinates((float) $branch->latitude, (float) $branch->longitude)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Configura latitud y longitud de la sucursal antes de calcular transporte.',
            ]);
        }

        return $this->route(
            (float) $branch->latitude,
            (float) $branch->longitude,
            $destinationLatitude,
            $destinationLongitude,
            $throttleKey,
            $branch->address,
        );
    }

    public function route(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude, string $throttleKey, ?string $originAddress = null): array
    {
        if (! $this->validCoordinates($originLatitude, $originLongitude) || ! $this->validCoordinates($destinationLatitude, $destinationLongitude)) {
            throw ValidationException::withMessages([
                'coordinates' => 'Las coordenadas de origen y destino deben ser validas.',
            ]);
        }

        $cacheKey = $this->routeCacheKey($originLatitude, $originLongitude, $destinationLatitude, $destinationLongitude);
        $cached = TransportRouteCache::query()
            ->where('cache_key', $cacheKey)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($cached) {
            return $this->formatRoute($cached, true, $originAddress);
        }

        $this->ensureThrottle("transport-route:{$throttleKey}");

        $url = sprintf(
            '%s/route/v1/driving/%F,%F;%F,%F',
            rtrim($this->osrmUrl(), '/'),
            $originLongitude,
            $originLatitude,
            $destinationLongitude,
            $destinationLatitude,
        );

        $response = Http::acceptJson()
            ->withHeaders($this->headers())
            ->timeout(10)
            ->retry(1, 300)
            ->get($url, [
                'overview' => 'full',
                'geometries' => 'geojson',
                'steps' => 'false',
                'alternatives' => 'false',
            ]);

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            throw ValidationException::withMessages([
                'route' => 'No se pudo calcular una ruta por carretera para esas coordenadas.',
            ]);
        }

        $route = $response->json('routes.0');

        if (! is_array($route) || ! isset($route['distance'], $route['duration'])) {
            throw ValidationException::withMessages([
                'route' => 'El proveedor de rutas no devolvio distancia y duracion validas.',
            ]);
        }

        $cached = TransportRouteCache::query()->updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'origin_latitude' => round($originLatitude, 7),
                'origin_longitude' => round($originLongitude, 7),
                'destination_latitude' => round($destinationLatitude, 7),
                'destination_longitude' => round($destinationLongitude, 7),
                'distance_meters' => (int) round((float) $route['distance']),
                'duration_seconds' => (int) round((float) $route['duration']),
                'provider' => 'osrm',
                'geometry' => $route['geometry'] ?? null,
                'expires_at' => now()->addDays($this->routeCacheDays()),
            ],
        );

        return $this->formatRoute($cached, false, $originAddress);
    }

    public function routeCacheKey(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): string
    {
        return 'osrm:driving:'.implode(':', [
            number_format($originLatitude, 5, '.', ''),
            number_format($originLongitude, 5, '.', ''),
            number_format($destinationLatitude, 5, '.', ''),
            number_format($destinationLongitude, 5, '.', ''),
        ]);
    }

    private function formatRoute(TransportRouteCache $route, bool $cached, ?string $originAddress = null): array
    {
        return [
            'cache_key' => $route->cache_key,
            'cached' => $cached,
            'provider' => $route->provider,
            'origin' => [
                'address' => $originAddress,
                'latitude' => (float) $route->origin_latitude,
                'longitude' => (float) $route->origin_longitude,
            ],
            'destination' => [
                'latitude' => (float) $route->destination_latitude,
                'longitude' => (float) $route->destination_longitude,
            ],
            'distance_meters' => $route->distance_meters,
            'distance_km' => round($route->distance_meters / 1000, 2),
            'duration_seconds' => $route->duration_seconds,
            'duration_minutes' => (int) ceil($route->duration_seconds / 60),
            'geometry' => $route->geometry,
        ];
    }

    private function ensureThrottle(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Espera un momento antes de volver a consultar el mapa.',
            ]);
        }

        RateLimiter::hit($key, 1);
    }

    private function validCoordinates(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180
            && ($latitude !== 0.0 || $longitude !== 0.0);
    }

    private function headers(): array
    {
        return [
            'User-Agent' => (string) config('services.openstreetmap.user_agent'),
        ];
    }

    private function nominatimUrl(): string
    {
        return (string) config('services.openstreetmap.nominatim_url', 'https://nominatim.openstreetmap.org');
    }

    private function osrmUrl(): string
    {
        return (string) config('services.openstreetmap.osrm_url', 'https://router.project-osrm.org');
    }

    private function countryCodes(): string
    {
        return (string) config('services.openstreetmap.countrycodes', 'bo');
    }

    private function routeCacheDays(): int
    {
        return max((int) config('services.openstreetmap.route_cache_days', 30), 1);
    }

    private function geocodeCacheDays(): int
    {
        return max((int) config('services.openstreetmap.geocode_cache_days', 7), 1);
    }
}
