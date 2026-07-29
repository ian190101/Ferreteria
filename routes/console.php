<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Models\BranchSetting;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Settings\Models\SystemSetting;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Services\ClientSetupService;
use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\ProductionLogService;
use App\Modules\Settings\Services\ProductionAlertService;
use App\Modules\Settings\Services\ProductionUpdateService;
use App\Modules\Settings\Services\SystemHealthService;
use App\Support\AuthSessionCache;
use App\Support\SystemRoles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system:create-master-user {email} {password} {--name=Mr. Robot Bolivia}', function (string $email, string $password) {
    $branch = Branch::query()->orderBy('id')->first()
        ?? Branch::query()->create([
            'name' => 'Sucursal Principal',
            'code' => 'PRINCIPAL',
            'barcode' => 'BR-PRINCIPAL',
            'phone' => null,
            'address' => null,
            'is_active' => true,
        ]);

    $role = Role::firstOrCreate(['name' => SystemRoles::SYSTEM_SUPERADMIN, 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->pluck('name')->all());

    $user = User::query()->updateOrCreate(
        ['email' => $email],
        [
            'branch_id' => $branch->id,
            'name' => (string) $this->option('name'),
            'is_active' => true,
            'password' => Hash::make($password),
            'force_password_change' => false,
        ],
    );

    $user->syncRoles([$role->name]);
    $user->accessibleBranches()->syncWithoutDetaching([$branch->id]);
    AuthSessionCache::bump();

    $this->info("Usuario maestro {$email} preparado correctamente.");

    return Command::SUCCESS;
})->purpose('Crea o actualiza el usuario interno sistemasuperadmin.');

