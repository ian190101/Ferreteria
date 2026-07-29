<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\MaintenanceLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ProductionUpdateService
{
    public function prepare(?string $targetVersion = null): array
    {
        $backup = app(MaintenanceBackupService::class)->generate('sql', metadata: [
            'reason' => 'pre_update',
            'target_version' => $targetVersion,
        ]);

        app(ProductionLogService::class)->record('maintenance', 'info', 'pre_update_ready', 'Backup previo a actualizacion generado.', [
            'backup_id' => $backup->id,
            'target_version' => $targetVersion,
        ]);

        return [
            'backup' => $backup,
            'pending_migrations' => $this->pendingMigrations(),
            'current_version' => config('system_info.version'),
            'target_version' => $targetVersion,
        ];
    }

    public function applyMigrations(): array
    {
        Artisan::call('migrate', ['--force' => true]);

        app(ProductionLogService::class)->record('maintenance', 'info', 'migrations_applied', 'Migraciones aplicadas desde modulo de mantenimiento.', [
            'output' => Artisan::output(),
        ]);

        return [
            'output' => Artisan::output(),
            'pending_migrations' => $this->pendingMigrations(),
        ];
    }

    public function rollbackToBackup(MaintenanceBackup $backup): array
    {
        $result = app(MaintenanceBackupService::class)->restoreSql($backup);

        app(ProductionLogService::class)->record('maintenance', 'warning', 'production_rollback_restored', 'Se restauro un backup SQL desde mantenimiento productivo.', [
            'backup_id' => $backup->id,
            'path' => $backup->path,
        ]);

        app(ProductionAlertService::class)->notify('Rollback productivo ejecutado', 'Se restauro un backup SQL desde el centro de produccion.', [
            'backup_id' => $backup->id,
            'path' => $backup->path,
        ]);

        return $result;
    }

    public function pendingMigrations(): array
    {
        $ran = collect(DB::table('migrations')->pluck('migration'))->all();

        return collect(File::files(database_path('migrations')))
            ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
            ->reject(fn (string $migration) => in_array($migration, $ran, true))
            ->values()
            ->all();
    }

    public function latestPreUpdateBackup(): ?MaintenanceBackup
    {
        return MaintenanceBackup::query()
            ->where('metadata->reason', 'pre_update')
            ->latest()
            ->first();
    }
}
