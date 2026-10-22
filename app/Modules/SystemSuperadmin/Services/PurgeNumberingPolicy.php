<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Services;

use App\Modules\Sales\Models\DocumentSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PurgeNumberingPolicy
{
    public function inspect(array $options, array $tables): array
    {
        $sequences = [];
        $autoTables = [];
        $blockers = [];
        if ($options['only_soft_deleted']) {
            return ['sequences' => [], 'auto_increment_tables' => [], 'blockers' => []];
        }
        if ($options['reset_sequences']) {
            $query = DocumentSequence::query()->orderBy('branch_id')->orderBy('document_type');
            if ($options['sequence_ids'] !== []) {
                $query->whereIn('id', $options['sequence_ids']);
            }
            foreach ($query->get() as $sequence) {
                if (! in_array($sequence->document_type, ['sale_note', 'quotation'], true) || ! in_array('sales', $tables, true)) {
                    if ($options['sequence_ids'] !== []) {
                        $blockers[] = 'El correlativo solicitado queda fuera del alcance; se conservan sus documentos, incluidos los eliminados lógicamente.';
                    }

                    continue;
                }
                $sequences[] = ['id' => $sequence->id, 'branch_id' => $sequence->branch_id,
                    'document_type' => $sequence->document_type, 'prefix' => $sequence->prefix,
                    'before' => $sequence->next_number, 'after' => 1];
            }
            if ($options['sequence_ids'] !== [] && count($sequences) !== count($options['sequence_ids'])) {
                $blockers[] = 'Algún correlativo no existe o no pertenece a los documentos incluidos en la purga.';
            }
        }
        if ($options['reset_auto_increment']) {
            if ($options['scope'] !== 'full_reset') {
                $blockers[] = 'Los identificadores solo pueden reiniciarse durante un reinicio completo.';
            } else {
                foreach ($tables as $table) {
                    if (collect(Schema::getColumns($table))->contains(fn ($column) => $column['auto_increment'])) {
                        $autoTables[] = $table;
                    }
                }
                foreach (DB::table('audits')->distinct()->pluck('auditable_type') as $type) {
                    $class = Relation::getMorphedModel($type) ?? $type;
                    if (! is_string($class) || ! str_starts_with($class, 'App\\') || ! is_subclass_of($class, Model::class) || in_array((new $class)->getTable(), $autoTables, true)) {
                        $blockers[] = 'La auditoría protegida conserva referencias históricas. Reutilizar esos identificadores confundiría su trazabilidad.';
                        break;
                    }
                }
                if (DB::table('operational_traces')->whereNotNull('entity_id')->exists()) {
                    // Las trazas usan nombres de entidad libres; no se presume que carezcan de referencias.
                    $blockers[] = 'Las trazas protegidas conservan identificadores de entidades. No se permite reutilizarlos mientras exista esa historia.';
                }
            }
        }

        return ['sequences' => $sequences, 'auto_increment_tables' => $autoTables, 'blockers' => array_values(array_unique($blockers))];
    }

    public function applySequences(array $sequences): void
    {
        foreach ($sequences as $entry) {
            $sequence = DocumentSequence::query()->lockForUpdate()->findOrFail($entry['id']);
            if (DB::table('sales')->where('branch_id', $sequence->branch_id)->where('document_type', $sequence->document_type)->exists()
                || DB::table('sales')->where('receipt_number', $sequence->preview(1))->exists()) {
                throw ValidationException::withMessages(['reset_sequences' => 'Permanecen documentos que podrían entrar en conflicto, incluidos los conservados en papelera.']);
            }
            $sequence->update(['next_number' => 1]);
        }
    }

    public function resetIdentifiers(array $tables): array
    {
        if ($tables === []) {
            return ['ok' => true, 'errors' => []];
        }
        if (DB::transactionLevel() > 0) {
            return ['ok' => false, 'errors' => ['No se ejecutó DDL porque sigue abierta una transacción.']];
        }
        $errors = [];
        foreach ($tables as $table) {
            try {
                if (DB::table($table)->exists()) {
                    throw new \RuntimeException('La tabla no quedó vacía.');
                }
                DB::statement('ALTER TABLE `'.str_replace('`', '``', $table).'` AUTO_INCREMENT = 1');
            } catch (\Throwable $exception) {
                $errors[$table] = $exception::class;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }
}
