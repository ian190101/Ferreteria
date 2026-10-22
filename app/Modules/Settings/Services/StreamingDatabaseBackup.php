<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\LazyCollection;
use RuntimeException;

final class StreamingDatabaseBackup
{
    /** @param resource $stream */
    public function write(Connection $connection, $stream, string $format): array
    {
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('El respaldo completo validado requiere MySQL o MariaDB.');
        }
        // Un usuario con acceso a varias bases solo debe respaldar el esquema de esta conexión.
        $tables = collect($connection->getSchemaBuilder()->getTables($connection->getDatabaseName()))->pluck('name')->sort()->values();
        if ($tables->isEmpty()) {
            throw new RuntimeException('No se encontraron tablas para respaldar.');
        }
        $manifest = [];
        if ($format === 'sql') {
            $this->put($stream, "-- calmina-backup-v2\n");
            $this->statement($stream, 'SET FOREIGN_KEY_CHECKS=0;');
            $mode = (string) $connection->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            $mode = $this->recoverySqlMode($mode);
            $this->statement($stream, "SET SQL_MODE='{$mode}';");
        } else {
            $this->put($stream, json_encode(['generated_at' => now()->toIso8601String(), 'database' => $connection->getDatabaseName()], JSON_THROW_ON_ERROR));
            fseek($stream, -1, SEEK_CUR);
            $this->put($stream, ',"tables":{');
        }
        foreach ($tables as $index => $table) {
            $columns = $connection->getSchemaBuilder()->getColumns($table);
            $insertable = collect($columns)->filter(fn ($column) => $column['generation'] === null)->pluck('name')->all();
            foreach ($connection->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_schema'] !== $connection->getDatabaseName()) {
                    throw new RuntimeException('El respaldo detectó relaciones entre bases; requiere una estrategia específica.');
                }
            }
            if ($format === 'sql') {
                $quoted = $this->identifier($table);
                $create = (array) $connection->selectOne('SHOW CREATE TABLE '.$quoted);
                $this->statement($stream, 'DROP TABLE IF EXISTS '.$quoted.';');
                $this->statement($stream, $create['Create Table'].';');
            } else {
                $this->put($stream, ($index === 0 ? '' : ',').json_encode($table, JSON_THROW_ON_ERROR).':[');
            }
            $hash = hash_init('sha256');
            $count = 0;
            foreach ($this->rows($connection, $table) as $row) {
                $data = (array) $row;
                hash_update($hash, serialize($data)."\n");
                if ($format === 'sql') {
                    $data = array_intersect_key($data, array_flip($insertable));
                    $columnSql = implode(', ', array_map($this->identifier(...), array_keys($data)));
                    $values = implode(', ', array_map(fn ($value) => $this->value($connection, $value), array_values($data)));
                    $this->statement($stream, 'INSERT INTO '.$this->identifier($table).' ('.$columnSql.') VALUES ('.$values.');');
                } else {
                    $this->put($stream, ($count === 0 ? '' : ',').json_encode($data, JSON_THROW_ON_ERROR));
                }
                $count++;
            }
            $manifest[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
            if ($format === 'json') {
                $this->put($stream, ']');
            }
        }
        if ($format === 'sql') {
            $this->statement($stream, 'SET FOREIGN_KEY_CHECKS=1;');
        } else {
            $this->put($stream, '}}');
        }
        if (! fflush($stream)) {
            throw new RuntimeException('No se pudo confirmar la escritura del respaldo temporal.');
        }
        $bytes = ftell($stream);
        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        rewind($stream);

