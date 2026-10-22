<?php

declare(strict_types=1);

use App\Modules\Settings\Services\RecoveryJournal;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RecoveryJournalTest extends TestCase
{
    public function test_muestra_las_restauraciones_recientes_por_fecha_y_no_por_uuid(): void
    {
        $root = storage_path('qa/journal-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root, 0700);
        $journal = new RecoveryJournal($root);
        try {
            $ids = [];
            for ($index = 0; $index < 12; $index++) {
                $id = sprintf('%08x-0000-4000-8000-000000000000', 100 - $index);
                $ids[] = $id;
                File::put($root.'/'.$id.'.json', json_encode(['id' => $id, 'created_at' => sprintf('2026-10-20T10:00:%02d.000001+00:00', $index)], JSON_THROW_ON_ERROR));
            }
            File::put($root.'/ffffffff-0000-4000-8000-000000000000.json', '{incompleto');
            $this->assertSame(array_reverse(array_slice($ids, -3)), array_column($journal->recent(3), 'id'));
            $this->assertCount(10, $journal->recent());
        } finally {
            $resolved = realpath($root);
            if ($resolved && str_starts_with($resolved, realpath(storage_path('qa')).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }

    public function test_reclama_una_sola_vez_y_conserva_el_progreso_sin_depender_de_tablas(): void
    {
        $root = storage_path('qa/journal-'.bin2hex(random_bytes(8)));
        $journal = new RecoveryJournal($root);
        try {
            $entry = $journal->create(1, 2, 'fabrica_calmina_aroma_testing');
            $other = $journal->create(1, 3, 'fabrica_calmina_aroma_testing');
            $this->assertTrue($journal->claim($entry['id']));
            $this->assertFalse($journal->claim($entry['id']));
            $journal->update($entry['id'], ['stage' => 'restoring', 'source_database' => 'no-permitida']);
            $this->assertSame('fabrica_calmina_aroma_testing', $journal->read($entry['id'])['source_database']);
            $journal->update($entry['id'], ['status' => 'completed', 'stage' => 'finished']);
            $journal->cancelOtherPending($entry['id'], 'fabrica_calmina_aroma_testing');
            $this->assertSame('completed', $journal->read($entry['id'])['status']);
            $this->assertSame('cancelled_by_restore', $journal->read($other['id'])['stage']);
            $this->assertCount(2, $journal->recent());
            $this->expectException(RuntimeException::class);
            $journal->read('../fuera-del-directorio');
        } finally {
            $resolved = realpath($root);
            if ($resolved && str_starts_with($resolved, realpath(storage_path('qa')).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }
}
