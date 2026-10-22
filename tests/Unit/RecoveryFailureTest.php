<?php

declare(strict_types=1);

use App\Jobs\RestoreMaintenanceBackupJob;
use App\Modules\Settings\Services\RecoveryJournal;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RecoveryFailureTest extends TestCase
{
    #[DataProvider('stages')]
    public function test_un_fallo_solo_cierra_el_servicio_si_ya_pudo_cambiar_datos(string $stage, bool $changed): void
    {
        $root = storage_path('qa/recovery-failure-'.bin2hex(random_bytes(8)));
        $journal = new RecoveryJournal($root);
        app()->instance(RecoveryJournal::class, $journal);
        $mode = $this->mock(MaintenanceMode::class);
        if ($changed) {
            $mode->shouldReceive('active')->once()->andReturnFalse();
            $mode->shouldReceive('activate')->once()->with(Mockery::on(fn (array $payload): bool => $payload['status'] === 503 && $payload['retry'] === 60));
        } else {
            $mode->shouldNotReceive('activate');
        }
        try {
            $entry = $journal->create(1, 2, 'fabrica_calmina_aroma_testing');
            $journal->update($entry['id'], ['stage' => $stage]);
            (new RestoreMaintenanceBackupJob($entry['id']))->failed(new RuntimeException('Interrupción de ensayo'));
            $result = $journal->read($entry['id']);
            $this->assertSame('failed', $result['status']);
            $this->assertStringContainsString($changed ? 'después de iniciar' : 'No se inició', $result['message']);
        } finally {
            $resolved = realpath($root);
            if ($resolved && str_starts_with($resolved, realpath(storage_path('qa')).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }

    public static function stages(): array
    {
        return [
            'verificacion previa' => ['verifying_recovery', false],
            'sustitucion iniciada' => ['restoring', true],
            'cotejo de datos' => ['verifying_source', true],
            'invalidacion final' => ['finalizing', true],
        ];
    }
}
