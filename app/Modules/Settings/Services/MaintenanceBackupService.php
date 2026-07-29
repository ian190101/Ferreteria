<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MaintenanceBackupService
{
    public function generate(string $format = 'json', ?User $user = null, array $metadata = []): MaintenanceBackup
    {
        $format = $format === 'sql' ? 'sql' : 'json';
        $content = $format === 'sql' ? $this->buildSqlDump() : $this->buildJsonBackup();
        $path = 'backups/backup-'.now()->format('Ymd-His').'.'.$format;

        Storage::disk('local')->put($path, $content);

        return MaintenanceBackup::query()->create([
            'user_id' => $user?->id ?? User::query()->orderBy('id')->value('id'),
            'disk' => 'local',
            'path' => $path,
            'status' => 'created',
            'size_bytes' => strlen($content),
            'metadata' => array_merge([
                'format' => $format,
                'database' => config('database.connections.'.DB::connection()->getName().'.database'),
                'generated_by' => $user ? 'user' : 'system',
            ], $metadata),
        ]);
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

    public function restoreSql(MaintenanceBackup $backup): array
    {
        $format = $backup->metadata['format'] ?? pathinfo($backup->path, PATHINFO_EXTENSION);

        if ($format !== 'sql') {
            throw ValidationException::withMessages([
                'backup' => 'Solo se puede restaurar automaticamente un backup SQL. Los backups JSON son para revision operativa.',
            ]);
        }

        $disk = Storage::disk($backup->disk);

        if (! $disk->exists($backup->path)) {
            throw ValidationException::withMessages([
                'backup' => 'El archivo de backup no existe en el disco configurado.',
            ]);
        }

        $content = $disk->get($backup->path);

        if (! str_contains($content, 'SET FOREIGN_KEY_CHECKS=0') || ! str_contains($content, 'INSERT INTO')) {
            throw ValidationException::withMessages([
                'backup' => 'El backup SQL no parece ser un respaldo completo generado por el sistema.',
            ]);
        }

        DB::connection()->getPdo()->exec($content);

        $backup->update([
            'status' => 'restored',
            'metadata' => array_merge($backup->metadata ?? [], [
                'restored_at' => now()->toISOString(),
            ]),
        ]);

        return [
            'ok' => true,
            'message' => 'Backup SQL restaurado correctamente. Revisa el sistema antes de continuar operando.',
        ];
    }

    private function buildJsonBackup(): string
    {
        $tables = $this->tableNames(DB::connection()->getDriverName());
        $payload = [
            'generated_at' => now()->toISOString(),
            'database' => DB::connection()->getDatabaseName(),
            'tables' => $tables
                ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderByRaw('1')->limit(5000)->get()->toArray()])
                ->all(),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function buildSqlDump(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();
        $tables = $this->tableNames($driver);

        $sql = [
            '-- Backup SQL generado por el sistema',
            '-- Base de datos: '.$database,
            '-- Fecha: '.now()->format('Y-m-d H:i:s'),
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
            '',
        ];

        foreach ($tables as $table) {
            $quotedTable = str_replace('`', '``', $table);
            $sql[] = '-- Tabla: '.$table;
            $sql[] = 'DROP TABLE IF EXISTS `'.$quotedTable.'`;';

            if ($driver === 'mysql') {
                $create = $connection->selectOne('SHOW CREATE TABLE `'.$quotedTable.'`');
                $createSql = $create->{'Create Table'} ?? array_values((array) $create)[1] ?? null;
                if ($createSql) {
                    $sql[] = $createSql.';';
                }
            }

            $connection->table($table)->orderByRaw('1')->chunk(500, function ($rows) use (&$sql, $quotedTable) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $columns = collect(array_keys($data))->map(fn (string $column) => '`'.str_replace('`', '``', $column).'`')->implode(', ');
                    $values = collect(array_values($data))->map(fn (mixed $value) => $this->sqlValue($value))->implode(', ');
                    $sql[] = 'INSERT INTO `'.$quotedTable.'` ('.$columns.') VALUES ('.$values.');';
                }
            });
            $sql[] = '';
        }

        $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $sql[] = '';

        return implode(PHP_EOL, $sql);
    }

    private function tableNames(string $driver): Collection
    {
        try {
            if ($driver === 'mysql') {
                return collect(DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']))
                    ->map(fn (object $row) => array_values((array) $row)[0])
                    ->values();
            }

            return collect(Schema::getTables())
                ->map(fn (array $table) => $table['name'] ?? $table['schema_qualified_name'] ?? null)
                ->filter()
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }
}
