<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SystemInfoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/System/Info', [
            'system' => [
                'name' => config('system_info.name'),
                'version' => config('system_info.version'),
                'developer' => config('system_info.developer'),
                'year' => config('system_info.year'),
                'support_email' => config('system_info.support_email'),
                'timezone' => config('app.timezone'),
                'server_time' => now()->format('d/m/Y H:i:s'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'locale' => app()->getLocale(),
                'cache' => config('cache.default'),
                'session' => config('session.driver'),
                'queue' => config('queue.default'),
                'filesystem' => config('filesystems.default'),
            ],
            'database' => [
                'connection' => DB::connection()->getName(),
                'driver' => DB::connection()->getDriverName(),
                'database' => config('database.connections.'.DB::connection()->getName().'.database'),
                'server' => $this->databaseVersion(),
                'tables' => $this->tableCount(),
            ],
            'hosting' => [
                'trusted_proxies' => env('TRUSTED_PROXIES') ?: 'No definido',
                'render_service' => env('RENDER_SERVICE_NAME') ?: 'No detectado',
                'render_external_url' => env('RENDER_EXTERNAL_URL') ?: 'No detectado',
                'render_commit' => env('RENDER_GIT_COMMIT') ?: 'No detectado',
            ],
        ]);
    }

    private function databaseVersion(): string
    {
        try {
            $row = DB::selectOne('select version() as version');

            return (string) ($row->version ?? 'No disponible');
        } catch (Throwable) {
            return 'No disponible';
        }
    }

    private function tableCount(): int
    {
        try {
            return count(Schema::getTables());
        } catch (Throwable) {
            return 0;
        }
    }
}