Artisan::command('production:backup-scheduled', function (MaintenanceBackupService $backups) {
    $settings = production_maintenance_settings();

    if (($settings['backup_frequency'] ?? 'daily') === 'disabled') {
        $this->info('Backups automaticos desactivados.');

        return Command::SUCCESS;
    }

    try {
        $backup = $backups->generate($settings['backup_format'] ?? 'sql', metadata: ['reason' => 'scheduled']);
        $deleted = $backups->prune((int) ($settings['backup_retention_days'] ?? 14));
    } catch (\Throwable $exception) {
        app(ProductionLogService::class)->record('maintenance', 'error', 'scheduled_backup_failed', 'Fallo el backup automatico.', [
            'error' => $exception->getMessage(),
        ]);
        app(ProductionAlertService::class)->notify('Fallo backup automatico', 'No se pudo generar el backup automatico programado.', [
            'error' => $exception->getMessage(),
        ]);
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    app(ProductionLogService::class)->record('maintenance', 'info', 'scheduled_backup_completed', 'Backup automatico ejecutado correctamente.', [
        'backup_id' => $backup->id,
        'path' => $backup->path,
        'deleted_expired_backups' => $deleted,
    ]);

    $this->info("Backup automatico generado: {$backup->path}. Eliminados por retencion: {$deleted}.");

    return Command::SUCCESS;
})->purpose('Ejecuta backup automatico y retencion configurada.');

Artisan::command('production:health-check', function (SystemHealthService $health) {
    SystemSetting::query()->updateOrCreate(
        ['key' => 'production.scheduler_heartbeat'],
        [
            'group' => 'production',
            'value' => ['last_run_at' => now()->toISOString()],
            'description' => 'Ultima ejecucion conocida del programador de tareas.',
            'is_public' => false,
        ],
    );

    $result = $health->check();

    foreach ($result['checks'] as $check) {
        $this->line("[{$check['status']}] {$check['name']}: {$check['message']}");
    }

    app(ProductionLogService::class)->record(
        'maintenance',
        $result['status'] === 'ok' ? 'info' : 'warning',
        'health_check_completed',
        'Chequeo productivo ejecutado.',
        ['status' => $result['status']],
    );

    if ($result['status'] !== 'ok') {
        app(ProductionAlertService::class)->notify('Chequeo productivo requiere atencion', 'El chequeo de salud detecto advertencias o errores.', [
            'checks' => $result['checks'],
        ]);
    }

    return $result['status'] === 'ok' ? Command::SUCCESS : Command::FAILURE;
})->purpose('Verifica salud de base de datos, cache, backups, SIAT, permisos y seguridad.');

Artisan::command('production:cleanup', function () {
    try {
        Cache::forget('healthcheck.cache');
        Artisan::call('queue:prune-failed', ['--hours' => 168]);
        Artisan::call('model:prune', ['--no-interaction' => true]);

        app(ProductionLogService::class)->record('maintenance', 'info', 'production_cleanup_completed', 'Limpieza productiva ejecutada correctamente.');
        $this->info('Limpieza productiva completada.');

        return Command::SUCCESS;
    } catch (\Throwable $exception) {
        app(ProductionLogService::class)->record('maintenance', 'error', 'production_cleanup_failed', 'Fallo la limpieza productiva.', [
            'error' => $exception->getMessage(),
        ]);
        app(ProductionAlertService::class)->notify('Fallo limpieza productiva', 'No se pudo completar la limpieza productiva programada.', [
            'error' => $exception->getMessage(),
        ]);
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }
})->purpose('Limpia caches temporales y registros fallidos antiguos de produccion.');

Artisan::command('production:pre-update {--target=}', function (ProductionUpdateService $updates) {
    $result = $updates->prepare($this->option('target') ?: null);
    $this->info('Backup previo generado: '.$result['backup']->path);
    $this->info('Migraciones pendientes: '.(count($result['pending_migrations']) ?: 0));

    return Command::SUCCESS;
})->purpose('Genera backup y diagnostico antes de actualizar codigo.');

Artisan::command('production:migrate-safe', function (ProductionUpdateService $updates) {
    $updates->prepare(config('system_info.version'));
    $result = $updates->applyMigrations();
    $this->line($result['output']);

    return Command::SUCCESS;
})->purpose('Genera backup previo y aplica migraciones en produccion.');

Artisan::command('production:restore-backup {backupId} {--confirm=}', function (int $backupId, ProductionUpdateService $updates) {
    if ($this->option('confirm') !== 'RESTAURAR') {
        $this->error('Operacion bloqueada. Usa --confirm=RESTAURAR para restaurar un backup SQL.');

        return Command::FAILURE;
    }

    $backup = MaintenanceBackup::query()->findOrFail($backupId);
    $result = $updates->rollbackToBackup($backup);
    $this->info($result['message']);

    return Command::SUCCESS;
})->purpose('Restaura un backup SQL generado por el sistema con confirmacion explicita.');

Artisan::command('siat:daily-maintenance', function () {
    $settings = production_maintenance_settings();

    if (! (($settings['siat_auto_cufd'] ?? true) || ($settings['siat_auto_catalog_sync'] ?? true))) {
        $this->info('Mantenimiento SIAT automatico desactivado.');

        return Command::SUCCESS;
    }

    $branches = \App\Modules\Billing\Models\SiatBranchSetting::query()
        ->where('is_active', true)
        ->pluck('branch_id');

    foreach ($branches as $branchId) {
        try {
            if ($settings['siat_auto_cufd'] ?? true) {
                app(\App\Modules\Billing\Services\SiatCodeService::class)->requestCufd((int) $branchId);
            }

            if ($settings['siat_auto_catalog_sync'] ?? true) {
                app(\App\Modules\Billing\Services\SiatCatalogSyncService::class)->syncCoreCatalogs((int) $branchId);
            }

            app(ProductionLogService::class)->record('siat', 'info', 'siat_daily_maintenance_ok', 'Mantenimiento SIAT diario completado.', [
                'branch_id' => $branchId,
            ]);
        } catch (\Throwable $exception) {
            app(ProductionLogService::class)->record('siat', 'error', 'siat_daily_maintenance_failed', 'Fallo el mantenimiento diario SIAT.', [
                'branch_id' => $branchId,
                'error' => $exception->getMessage(),
            ]);
            app(ProductionAlertService::class)->notify('Fallo mantenimiento SIAT', 'No se pudo completar el mantenimiento diario SIAT para una sucursal.', [
                'branch_id' => $branchId,
                'error' => $exception->getMessage(),
            ]);
            $this->error("Sucursal {$branchId}: ".$exception->getMessage());
        }
    }

    return Command::SUCCESS;
})->purpose('Renueva CUFD y sincroniza catalogos SIAT segun configuracion.');

Artisan::command('app:setup-client {--business-name=Fabrica de Calaminas} {--branch-name=Sucursal Central} {--admin-email=admin@calamina.local} {--admin-password=admin123456} {--system-email=mrrobotbolivia@gmail.com} {--system-password=mrrobot2026}', function () {
    app(ClientSetupService::class)->setup([
        'business_name' => (string) $this->option('business-name'),
        'branch_name' => (string) $this->option('branch-name'),
        'branch_code' => 'CENTRAL',
        'admin_name' => 'Administrador',
        'admin_email' => (string) $this->option('admin-email'),
        'admin_password' => (string) $this->option('admin-password'),
        'system_email' => (string) $this->option('system-email'),
        'system_password' => (string) $this->option('system-password'),
    ]);

    AuthSessionCache::bump();
    $this->info('Cliente preparado correctamente. Revisa Sistema > Centro de produccion para validar salud.');

    return Command::SUCCESS;
})->purpose('Prepara una instancia web nueva para un cliente.');

Artisan::command('production:visual-smoke', function () {
    $routes = ['login', 'dashboard', 'settings.info.index', 'settings.system.index'];
    $missing = collect($routes)->reject(fn (string $route) => \Illuminate\Support\Facades\Route::has($route));

    if ($missing->isNotEmpty()) {
        $this->error('Rutas faltantes: '.$missing->implode(', '));

        return Command::FAILURE;
    }

    app(ProductionLogService::class)->record('maintenance', 'info', 'visual_smoke_ready', 'Prueba smoke visual basica completada a nivel rutas.', [
        'routes' => $routes,
    ]);

    $this->info('Smoke visual basico correcto. Para QA visual completa ejecutar navegador/Playwright en el entorno de despliegue.');

    return Command::SUCCESS;
})->purpose('Valida rutas criticas para prueba visual previa a produccion.');

Artisan::command('production:readiness-check', function (SystemHealthService $health, ProductionUpdateService $updates) {
    $failed = false;
    $this->info('Checklist de produccion');
    $this->line('Version: '.config('system_info.version'));
    $this->line('APP_ENV: '.app()->environment());
    $this->line('APP_DEBUG: '.(config('app.debug') ? 'true' : 'false'));
    $this->line('APP_URL: '.config('app.url'));
    $this->line('DB_CONNECTION: '.config('database.default'));
    $this->line('CACHE_STORE: '.config('cache.default'));
    $this->line('QUEUE_CONNECTION: '.Queue::getDefaultDriver());
    $this->line('MAIL_MAILER: '.config('mail.default'));
    $this->newLine();

    foreach ($health->check()['checks'] as $check) {
        $label = strtoupper($check['status']);
        $this->line("[{$label}] {$check['name']}: {$check['message']}");
        $failed = $failed || $check['status'] === 'error';
    }

    $pending = $updates->pendingMigrations();
    if ($pending !== []) {
        $failed = true;
        $this->error('Migraciones pendientes: '.implode(', ', $pending));
    } else {
        $this->info('Migraciones: al dia.');
    }

    $this->newLine();
    $this->line('Recordatorio: SIAT e impresion fisica requieren pruebas externas reales antes de facturar o entregar produccion.');

    return $failed ? Command::FAILURE : Command::SUCCESS;
})->purpose('Ejecuta checklist tecnico final de produccion.');

app(Schedule::class)->command('production:backup-scheduled')->dailyAt('02:00')->withoutOverlapping();
app(Schedule::class)->command('siat:daily-maintenance')->dailyAt('03:00')->withoutOverlapping();
app(Schedule::class)->command('production:health-check')->hourly()->withoutOverlapping();
app(Schedule::class)->command('production:cleanup')->dailyAt('04:00')->withoutOverlapping();

if (! function_exists('production_maintenance_settings')) {
    function production_maintenance_settings(): array
    {
        $stored = SystemSetting::query()->where('key', 'production.maintenance')->value('value');

        return array_merge([
            'backup_frequency' => 'daily',
            'backup_format' => 'sql',
            'backup_retention_days' => 14,
            'siat_auto_cufd' => true,
            'siat_auto_catalog_sync' => true,
            'technical_alert_email' => env('TECHNICAL_ALERT_EMAIL'),
            'offline_pos_enabled' => false,
            'license_validation_mode' => 'offline',
        ], is_array($stored) ? $stored : []);
    }
}