        return ['format_version' => 2, 'manifest' => $manifest, 'sha256' => hash_final($hash), 'size_bytes' => $bytes];
    }

    /** @param resource $stream */
    public function restore(Connection $target, $stream, array $preserveTables = [], array $skipDataTables = []): void
    {
        if (trim((string) fgets($stream)) !== '-- calmina-backup-v2') {
            throw new RuntimeException('El archivo no utiliza el formato de respaldo SQL validado.');
        }
        $old = $target->selectOne('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS foreign_keys');
        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (! preg_match('/^-- calmina-statement-bytes:([0-9]+)\s*$/', $line, $match)) {
                    throw new RuntimeException('La estructura del respaldo SQL está dañada.');
                }
                $length = (int) $match[1];
                if ($length <= 0 || $length > 268435456) {
                    throw new RuntimeException('La longitud de una sentencia del respaldo no es válida.');
                }
                $statement = '';
                while (strlen($statement) < $length && ! feof($stream)) {
                    $part = fread($stream, min(65536, $length - strlen($statement)));
                    if ($part === false || $part === '') {
                        throw new RuntimeException('El respaldo SQL está incompleto.');
                    }
                    $statement .= $part;
                }
                if (strlen($statement) !== $length) {
                    throw new RuntimeException('El respaldo SQL está truncado.');
                }
                $preserve = false;
                foreach ($preserveTables as $table) {
                    if (preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) '.preg_quote($this->identifier($table), '/').'(?=[\s;(])/i', $statement)) {
                        $preserve = true;
                        break;
                    }
                }
                foreach ($skipDataTables as $table) {
                    if (preg_match('/^INSERT INTO '.preg_quote($this->identifier($table), '/').'(?=[\s;(])/i', $statement)) {
                        $preserve = true;
                        break;
                    }
                }
                if (! $preserve) {
                    // Los respaldos anteriores también pueden contener esquemas con fechas cero heredadas.
                    if (preg_match("/\\ASET SQL_MODE='([A-Z0-9_,]*)';\\z/", $statement, $mode)) {
                        $statement = 'SET SQL_MODE='.$target->getPdo()->quote($this->recoverySqlMode($mode[1])).';';
                    }
                    $target->getPdo()->exec($statement);
                }
            }
        } finally {
            $target->statement('SET SESSION FOREIGN_KEY_CHECKS = '.((int) $old->foreign_keys));
            $target->statement('SET SESSION SQL_MODE = '.$target->getPdo()->quote($old->sql_mode));
        }
    }

    public function verifyRestored(Connection $target, array $manifest, array $skipDataTables = []): void
    {
        foreach ($manifest as $table => $expected) {
            if (! in_array($table, $skipDataTables, true)) {
                $hash = hash_init('sha256');
                $count = 0;
                foreach ($this->rows($target, $table) as $row) {
                    hash_update($hash, serialize((array) $row)."\n");
                    $count++;
                }
                if ($count !== $expected['rows'] || ! hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new RuntimeException('La restauración no coincide con el respaldo en la tabla '.$table.'.');
                }
            }
            foreach ($target->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
                $query = $target->table($table.' as child')->leftJoin($foreignKey['foreign_table'].' as parent', function ($join) use ($foreignKey): void {
                    foreach ($foreignKey['columns'] as $index => $column) {
                        $join->on('child.'.$column, '=', 'parent.'.$foreignKey['foreign_columns'][$index]);
                    }
                });
                foreach ($foreignKey['columns'] as $column) {
                    $query->whereNotNull('child.'.$column);
                }
                if ($query->whereNull('parent.'.$foreignKey['foreign_columns'][0])->exists()) {
                    throw new RuntimeException('La restauración contiene referencias huérfanas en '.$table.'.');
                }
            }
        }
    }

    private function recoverySqlMode(string $mode): string
    {
        // Solo el ensayo y la recuperación admiten fechas cero; el modo de la aplicación se conserva.
        $modes = array_diff(array_filter(explode(',', $mode)), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']);

        return implode(',', array_unique([...$modes, 'NO_AUTO_VALUE_ON_ZERO']));
    }

    private function rows(Connection $connection, string $table): LazyCollection
    {
        $indexes = $connection->getSchemaBuilder()->getIndexes($table);
        $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? $connection->getSchemaBuilder()->getColumnListing($table);
        $query = $connection->table($table);
        foreach ($primary as $column) {
            $query->orderBy($column);
        }

        return $query->lazy(500);
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function value(Connection $connection, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            default => $connection->getPdo()->quote((string) $value),
        };
    }

    /** @param resource $stream */
    private function statement($stream, string $sql): void
    {
        $this->put($stream, '-- calmina-statement-bytes:'.strlen($sql)."\n".$sql."\n");
    }

    /** @param resource $stream */
    private function put($stream, string $content): void
    {
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo escribir el respaldo. Revisa espacio y permisos.');
            }
            $offset += $written;
        }
    }
}
