<?php

declare(strict_types=1);

use App\Modules\Settings\Services\MaintenanceBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\MaintenanceScenario;

final class LegacyBackupRecoveryTest extends CommittedDatabaseTestCase
{
    public function test_el_respaldo_reconstruye_fechas_cero_heredadas_sin_cambiar_el_modo_de_la_aplicacion(): void
    {
        Storage::fake('local');
        $user = MaintenanceScenario::user();
        $original = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $legacy = implode(',', array_diff(explode(',', $original), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
        try {
            DB::statement('SET SESSION SQL_MODE = '.DB::getPdo()->quote($legacy));
            DB::statement("CREATE TABLE qa_legacy_dates (id BIGINT UNSIGNED PRIMARY KEY, submitted_at TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00')");
            DB::table('qa_legacy_dates')->insert(['id' => 1]);
        } finally {
            DB::statement('SET SESSION SQL_MODE = '.DB::getPdo()->quote($original));
        }

        $service = app(MaintenanceBackupService::class);
        $backup = $service->generate('sql', $user);
        // Reproduce el formato anterior, que guardaba el modo estricto junto a un esquema histórico.
        $sql = Storage::disk('local')->get($backup->path);
        $statement = "SET SQL_MODE='{$original},NO_AUTO_VALUE_ON_ZERO';";
        $sql = preg_replace_callback('/-- calmina-statement-bytes:[0-9]+\nSET SQL_MODE=\'[^\']*\';\n/',
            fn () => '-- calmina-statement-bytes:'.strlen($statement)."\n".$statement."\n", $sql, 1, $replacements);
        $this->assertSame(1, $replacements);
        Storage::disk('local')->put($backup->path, $sql);
        $backup->update(['size_bytes' => strlen($sql), 'metadata' => [...$backup->metadata, 'sha256' => hash('sha256', $sql), 'size_bytes' => strlen($sql)]]);
        $result = $service->verifyRecovery($backup);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $backup->metadata['manifest']['qa_legacy_dates']['rows']);
        $this->assertSame('0000-00-00 00:00:00', DB::table('qa_legacy_dates')->value('submitted_at'));
        $this->assertSame($original, DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
    }
}
