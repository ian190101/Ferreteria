<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Support\OperationalCacheOutbox;
use App\Support\OperationalWriteGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MaintenanceBackupService
{
    public function generate(string $format = 'json', ?User $user = null, array $metadata = []): MaintenanceBackup
    {
        $format = $format === 'sql' ? 'sql' : 'json';
        $stream = tmpfile();
        if ($stream === false) {
            throw ValidationException::withMessages(['backup' => 'No se pudo abrir un archivo temporal para el respaldo.']);
        }
        $path = 'backups/backup-'.now()->format('Ymd-His').'-'.Str::ulid().'.'.$format;
        $partial = $path.'.partial';
        $disk = Storage::disk('local');
        try {
            if (DB::transactionLevel() === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $details = DB::transaction(fn () => app(StreamingDatabaseBackup::class)->write(DB::connection(), $stream, $format));
            if (! $disk->put($partial, $stream)) {
                throw new \RuntimeException('No se pudo escribir el respaldo en el disco configurado.');
            }
            $attributes = [
                'user_id' => $user?->id ?? User::query()->orderBy('id')->value('id'),
                'disk' => 'local', 'path' => $partial, 'status' => 'created', 'size_bytes' => $details['size_bytes'],
                'metadata' => [...$metadata, ...$details, 'format' => $format, 'database' => DB::connection()->getDatabaseName(),
                    'generated_by' => $user ? 'user' : 'system'],
            ];
            if (! $this->verifyReadable(new MaintenanceBackup($attributes))['ok'] || ! $disk->move($partial, $path)) {
                throw new \RuntimeException('El respaldo no superó la comprobación de integridad o su publicación.');
            }
            $attributes['path'] = $path;

            return MaintenanceBackup::query()->create($attributes);
        } catch (Throwable $exception) {
            $disk->delete($partial);
            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    public function prune(int $retentionDays): int
    {
        if ($retentionDays < 1) {
            return 0;
        }

        $expired = MaintenanceBackup::query()
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->get();
        $deleted = 0;

        foreach ($expired as $backup) {
            Storage::disk($backup->disk)->delete($backup->path);
            $backup->delete();
            $deleted++;
        }

        return $deleted;
    }

    public function verifyReadable(MaintenanceBackup $backup): array
    {
        $disk = Storage::disk($backup->disk);

        if (! $disk->exists($backup->path)) {
            return ['ok' => false, 'message' => 'El archivo de backup no existe en el disco configurado.'];
        }

        if (($backup->metadata['format_version'] ?? null) === 2) {
            $stream = $disk->readStream($backup->path);
            if ($stream === false) {
                return ['ok' => false, 'message' => 'No se pudo abrir el respaldo para verificarlo.'];
            }
            try {
                $prefix = fread($stream, 64);
                $hash = hash_init('sha256');
                hash_update($hash, $prefix);
                $bytes = strlen($prefix) + hash_update_stream($hash, $stream);
                $valid = $bytes > 0 && $bytes === (int) $backup->size_bytes
                    && hash_equals((string) ($backup->metadata['sha256'] ?? ''), hash_final($hash));
                $valid = $valid && (($backup->metadata['format'] ?? '') === 'sql'
                    ? str_starts_with($prefix, "-- calmina-backup-v2\n") : str_starts_with($prefix, '{'));

                return ['ok' => $valid, 'message' => $valid ? 'Tamaño y SHA-256 del respaldo verificados.' : 'El respaldo está incompleto o su integridad no coincide.'];
            } finally {
                fclose($stream);
            }
        }

        $content = $disk->get($backup->path);
        $format = $backup->metadata['format'] ?? pathinfo($backup->path, PATHINFO_EXTENSION);

        if ($format === 'json') {
            json_decode($content, true);

            return [
                'ok' => json_last_error() === JSON_ERROR_NONE,
                'message' => json_last_error() === JSON_ERROR_NONE
                    ? 'Backup JSON legible y estructurado.'
                    : 'El backup JSON no se puede interpretar.',
            ];
        }

        return [
            'ok' => str_contains($content, 'SET FOREIGN_KEY_CHECKS=0') || str_contains($content, 'INSERT INTO'),
            'message' => 'Backup SQL legible para restauracion manual controlada.',
        ];
    }

    public function verifyRecovery(MaintenanceBackup $backup): array
    {
        if (($backup->metadata['format'] ?? null) !== 'sql' || ($backup->metadata['format_version'] ?? null) !== 2
            || ! $this->verifyReadable($backup)['ok']) {
            throw ValidationException::withMessages(['backup' => 'El respaldo SQL debe superar su verificación de integridad antes del ensayo de restauración.']);
        }
        $source = DB::connection();
        $settings = config('maintenance.restore_verification', []);
        $expectedDatabase = $source->getDatabaseName().'_restore_verification';
        if (($settings['database'] ?? null) !== $expectedDatabase || empty($settings['username'])
            || strtolower((string) $settings['username']) === 'root' || $settings['username'] === $source->getConfig('username')
            || ($backup->metadata['database'] ?? null) !== $source->getDatabaseName()) {
            throw ValidationException::withMessages(['backup' => 'Configura RESTORE_DB_* con una base desechable llamada '.$expectedDatabase.' y un usuario exclusivo antes de purgar.']);
        }
        $connection = config('database.connections.'.$source->getName());
        $connection = array_replace($connection, array_filter($settings, fn ($value, $key) => $value !== null && (! in_array($key, ['host', 'port'], true) || $value !== ''), ARRAY_FILTER_USE_BOTH));
        $connection['url'] = null;
        $connection['unix_socket'] = '';
        $connection['options'][\PDO::ATTR_PERSISTENT] = false;
        config(['database.connections.backup_verification' => $connection]);
        DB::purge('backup_verification');
        $target = DB::connection('backup_verification');
        if ($target->getDatabaseName() !== $expectedDatabase || $target->getDriverName() !== 'mysql') {
            throw ValidationException::withMessages(['backup' => 'La conexión temporal de restauración no es segura.']);
        }
        $lock = 'calmina:restore:'.sha1($expectedDatabase);
        if ((int) $target->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired !== 1) {
            throw ValidationException::withMessages(['backup' => 'Ya existe un ensayo de restauración en curso.']);
        }
        $stream = null;
        try {
            $target->getSchemaBuilder()->dropAllTables();
            $stream = Storage::disk($backup->disk)->readStream($backup->path);
            if ($stream === false) {
                throw new \RuntimeException('No se pudo abrir el respaldo para el ensayo.');
            }
            $writer = app(StreamingDatabaseBackup::class);
            $writer->restore($target, $stream);
            $writer->verifyRestored($target, $backup->metadata['manifest']);
            $target->getSchemaBuilder()->dropAllTables();
            $result = ['ok' => true, 'checked_at' => now()->toIso8601String(),
                'tables' => count($backup->metadata['manifest']),
                'rows' => array_sum(array_column($backup->metadata['manifest'], 'rows')),
                'message' => 'Restauración real verificada en una base temporal, sin diferencias ni nuevas referencias huérfanas.'];
            $backup->update(['metadata' => [...$backup->metadata, 'recovery_verification' => $result]]);

            return $result;
        } catch (Throwable $exception) {
            $backup->update(['metadata' => [...$backup->metadata, 'recovery_verification' => [
                'ok' => false, 'checked_at' => now()->toIso8601String(), 'exception' => $exception::class,
            ]]]);
            try {
                $target->getSchemaBuilder()->dropAllTables();
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
            report($exception);
            throw ValidationException::withMessages(['backup' => 'Falló el ensayo de restauración. El respaldo se conserva y la purga no puede continuar.']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $target->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            DB::purge('backup_verification');
        }
    }

    public function restoreSql(MaintenanceBackup $backup, ?\Closure $progress = null): array
    {
        if (($backup->metadata['format_version'] ?? null) !== 2) {
            throw ValidationException::withMessages(['backup' => 'Este respaldo histórico no tiene manifiesto verificable. Consérvalo y realiza una restauración manual ensayada.']);
        }

        return app(OperationalWriteGate::class)->exclusive(function () use ($backup, $progress): array {
            $progress?->__invoke('verifying_recovery');
            $this->verifyRecovery($backup);
            $stream = Storage::disk($backup->disk)->readStream($backup->path);
            if ($stream === false) {
                throw ValidationException::withMessages(['backup' => 'No se pudo abrir el respaldo verificado.']);
            }
            try {
                $writer = app(StreamingDatabaseBackup::class);
                // Estos datos siguen vivos durante el progreso; no deben retroceder ni resucitar trabajos antiguos.
                $runtimeTables = ['cache', 'cache_locks', 'jobs', 'failed_jobs', 'sessions'];
                $existingTables = collect(DB::connection()->getSchemaBuilder()->getTables())->pluck('name')->all();
                $preservedTables = ['maintenance_barriers', ...array_intersect($runtimeTables, $existingTables)];
                $progress?->__invoke('restoring');
                $writer->restore(DB::connection(), $stream, $preservedTables, $runtimeTables);
                $progress?->__invoke('verifying_source');
                $writer->verifyRestored(DB::connection(), $backup->metadata['manifest'], $runtimeTables);
            } finally {
                fclose($stream);
            }
            // Una restauración no debe volver a ejecutar purgas que estaban pendientes en el respaldo.
            $progress?->__invoke('finalizing');
            DB::table('jobs')->where('queue', 'maintenance')->delete();
            DB::table('maintenance_purge_runs')->whereIn('status', ['pending', 'running', 'committed'])
                ->update(['status' => 'failed', 'stage' => 'cancelled_by_restore', 'message' => 'Solicitud cancelada tras restaurar la base. Requiere una nueva vista previa.', 'updated_at' => now()]);
            DB::table('maintenance_deployment_runs')->whereIn('status', ['pending', 'running'])
                ->update(['status' => 'failed', 'result' => json_encode(['successful' => false, 'message' => 'Actualización cancelada tras restaurar la base. Requiere revisión del código desplegado.'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $userId = User::query()->whereKey($backup->user_id)->value('id') ?? User::query()->orderBy('id')->value('id');
            MaintenanceBackup::query()->create([
                'user_id' => $userId, 'disk' => $backup->disk, 'path' => $backup->path, 'status' => 'restored',
                'size_bytes' => $backup->size_bytes,
                'metadata' => [...$backup->metadata, 'restored_at' => now()->toIso8601String()],
            ]);
            app(OperationalCacheOutbox::class)->schedule('recovery');
            if (DB::table('cache_invalidation_outbox')->where('scope', 'recovery')->exists()) {
                if (! app()->maintenanceMode()->active()) {
                    app()->maintenanceMode()->activate(['time' => time(), 'retry' => 60, 'status' => 503]);
                }
                throw new \RuntimeException('Los datos se recuperaron, pero falta invalidar la caché de autorización. El servicio permanece en mantenimiento.');
            }

            return ['ok' => true, 'message' => 'Respaldo restaurado y datos operativos verificados.', 'preserved_runtime_tables' => $runtimeTables];
        });
    }
}
