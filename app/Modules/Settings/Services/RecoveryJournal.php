<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class RecoveryJournal
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        // El progreso debe sobrevivir al reemplazo de las tablas de la propia base restaurada.
        $this->directory = $directory ?? storage_path('app/private/maintenance-recovery');
    }

    public function create(int $userId, int $backupId, string $database): array
    {
        File::ensureDirectoryExists($this->directory, 0700);
        $entry = ['id' => (string) Str::orderedUuid(), 'user_id' => $userId, 'backup_id' => $backupId,
            'source_database' => $database, 'status' => 'pending', 'stage' => 'queued', 'result' => null,
            'message' => 'Restauración registrada; todavía no ha terminado.',
            'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString()];
        $this->write($entry['id'], $entry);

        return $entry;
    }

    public function read(string $id): array
    {
        $entry = json_decode(File::get($this->path($id)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($entry) || ($entry['id'] ?? null) !== $id) {
            throw new RuntimeException('El registro de recuperación no es válido.');
        }

        return $entry;
    }

    public function claim(string $id): bool
    {
        return $this->mutate($id, function (array $entry): ?array {
            return $entry['status'] !== 'pending' ? null : [...$entry, 'status' => 'running', 'stage' => 'validating'];
        });
    }

    public function update(string $id, array $changes): void
    {
        $allowed = array_intersect_key($changes, array_flip(['status', 'stage', 'message', 'result']));
        $this->mutate($id, fn (array $entry) => [...$entry, ...$allowed]);
    }

    public function recent(int $limit = 10): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }
        $files = glob($this->directory.'/*.json') ?: [];
        $limit = min(100, max(1, $limit));
        $entries = [];
        foreach ($files as $file) {
            try {
                $entry = $this->read(basename($file, '.json'));
                // Los UUID aleatorios no indican antigüedad; se conserva solo el grupo más reciente.
                $entries[] = ['entry' => $entry, 'time' => (new \DateTimeImmutable($entry['created_at']))->format('U.u')];
                usort($entries, fn (array $a, array $b): int => ($b['time'] <=> $a['time']) ?: strcmp($b['entry']['id'], $a['entry']['id']));
                if (count($entries) > $limit) {
                    array_pop($entries);
                }
            } catch (\Throwable) { /* Un archivo incompleto no debe ocultar los demás registros. */
            }
        }

        return array_column($entries, 'entry');
    }

    public function cancelOtherPending(string $currentId, string $database): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if ($id === $currentId) {
                continue;
            }
            $this->mutate($id, fn (array $entry) => $entry['source_database'] === $database && $entry['status'] === 'pending'
                ? [...$entry, 'status' => 'failed', 'stage' => 'cancelled_by_restore', 'message' => 'Solicitud cancelada tras restaurar la base. Requiere una nueva revisión.'] : null);
        }
    }

    private function mutate(string $id, \Closure $change): bool
    {
        $lock = fopen($this->path($id).'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('No se pudo bloquear el registro de recuperación.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el registro de recuperación.');
            }
            $entry = $change($this->read($id));
            if ($entry === null) {
                return false;
            }
            $this->write($id, [...$entry, 'updated_at' => now()->toISOString()]);

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function write(string $id, array $entry): void
    {
        $path = $this->path($id);
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('No se pudo conservar el progreso de recuperación.');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('No se pudo confirmar el progreso de recuperación.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Identificador de recuperación inválido.');
        }

        return $this->directory.'/'.$id.'.json';
    }
}
