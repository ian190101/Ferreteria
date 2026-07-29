<?php

namespace App\Modules\Settings\Services;

use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCufd;
use App\Modules\Settings\Models\ApplicationLicense;
use App\Modules\Settings\Models\MaintenanceBackup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Throwable;

class SystemHealthService
{
    public function check(): array
    {
        $checks = [
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->mail(),
            $this->scheduler(),
            $this->storagePath(),
            $this->latestBackup(),
            $this->license(),
            $this->siat(),
            $this->productionSecurity(),
        ];

        return [
            'status' => collect($checks)->contains(fn (array $check) => $check['status'] === 'error') ? 'error' : 'ok',
            'checked_at' => now()->format('d/m/Y H:i:s'),
            'checks' => $checks,
        ];
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->ok('Base de datos', 'Conexion activa.');
        } catch (Throwable $exception) {
            return $this->error('Base de datos', 'No se pudo conectar a la base de datos.', $exception->getMessage());
        }
    }

    private function cache(): array
    {
        try {
            Cache::put('healthcheck.cache', now()->timestamp, 30);

            return Cache::has('healthcheck.cache')
                ? $this->ok('Cache', 'Cache operativo con driver '.config('cache.default').'.')
                : $this->warning('Cache', 'El cache no confirmo escritura/lectura.');
        } catch (Throwable $exception) {
            return $this->error('Cache', 'No se pudo escribir en cache.', $exception->getMessage());
        }
    }

    private function queue(): array
    {
        try {
            $driver = Queue::getDefaultDriver();

            return $driver === 'sync'
                ? $this->warning('Colas', 'QUEUE_CONNECTION esta en sync. Para produccion se recomienda database o redis con queue worker activo.')
                : $this->ok('Colas', 'Driver de cola configurado: '.$driver.'.');
        } catch (Throwable) {
            return $this->warning('Colas', 'No se pudo leer el driver de colas.');
        }
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->warning('Correo', 'MAIL_MAILER esta en '.$mailer.'. Las alertas tecnicas no saldran por correo real.');
        }

        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->warning('Correo', 'MAIL_FROM_ADDRESS no parece ser un correo valido.');
        }

        return $this->ok('Correo', 'Mailer productivo configurado: '.$mailer.'.');
    }

    private function scheduler(): array
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return $this->warning('Tareas programadas', 'No se puede verificar schedule hasta migrar system_settings.');
            }

            $lastRun = \App\Modules\Settings\Models\SystemSetting::query()
                ->where('key', 'production.scheduler_heartbeat')
                ->value('value');
            $lastRunAt = is_array($lastRun) ? ($lastRun['last_run_at'] ?? null) : null;

            if (! $lastRunAt) {
                return $this->warning('Tareas programadas', 'Aun no se registro ejecucion de schedule. Configura cron cada minuto.');
            }

            $lastRunCarbon = Carbon::parse($lastRunAt);

            return $lastRunCarbon->lt(now()->subHours(2))
                ? $this->warning('Tareas programadas', 'El ultimo heartbeat de schedule tiene mas de 2 horas.')
                : $this->ok('Tareas programadas', 'Ultimo heartbeat: '.$lastRunCarbon->format('d/m/Y H:i'));
        } catch (Throwable $exception) {
            return $this->warning('Tareas programadas', 'No se pudo verificar el heartbeat del schedule.', $exception->getMessage());
        }
    }

    private function storagePath(): array
    {
        $paths = [storage_path('app'), storage_path('logs'), storage_path('framework/cache'), base_path('bootstrap/cache')];
        $blocked = collect($paths)->filter(fn (string $path) => ! File::isWritable($path))->values();

        return $blocked->isEmpty()
            ? $this->ok('Permisos de archivos', 'Storage, logs y cache son escribibles.')
            : $this->error('Permisos de archivos', 'Hay carpetas sin permisos de escritura.', $blocked->all());
    }

    private function latestBackup(): array
    {
        $backup = MaintenanceBackup::query()->latest()->first();

        if (! $backup) {
            return $this->warning('Backups', 'Aun no existe ningun backup registrado.');
        }

        return $backup->created_at?->lt(now()->subDay())
            ? $this->warning('Backups', 'El ultimo backup tiene mas de 24 horas.')
            : $this->ok('Backups', 'Ultimo backup: '.$backup->created_at?->format('d/m/Y H:i'));
    }

    private function license(): array
    {
        if (! Schema::hasTable('application_licenses')) {
            return $this->warning('Licencia', 'La tabla de licencias aun no esta migrada.');
        }

        $license = ApplicationLicense::query()->where('status', ApplicationLicense::STATUS_ACTIVE)->latest()->first();

        if (! $license) {
            return $this->warning('Licencia', 'No hay una licencia activa registrada para esta instalacion.');
        }

        if ($license->support_until && $license->support_until->isPast()) {
            return $this->warning('Licencia', 'La fecha de soporte de la licencia vencio.');
        }

        return $this->ok('Licencia', 'Licencia activa para '.$license->holder_name.'.');
    }

    private function siat(): array
    {
        if (! Schema::hasTable('siat_branch_settings')) {
            return $this->warning('SIAT', 'El modulo SIAT aun no esta migrado.');
        }

        $settings = SiatBranchSetting::query()->where('is_active', true)->count();
        $cufds = SiatCufd::query()
            ->where('status', SiatCufd::STATUS_ACTIVE)
            ->where('valid_until', '>', now())
            ->count();

        if ($settings === 0) {
            return $this->warning('SIAT', 'No hay configuracion SIAT activa.');
        }

        return $cufds > 0
            ? $this->ok('SIAT', 'Configuracion activa con CUFD vigente.')
            : $this->warning('SIAT', 'Hay configuracion activa, pero no se encontro CUFD vigente.');
    }

    private function productionSecurity(): array
    {
        if (! app()->environment('production')) {
            return $this->warning('Seguridad produccion', 'El sistema no esta en entorno production.');
        }

        if (config('app.debug')) {
            return $this->error('Seguridad produccion', 'APP_DEBUG debe estar en false para produccion.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            return $this->warning('Seguridad produccion', 'APP_URL deberia usar HTTPS.');
        }

        if (! config('session.secure')) {
            return $this->warning('Seguridad produccion', 'SESSION_SECURE_COOKIE debe estar activo cuando el sitio usa HTTPS.');
        }

        return $this->ok('Seguridad produccion', 'Entorno, debug y URL principal estan alineados.');
    }

    private function ok(string $name, string $message, mixed $details = null): array
    {
        return compact('name', 'message') + ['status' => 'ok', 'details' => $details];
    }

    private function warning(string $name, string $message, mixed $details = null): array
    {
        return compact('name', 'message') + ['status' => 'warning', 'details' => $details];
    }

    private function error(string $name, string $message, mixed $details = null): array
    {
        return compact('name', 'message') + ['status' => 'error', 'details' => $details];
    }
}
