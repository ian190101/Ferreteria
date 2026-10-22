<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PurgeScopePlanner
{
    public function candidates(string $table, bool $trash, ?string $alias = null): Builder
    {
        $query = DB::table($alias ? $table.' as '.$alias : $table);
        if ($trash) {
            Schema::hasColumn($table, 'deleted_at')
                ? $query->whereNotNull(($alias ?? $table).'.deleted_at')
                : $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** @param array<int, string> $tables */
    public function calculate(array $tables, bool $trash): array
    {
        $counts = [];
        $preserved = [];
        $fingerprint = hash_init('sha256');
        foreach ($tables as $table) {
            $counts[$table] = $this->candidates($table, $trash)->count();
            $preserved[$table] = $trash ? DB::table($table)->count() - $counts[$table] : 0;
            hash_update($fingerprint, $table."\n");
            if ($counts[$table] === 0) {
                continue;
            }
            $query = $this->candidates($table, $trash);
            $indexes = Schema::getIndexes($table);
            $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? Schema::getColumnListing($table);
            foreach ($primary as $column) {
                $query->orderBy($column);
            }
            $query->chunk(500, function ($rows) use ($fingerprint): void {
                foreach ($rows as $row) {
                    hash_update($fingerprint, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                }
            });
        }

        $blockers = [];
        $edges = [];
        $breaks = [];
        $dependencies = [];
        foreach ($this->foreignKeys() as $foreignKey) {
            $child = $foreignKey['child'];
            $parent = $foreignKey['parent'];
            if ($foreignKey['foreign_schema'] !== DB::connection()->getDatabaseName()
                || ! in_array($parent, $tables, true) || $counts[$parent] === 0) {
                continue;
            }
            if ($foreignKey['schema'] !== DB::connection()->getDatabaseName()) {
                $blockers[] = "Existe una dependencia desde otra base de datos hacia {$parent}; requiere revisión específica.";

                continue;
            }
            $relation = DB::table($child.' as child')->join($parent.' as parent', function ($join) use ($foreignKey): void {
                foreach ($foreignKey['columns'] as $index => $column) {
                    $join->on('child.'.$column, '=', 'parent.'.$foreignKey['foreign_columns'][$index]);
                }
            });
            if ($trash) {
                $relation->whereNotNull('parent.deleted_at');
            }
            $references = (clone $relation)->count();
            if ($references === 0) {
                continue;
            }
            $childSelected = in_array($child, $tables, true);
            $retainedCount = $references;
            if ($childSelected && ! $trash) {
                $retainedCount = 0;
            } elseif ($childSelected && Schema::hasColumn($child, 'deleted_at')) {
                $retainedCount = (clone $relation)->whereNull('child.deleted_at')->count();
            }
            $dependencies[] = ['table' => $child, 'parent' => $parent, 'references' => $references, 'retained' => $retainedCount];
            if ($retainedCount > 0) {
                $blockers[] = "{$child} conserva {$retainedCount} referencias a {$parent}; incluye las dependencias o conserva el registro padre.";

                continue;
            }
            $edges[] = ['child' => $child, 'parent' => $parent, 'columns' => $foreignKey['columns'], 'nullable' => $foreignKey['nullable']];
        }

        // Las referencias polimórficas no aparecen en information_schema.
        foreach (['inventory_movements' => ['source_type', 'source_id'], 'bank_transactions' => ['source_type', 'source_id'],
            'print_jobs' => ['printable_type', 'printable_id'], 'customer_qr_orders' => ['converted_to_type', 'converted_to_id']] as $child => [$typeColumn, $idColumn]) {
            if (! Schema::hasTable($child)) {
                continue;
            }
            $types = DB::table($child)->whereNotNull($typeColumn)->distinct()->pluck($typeColumn);
            foreach ($types as $type) {
                $class = Relation::getMorphedModel($type) ?? $type;
                if (! is_string($class) || ! str_starts_with($class, 'App\\') || ! is_subclass_of($class, Model::class)) {
                    $blockers[] = "{$child} contiene una referencia polimórfica no reconocida; requiere revisión antes de purgar.";

                    continue;
                }
                $parent = (new $class)->getTable();
                if (! in_array($parent, $tables, true) || $counts[$parent] === 0) {
                    continue;
                }
                $relation = DB::table($child.' as child')->where('child.'.$typeColumn, $type)
                    ->join($parent.' as parent', 'child.'.$idColumn, '=', 'parent.id');
                if ($trash) {
                    $relation->whereNotNull('parent.deleted_at');
                }
                $references = (clone $relation)->count();
                if ($references === 0) {
                    continue;
                }
                if (in_array($child, $tables, true)) {
                    if (! $trash) {
                        $relation->whereRaw('1 = 0');
                    } elseif (Schema::hasColumn($child, 'deleted_at')) {
                        $relation->whereNull('child.deleted_at');
                    }
                }
                $retainedCount = $relation->count();
                $dependencies[] = ['table' => $child, 'parent' => $parent, 'references' => $references, 'retained' => $retainedCount];
                if ($retainedCount > 0) {
                    $blockers[] = "{$child} conserva referencias polimórficas a {$parent}.";
                }
            }
        }

        $remaining = $tables;
        $ordered = [];
        while ($remaining !== []) {
            $ready = array_values(array_filter($remaining, fn ($table) => ! collect($edges)->contains(fn ($edge) => $edge['parent'] === $table && in_array($edge['child'], $remaining, true))));
            if ($ready === []) {
                $breakable = collect($edges)->first(fn ($edge) => $edge['nullable'] && in_array($edge['child'], $remaining, true) && in_array($edge['parent'], $remaining, true));
                if (! $breakable) {
                    $blockers[] = 'Dependencia circular obligatoria: '.implode(', ', $remaining).'. Se requiere una estrategia específica del esquema.';
                    break;
                }
                $breaks[] = $breakable;
                $edges = array_values(array_filter($edges, fn ($edge) => $edge !== $breakable));

                continue;
            }
            sort($ready);
            $ordered = [...$ordered, ...$ready];
            $remaining = array_values(array_diff($remaining, $ready));
        }

        hash_update($fingerprint, json_encode([$counts, $preserved], JSON_THROW_ON_ERROR));

        return ['counts' => $counts, 'preserved' => $preserved, 'dependencies' => $dependencies,
            'blockers' => array_values(array_unique($blockers)), 'order' => $ordered, 'nullable_breaks' => $breaks,
            'fingerprint' => hash_final($fingerprint)];
    }

    private function foreignKeys(): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.COLUMNS as c', function ($join): void {
                $join->on('c.TABLE_SCHEMA', '=', 'k.TABLE_SCHEMA')->on('c.TABLE_NAME', '=', 'k.TABLE_NAME')->on('c.COLUMN_NAME', '=', 'k.COLUMN_NAME');
            })
            ->whereNotNull('k.REFERENCED_TABLE_NAME')
            ->where(fn ($query) => $query->where('k.TABLE_SCHEMA', $database)->orWhere('k.REFERENCED_TABLE_SCHEMA', $database))
            ->orderBy('k.TABLE_SCHEMA')->orderBy('k.TABLE_NAME')->orderBy('k.CONSTRAINT_NAME')->orderBy('k.ORDINAL_POSITION')
            ->get(['k.TABLE_SCHEMA as schema_name', 'k.TABLE_NAME as child_table', 'k.CONSTRAINT_NAME as constraint_name',
                'k.COLUMN_NAME as column_name', 'k.REFERENCED_TABLE_SCHEMA as foreign_schema',
                'k.REFERENCED_TABLE_NAME as parent_table', 'k.REFERENCED_COLUMN_NAME as foreign_column', 'c.IS_NULLABLE as is_nullable']);
        $keys = [];
        foreach ($rows as $row) {
            $id = $row->schema_name.'.'.$row->child_table.'.'.$row->constraint_name;
            $keys[$id] ??= ['schema' => $row->schema_name, 'foreign_schema' => $row->foreign_schema,
                'child' => $row->child_table, 'parent' => $row->parent_table,
                'columns' => [], 'foreign_columns' => [], 'nullable' => true];
            $keys[$id]['columns'][] = $row->column_name;
            $keys[$id]['foreign_columns'][] = $row->foreign_column;
            $keys[$id]['nullable'] = $keys[$id]['nullable'] && $row->is_nullable === 'YES';
        }

        return array_values($keys);
    }
}
