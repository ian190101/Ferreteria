<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\MaintenanceLog;
use App\Support\ClientIpResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductionLogService
{
    public function record(string $channel, string $level, string $event, string $message, array $context = []): MaintenanceLog
    {
        $payload = [
            'user_id' => Auth::id(),
            'channel' => $channel,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $this->sanitizeContext($context),
            'ip_address' => app(ClientIpResolver::class)->resolve(request()),
        ];

        Log::log($this->normalizeLevel($level), $message, [
            'channel' => $channel,
            'event' => $event,
            'context' => $payload['context'],
        ]);

        try {
            return MaintenanceLog::query()->create($payload);
        } catch (Throwable) {
            // Si la BD no esta disponible, el log de archivo queda como respaldo operativo.
            return new MaintenanceLog($payload);
        }
    }

    private function normalizeLevel(string $level): string
    {
        return in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)
            ? $level
            : 'info';
    }

    private function sanitizeContext(array $context): array
    {
        $blocked = ['password', 'token', 'secret', 'certificate_password', 'license_key'];

        return collect($context)
            ->mapWithKeys(function (mixed $value, string $key) use ($blocked) {
                foreach ($blocked as $needle) {
                    if (str_contains(strtolower($key), $needle)) {
                        return [$key => '[protegido]'];
                    }
                }

                return [$key => $value];
            })
            ->all();
    }
}
