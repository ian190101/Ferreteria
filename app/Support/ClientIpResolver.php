<?php

namespace App\Support;

use Illuminate\Http\Request;

class ClientIpResolver
{
    /**
     * Obtiene la IP real del cliente incluso cuando la app esta detras de
     * Cloudflare, Render, XAMPP con tunel u otros proxies reversos.
     */
    public function resolve(?Request $request = null): string
    {
        $request ??= request();

        $candidates = [
            ...$this->headerIps($request, 'CF-Connecting-IP'),
            ...$this->headerIps($request, 'True-Client-IP'),
            ...$this->headerIps($request, 'X-Forwarded-For'),
            ...$this->forwardedHeaderIps($request),
            ...$this->headerIps($request, 'X-Real-IP'),
            ...$this->serverIps($request),
            $request->ip(),
        ];

        return $this->bestIp($candidates) ?? '127.0.0.1';
    }

    /**
     * @return array<int, string>
     */
    private function headerIps(Request $request, string $header): array
    {
        $value = (string) $request->headers->get($header, '');

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part) => $this->normalizeIp($part),
            explode(',', $value),
        )));
    }

    /**
     * @return array<int, string>
     */
    private function forwardedHeaderIps(Request $request): array
    {
        $value = (string) $request->headers->get('Forwarded', '');

        if ($value === '') {
            return [];
        }

        preg_match_all('/for=(?:"?)([^;,"]+)/i', $value, $matches);

        return array_values(array_filter(array_map(
            fn (string $part) => $this->normalizeIp($part),
            $matches[1] ?? [],
        )));
    }

    /**
     * @return array<int, string>
     */
    private function serverIps(Request $request): array
    {
        return array_values(array_filter([
            $this->normalizeIp((string) $request->server('HTTP_CF_CONNECTING_IP', '')),
            $this->normalizeIp((string) $request->server('HTTP_X_REAL_IP', '')),
            $this->normalizeIp((string) $request->server('REMOTE_ADDR', '')),
        ]));
    }

    /**
     * @param  array<int, string|null>  $candidates
     */
    private function bestIp(array $candidates): ?string
    {
        $valid = array_values(array_unique(array_filter($candidates, fn (?string $ip) => $this->isValid($ip))));

        if ($valid === []) {
            return null;
        }

        return collect($valid)->first(fn (string $ip) => $this->isPublic($ip)) ?? $valid[0];
    }

    private function normalizeIp(?string $value): ?string
    {
        $ip = trim((string) $value, " \t\n\r\0\x0B\"'");

        if ($ip === '' || strtolower($ip) === 'unknown' || strtolower($ip) === 'local') {
            return null;
        }

        if (str_starts_with($ip, '[') && str_contains($ip, ']')) {
            $ip = substr($ip, 1, strpos($ip, ']') - 1);
        }

        if (substr_count($ip, ':') === 1 && preg_match('/^(.+):\d+$/', $ip, $matches)) {
            $ip = $matches[1];
        }

        return $this->isValid($ip) ? $ip : null;
    }

    private function isValid(?string $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
