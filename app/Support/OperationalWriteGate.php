<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OperationalWriteGate
{
    private int $depth = 0;

    private int $exclusiveDepth = 0;

    private ?Connection $sharedConnection = null;

    public function shared(Connection $connection): void
    {
        if (! $connection->table('maintenance_barriers')->where('name', 'database-purge')->sharedLock()->first()) {
            throw new RuntimeException('Falta la barrera de mantenimiento. Ejecuta las migraciones antes de operar.');
        }
    }

    public function exclusive(Closure $operation): mixed
    {
        if ($this->exclusiveDepth > 0) {
            return $operation();
        }
        abort_if($this->depth > 0, 423, 'La purga no puede iniciarse dentro de otra operación de escritura.');
        // Una conexión separada mantiene la barrera mientras el respaldo se guarda fuera del borrado.
        config(['database.connections.maintenance_gate' => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge('maintenance_gate');
        $gate = DB::connection('maintenance_gate');
        // Las transacciones de coordinación no deben capturar callbacks del negocio ni de la cola.
        $gate->unsetTransactionManager();
        if ($gate->getDriverName() !== 'mysql') {
            throw new RuntimeException('La purga validada requiere MySQL o MariaDB.');
        }
        $name = 'calmina:purge:'.sha1($gate->getDatabaseName());
        $acquired = (int) $gate->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired;
        if ($acquired !== 1) {
            DB::purge('maintenance_gate');
        }
        abort_unless($acquired === 1, 423, 'Ya existe una purga en ejecución.');

        try {
            $gate->beginTransaction();
            if (! $gate->table('maintenance_barriers')->where('name', 'database-purge')->lockForUpdate()->first()) {
                throw new RuntimeException('Falta la barrera de mantenimiento.');
            }

            $this->exclusiveDepth++;
            try {
                return $operation();
            } finally {
                $this->exclusiveDepth--;
            }
        } finally {
            if ($gate->transactionLevel() > 0) {
                $gate->rollBack();
            }
            $gate->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            DB::purge('maintenance_gate');
        }
    }

    public function begin(): void
    {
        if ($this->depth++ > 0) {
            return;
        }
        try {
            config(['database.connections.operational_gate' => config('database.connections.'.DB::getDefaultConnection())]);
            DB::purge('operational_gate');
            $this->sharedConnection = DB::connection('operational_gate');
            $this->sharedConnection->unsetTransactionManager();
            $this->sharedConnection->beginTransaction();
            $this->shared($this->sharedConnection);
        } catch (\Throwable $exception) {
            $this->finish();
            throw $exception;
        }
    }

    public function finish(): void
    {
        if ($this->depth === 0) {
            return;
        }
        if (--$this->depth > 0) {
            return;
        }
        // La barrera no envuelve las transacciones del negocio ni las migraciones con DDL.
        if ($this->sharedConnection?->transactionLevel() > 0) {
            $this->sharedConnection->rollBack();
        }
        DB::purge('operational_gate');
        $this->sharedConnection = null;
    }
}
