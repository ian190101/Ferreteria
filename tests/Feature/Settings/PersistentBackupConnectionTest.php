<?php

declare(strict_types=1);

use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Support\OperationalWriteGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\MaintenanceScenario;

final class PersistentBackupConnectionTest extends CommittedDatabaseTestCase
{
    #[DataProvider('coordinationModes')]
    public function test_el_respaldo_no_comparte_la_transaccion_de_la_barrera_con_conexiones_persistentes(string $mode): void
    {
        Storage::fake('local');
        $user = MaintenanceScenario::user();
        $name = DB::getDefaultConnection();
        $original = config('database.connections.'.$name);
        $persistent = $original;
        $persistent['options'][PDO::ATTR_PERSISTENT] = true;
        config(['database.connections.'.$name => $persistent]);
        DB::purge($name);
        $connection = DB::connection();
        $sourceId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $gate = app(OperationalWriteGate::class);

        try {
            $backupAction = function () use ($mode, $user, $connection, $sourceId): void {
                $backup = app(MaintenanceBackupService::class)->generate('sql', $user);
                $gateName = $mode === 'exclusive' ? 'maintenance_gate' : 'operational_gate';
                $gateConnection = DB::connection($gateName);

                $this->assertNotSame($sourceId, (int) $gateConnection->selectOne('SELECT CONNECTION_ID() AS id')->id);
                $this->assertFalse($connection->getPdo()->inTransaction());
                $this->assertTrue($gateConnection->getPdo()->inTransaction());
                $this->assertTrue(app(MaintenanceBackupService::class)->verifyReadable($backup)['ok']);
                $this->assertSame(1, $backup->metadata['manifest']['branches']['rows']);
            };

            if ($mode === 'exclusive') {
                $gate->exclusive($backupAction);
            } else {
                $gate->begin();
                try {
                    $backupAction();
                } finally {
                    $gate->finish();
                }
            }
        } finally {
            DB::purge($name);
            config(['database.connections.'.$name => $original]);
        }
    }

    public static function coordinationModes(): array
    {
        return ['respaldo previo a actualizar' => ['exclusive'], 'respaldo programado' => ['shared']];
    }
}
