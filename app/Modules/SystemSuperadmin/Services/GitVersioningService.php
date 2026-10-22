<?php

declare(strict_types=1);

namespace App\Modules\SystemSuperadmin\Services;

use App\Support\SystemErrorDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class GitVersioningService
{
    private readonly string $basePath;

    public function __construct(?string $basePath = null, private readonly ?RepositoryDeploymentService $deployment = null)
    {
        $this->basePath = realpath($basePath ?? base_path()) ?: ($basePath ?? base_path());
    }

    public function getRepositoryStatus(): array
    {
        $release = ['version' => config('system_info.version'), 'commit' => config('deployment.commit'), 'built_at' => config('deployment.built_at')];
        $url = config('deployment.pipeline_url');
        $pipeline = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && ! preg_match('/[?&](?:token|password|secret|access_token)=/i', $url) ? $url : null;
        try {
            $this->repository();
            $symbolic = $this->run(['symbolic-ref', '--quiet', '--short', 'HEAD']);
            $detached = ! $symbolic['successful'];
            $branch = $detached ? 'HEAD separado' : $this->getCurrentBranch();

            return ['is_available' => true, 'mode' => 'repository', 'release' => $release, 'pipeline_url' => $pipeline,
                'updates_enabled' => ! $detached && config('maintenance.git_updates_enabled') && $this->deployer()->isConfigured(), 'git_version' => $this->getGitVersion(), 'branch' => $branch, 'detached' => $detached,
                'commit' => $this->getLatestCommit(), 'remote' => $this->getRemoteInfo($branch), 'working_tree' => $this->getWorkingTreeStatus(),
                'history' => $this->getCommitHistory(30), 'references' => $this->references(),
                'deployment_requirements' => [
                    'enabled' => (bool) config('maintenance.git_updates_enabled'),
                    'supervised_restart' => $this->deployer()->isConfigured(),
                    'queue_connection' => config('maintenance.queue_connection'),
                ], 'checked_at' => now()->toIso8601String()];
        } catch (Throwable $exception) {
            return ['is_available' => false, 'mode' => 'image', 'release' => $release, 'pipeline_url' => $pipeline,
                'error' => 'Esta instalación no dispone de un repositorio Git operativo. Actualiza mediante la construcción y sustitución de la imagen.',
                'technical_error' => $this->redact($exception->getMessage())];
        }
    }

    public function isGitAvailable(): bool
    {
        try {
            return $this->run(['--version'])['successful'];
        } catch (Throwable) {
            return false;
        }
    }

    public function getGitVersion(): string
    {
        return trim($this->checked(['--version']));
    }

    public function getCurrentBranch(): string
    {
        $branch = trim($this->checked(['symbolic-ref', '--quiet', '--short', 'HEAD']));
        if ($branch === '' || str_starts_with($branch, '-') || ! $this->run(['check-ref-format', '--branch', $branch])['successful']) {
            throw new RuntimeException('La rama no es válida o HEAD está separado.');
        }

        return $branch;
    }

    public function getLatestCommit(?string $ref = 'HEAD'): ?array
    {
        return $this->parseCommitLine($this->checked(['log', '-1', '--format=%H%x00%h%x00%s%x00%an%x00%ae%x00%cI', $this->resolve($ref ?? 'HEAD'), '--']));
    }

    public function getCommitHistory(int $limit = 15, string $ref = 'HEAD', int $offset = 0): array
    {
        $output = $this->checked(['log', '-'.min(100, max(1, $limit)), '--skip='.max(0, min(100000, $offset)), '--format=%H%x00%h%x00%s%x00%an%x00%ae%x00%cI', $this->resolve($ref), '--']);

        return array_values(array_filter(array_map($this->parseCommitLine(...), explode("\n", rtrim($output, "\r\n")))));
    }

    public function getRemoteInfo(string $branch): array
    {
        $url = $this->run(['remote', 'get-url', 'origin']);
        $counts = $this->run(['rev-list', '--left-right', '--count', 'HEAD...refs/remotes/origin/'.$branch, '--']);
        $parts = preg_split('/\s+/', trim($counts['output']));
        $ahead = $counts['successful'] ? (int) ($parts[0] ?? 0) : 0;
        $behind = $counts['successful'] ? (int) ($parts[1] ?? 0) : 0;
        $safeUrl = $this->redact(trim($url['output']));
        $webUrl = preg_replace('/\.git$/', '', preg_replace('/^git@github\.com:/', 'https://github.com/', $safeUrl));
        $webUrl = preg_replace('~[?#].*$~', '', preg_replace('~^(https://)[^/@]+@~', '$1', $webUrl));

        return ['name' => 'origin', 'url' => $safeUrl, 'web_url' => str_starts_with($webUrl, 'https://') ? $webUrl : null,
            'branch' => $branch, 'ahead' => $ahead, 'behind' => $behind,
            'sync_status' => ! $counts['successful'] ? 'untracked' : ($ahead && $behind ? 'diverged' : ($ahead ? 'ahead' : ($behind ? 'behind' : 'synced'))),
            'latest_remote_commit' => $counts['successful'] ? $this->getLatestCommit('refs/remotes/origin/'.$branch) : null];
    }

    public function getWorkingTreeStatus(): array
    {
        $records = explode("\0", $this->checked(['status', '--porcelain=v1', '-z', '--untracked-files=all']));
        $files = [];
        for ($index = 0; $index < count($records); $index++) {
            if (strlen($records[$index]) < 4) {
                continue;
            }
            // No recortar: los espacios iniciales pertenecen al estado. -z conserva rutas literales.
            $code = substr($records[$index], 0, 2);
            $path = substr($records[$index], 3);
            $original = strpbrk($code, 'RC') !== false ? ($records[++$index] ?? null) : null;
            $files[] = ['path' => $path, 'original_path' => $original, 'code' => $code,
                'status' => match (true) {
                    $code === '??' => 'No rastreado', str_contains($code, 'R') => 'Renombrado', str_contains($code, 'D') => 'Eliminado', str_contains($code, 'A') => 'Agregado', default => 'Modificado'
                },
                'can_commit' => $this->committable($path) && ($original === null || $this->committable($original))];
        }

        return ['is_clean' => $files === [], 'total_changed_files' => count($files), 'files' => array_slice($files, 0, 100), 'all_files' => $files];
    }

    public function fetchRemote(): array
    {
        return $this->mutate(function (): array {
            $this->remote();
            $this->checked(['fetch', '--prune', '--tags', 'origin']);

            return $this->success('Referencias remotas descargadas.');
        });
    }

    public function commit(string $message, bool $push = false, ?array $selected = null): array
    {
        return $this->mutate(function () use ($message, $push, $selected): array {
            if (mb_strlen(trim($message)) < 3 || str_contains($message, "\0")) {
                throw new RuntimeException('Mensaje de commit inválido.');
            }
            $branch = $this->getCurrentBranch();
            if ($push) {
                $this->remote();
            }
            $this->assertSafeIndex();
            $paths = [];
            foreach ($this->getWorkingTreeStatus()['all_files'] as $file) {
                if ($file['can_commit'] && ($selected === null || in_array($file['path'], $selected, true))) {
                    $paths[] = $file['path'];
                    if ($file['original_path']) {
                        $paths[] = $file['original_path'];
                    }
                }
            }
            if ($selected !== null && (count($selected) !== count(array_unique($selected)) || array_diff($selected, $paths) !== [])) {
                throw new RuntimeException('La selección contiene archivos no permitidos o que ya no tienen cambios.');
            }
            if ($paths === []) {
                throw new RuntimeException('No hay cambios de código permitidos para confirmar.');
            }
            foreach (array_chunk(array_unique($paths), 80) as $chunk) {
                $this->checked(['add', '-A', '--', ...$chunk]);
            }
            $this->assertSafeIndex();
            // --only evita incluir cambios preparados ajenos a la selección del usuario.
            $this->checked(['commit', '-m', trim($message), '--only', '--', ...array_unique($paths)]);
            $hash = $this->resolve('HEAD');
            if ($push) {
                try {
                    $this->checked(['push', 'origin', 'HEAD:refs/heads/'.$branch]);
                } catch (Throwable $exception) {
                    return ['successful' => false, 'committed' => true, 'pushed' => false, 'commit' => $hash,
                        'message' => 'El commit local existe, pero no se pudo publicar.', 'output' => '', 'error' => $this->redact($exception->getMessage())];
                }
            }

            return [...$this->success('Commit de código creado.'), 'commit' => $hash, 'pushed' => $push];
        });
    }

    public function pull(bool $runMigrations = true): array
    {
        return $this->update('pull');
    }

    public function references(): array
    {
        $output = $this->checked(['for-each-ref', '--sort=-committerdate', '--format=%(refname)%00%(objectname)%00%(subject)', 'refs/heads/', 'refs/remotes/origin/', 'refs/tags/']);
        $references = [];
        foreach (explode("\n", trim($output)) as $line) {
            $parts = explode("\0", $line, 3);
            if (count($parts) !== 3 || $parts[0] === 'refs/remotes/origin/HEAD') {
                continue;
            }
            $type = str_starts_with($parts[0], 'refs/heads/') ? 'local' : (str_starts_with($parts[0], 'refs/tags/') ? 'tag' : 'remote');
            $references[] = ['ref' => $parts[0], 'name' => preg_replace('~^refs/(?:heads/|tags/|remotes/)~', '', $parts[0]), 'type' => $type, 'hash' => $parts[1], 'message' => $parts[2]];
        }

        return $references;
    }

    public function inspect(string $ref, int $offset = 0): array
    {
        $this->repository();
        $hash = $this->resolve($ref);

        return ['commit' => $this->getLatestCommit($hash), 'history' => $this->getCommitHistory(30, $hash, $offset),
            'offset' => $offset, 'summary' => $this->redact($this->checked(['show', '--format=', '--stat', $hash, '--'])),
            'comparison' => $this->redact($this->checked(['diff', '--stat', 'HEAD', $hash, '--']))];
    }

    public function push(): array
    {
        return $this->mutate(function (): array {
            $branch = $this->getCurrentBranch();
            $this->remote();
            $this->checked(['push', 'origin', 'HEAD:refs/heads/'.$branch]);

            return [...$this->success('Commits locales publicados en origin/'.$branch.'.'), 'commit' => $this->resolve('HEAD')];
        });
    }

    public function previewDeployment(string $operation, string $reference = ''): array
    {
        $this->repository();
        if (! in_array($operation, ['pull', 'reset', 'previous', 'revision', 'branch'], true)) {
            throw new RuntimeException('Operación de actualización inválida.');
        }
        $branch = $this->getCurrentBranch();
        $targetBranch = null;
        if ($operation === 'branch') {
            $match = collect($this->references())->first(fn (array $item): bool => $item['ref'] === $reference && in_array($item['type'], ['local', 'remote'], true));
            if ($match === null) {
                throw new RuntimeException('Selecciona una rama local o remota existente.');
            }
            $targetBranch = preg_replace('~^refs/(?:heads/|remotes/origin/)~', '', $reference);
            $local = 'refs/heads/'.$targetBranch;
            if ($match['type'] === 'remote' && $this->run(['show-ref', '--verify', '--quiet', $local])['successful']) {
                throw new RuntimeException('Esa rama ya existe localmente. Selecciona su referencia local y después actualízala desde Git.');
            }
            if (! $this->getWorkingTreeStatus()['is_clean']) {
                throw new RuntimeException('Confirma o guarda los cambios locales antes de cambiar de rama.');
            }
        } elseif (in_array($operation, ['pull', 'reset'], true)) {
            $reference = 'refs/remotes/origin/'.$branch;
        } elseif ($operation === 'previous') {
            $reference = 'HEAD~1';
        }
        $target = $this->resolve($reference);
        $before = $this->resolve('HEAD');
        if ($operation === 'pull' && (! $this->getWorkingTreeStatus()['is_clean'] || ! $this->run(['merge-base', '--is-ancestor', $before, $target])['successful'])) {
            throw new RuntimeException('La actualización requiere un árbol limpio y avance directo. Confirma los cambios o resuelve la divergencia.');
        }

        return ['operation' => $operation, 'reference' => $reference, 'target_branch' => $targetBranch, 'source_branch' => $branch,
            'before_commit' => $before, 'target_commit' => $target, 'fingerprint' => $this->fingerprint(),
            'target' => $this->getLatestCommit($target), 'changed_files' => $this->redact($this->checked(['diff', '--stat', $before, $target, '--'])),
            'local_changes' => $this->getWorkingTreeStatus()['total_changed_files'],
            'database_policy' => 'Se respaldará la base antes del despliegue. Se aplicarán migraciones pendientes; volver a un commit no revierte la base de datos.',
            'expires_at' => now()->addMinutes(10)->timestamp];
    }

    public function deployPlan(array $plan): array
    {
        if (! in_array($plan['operation'] ?? null, ['pull', 'reset', 'previous', 'revision', 'branch'], true) || ($plan['expires_at'] ?? 0) < now()->timestamp) {
            return ['successful' => false, 'message' => 'La vista previa venció o no es válida. Genera otra.', 'error' => 'GIT_PREVIEW_EXPIRED'];
        }

        return $this->update($plan['operation'], $plan);
    }

    private function fingerprint(): string
    {
        $context = hash_init('sha256');
        hash_update($context, $this->checked(['diff', '--binary', 'HEAD', '--']));
        hash_update($context, $this->checked(['diff', '--binary', '--cached', '--']));
        foreach ($this->getWorkingTreeStatus()['all_files'] as $file) {
            hash_update($context, json_encode($file, JSON_THROW_ON_ERROR));
            $path = $this->basePath.DIRECTORY_SEPARATOR.$file['path'];
            if ($file['can_commit'] && is_file($path) && ! is_link($path)) {
                hash_update($context, hash_file('sha256', $path));
            }
        }

        return hash_final($context);
    }

    public function resetToRemote(): array
    {
        return $this->update('reset');
    }

    public function revertPrevious(): array
    {
        return $this->update('previous');
    }

    private function update(string $operation, ?array $plan = null): array
    {
        return $this->mutate(function () use ($operation, $plan): array {
            if (! config('maintenance.git_updates_enabled')) {
                throw new RuntimeException('La actualización del repositorio permanece deshabilitada hasta aprobar sus verificaciones.');
            }
            $branch = $this->getCurrentBranch();
            $before = $this->resolve('HEAD');
            if ($plan !== null) {
                if ($before !== $plan['before_commit'] || ! hash_equals($plan['fingerprint'], $this->fingerprint()) || $branch !== $plan['source_branch']) {
                    throw new RuntimeException('El repositorio cambió desde la vista previa. Genera una nueva antes de continuar.');
                }
                $target = $this->resolve($plan['target_commit']);
                if ($operation === 'branch' && ($target !== $this->resolve($plan['reference']) || ! $this->getWorkingTreeStatus()['is_clean'])) {
                    throw new RuntimeException('La rama cambió o hay cambios locales pendientes. Revisa otra vez la vista previa.');
                }
            } elseif ($operation === 'previous') {
                $target = $this->resolve('HEAD~1');
            } else {
                $this->remote();
                // Si fetch falla, no se descarta ningún cambio local.
                $this->checked(['fetch', '--prune', '--no-tags', 'origin']);
                $target = $this->resolve('refs/remotes/origin/'.$branch);
            }
            if ($operation === 'pull' && (! $this->getWorkingTreeStatus()['is_clean'] || ! $this->run(['merge-base', '--is-ancestor', $before, $target])['successful'])) {
                throw new RuntimeException('Se requiere un árbol limpio y avance directo. Resuelve los cambios o la divergencia.');
            }
            foreach ([$before, $target] as $revision) {
                foreach ($this->paths($this->checked(['ls-tree', '-r', '--name-only', '-z', $revision])) as $path) {
                    if ($this->operational($path)) {
                        throw new RuntimeException('Hay archivos operativos rastreados. Sepáralos antes de restablecer código.');
                    }
                }
            }
            $this->deployer()->assertReady($this->basePath);
            $targetPaths = $this->paths($this->checked(['ls-tree', '-r', '--name-only', '-z', $target]));
            foreach ($this->paths($this->checked(['ls-files', '--others', '--exclude-standard', '-z'])) as $path) {
                if ($this->committable($path)) {
                    continue;
                }
                foreach ($targetPaths as $tracked) {
                    if ($tracked === $path || str_starts_with($tracked, $path.'/') || str_starts_with($path, $tracked.'/')) {
                        throw new RuntimeException('El destino colisiona con un archivo local excluido. Consérvalo fuera del árbol antes de actualizar.');
                    }
                }
            }
            $recovery = $this->createRecovery($before, $target, $branch);

            return $this->deployer()->coordinate(function () use ($before, $target, $operation, $recovery, $plan): array {
                $changed = false;
                try {
                    $this->deployer()->prepare($this->basePath);
                    // El respaldo puede tardar: se vuelve a comprobar el código justo antes de sustituirlo.
                    if ($plan !== null && ($this->resolve('HEAD') !== $before || $this->getCurrentBranch() !== $plan['source_branch'] || ! hash_equals($plan['fingerprint'], $this->fingerprint()))) {
                        throw new RuntimeException('El código cambió durante la preparación. Se conservó el respaldo y no se sustituyó el código.');
                    }
                    if ($operation === 'branch') {
                        $name = $plan['target_branch'];
                        $exists = $this->run(['show-ref', '--verify', '--quiet', 'refs/heads/'.$name])['successful'];
                        $this->checked($exists ? ['switch', $name] : ['switch', '--create', $name, '--track', 'origin/'.$name]);
                        $changed = true;
                        if ($this->resolve('HEAD') !== $target) {
                            throw new RuntimeException('La rama de destino ya no coincide con la revisión confirmada.');
                        }
                    } else {
                        $this->checked($operation === 'pull' ? ['merge', '--ff-only', $target] : ['reset', '--hard', $target]);
                    }
                    $changed = true;
                    // Solo se limpia código nuevo respaldado; se conservan datos operativos e ignorados.
                    $untracked = array_values(array_filter($this->paths($this->checked(['ls-files', '--others', '--exclude-standard', '-z'])), $this->committable(...)));
                    foreach ($untracked as $path) {
                        $expected = $recovery['files']['untracked/'.$path] ?? null;
                        if (! is_string($expected) || ! hash_equals($expected, hash_file('sha256', $this->basePath.DIRECTORY_SEPARATOR.$path))) {
                            throw new RuntimeException('Cambió un archivo nuevo después de respaldarlo. Se detuvo la limpieza.');
                        }
                    }
                    foreach (array_chunk($untracked, 80) as $chunk) {
                        $this->checked(['clean', '-f', '--', ...$chunk]);
                    }
                    if (array_filter($this->paths($this->checked(['ls-files', '--others', '--exclude-standard', '-z'])), $this->committable(...)) !== []) {
                        throw new RuntimeException('La limpieza dejó archivos de código pendientes.');
                    }
                    $stages = $this->deployer()->complete($this->basePath);

                    return [...$this->success('Código, dependencias, frontend, migraciones, cachés y procesos actualizados.'),
                        'before_commit' => $before, 'target_commit' => $target, 'recovery' => $recovery, 'stages' => $stages];
                } catch (Throwable $exception) {
                    return ['successful' => false, 'code_changed' => $changed, 'before_commit' => $before, 'target_commit' => $target,
                        'recovery' => $recovery, 'message' => 'La actualización no terminó. Revisa la etapa fallida y el punto de recuperación antes de reabrir el servicio.',
                        'output' => '', 'error' => $this->redact($exception->getMessage())];
                }
            });
        });
    }

    private function repository(): void
    {
        if (realpath(trim($this->checked(['rev-parse', '--show-toplevel']))) !== realpath($this->basePath) || ! $this->isGitAvailable()) {
            throw new RuntimeException('La ruta no es la raíz del repositorio esperado.');
        }
    }

    private function remote(): void
    {
        $url = trim($this->checked(['remote', 'get-url', 'origin']));
        if ($url === '' || str_starts_with($url, '-') || str_contains($url, '::') || preg_match('/[\x00-\x20]/', $url)) {
            throw new RuntimeException('El remoto origin no es válido.');
        }
    }

    private function resolve(string $ref): string
    {
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0")) {
            throw new RuntimeException('Referencia Git inválida.');
        }
        $hash = trim($this->checked(['rev-parse', '--verify', '--end-of-options', $ref.'^{commit}']));
        if (! preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $hash)) {
            throw new RuntimeException('No se pudo resolver el commit.');
        }

        return $hash;
    }

    private function assertSafeIndex(): void
    {
        foreach ($this->paths($this->checked(['diff', '--cached', '--name-only', '-z'])) as $path) {
            if (! $this->committable($path)) {
                throw new RuntimeException('El índice contiene archivos operativos o secretos. Retíralos antes de confirmar.');
            }
        }
    }

    private function mutate(\Closure $action): array
    {
        $lock = null;
        try {
            $this->repository();
            $directory = storage_path('app/private/git-maintenance');
            File::ensureDirectoryExists($directory, 0700);
            $lock = fopen($directory.'/'.hash('sha256', $this->basePath).'.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Ya hay otra operación Git en curso.');
            }

            return $action();
        } catch (Throwable $exception) {
            return ['successful' => false, 'message' => 'La operación Git no se completó.', 'output' => '', 'error' => $this->redact($exception->getMessage())];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function createRecovery(string $before, string $target, string $branch): array
    {
        $root = storage_path('app/private/git-maintenance/recovery-'.bin2hex(random_bytes(16)));
        File::makeDirectory($root, 0700, true);
        $manifest = ['repository' => $this->basePath, 'before_commit' => $before, 'target_commit' => $target, 'branch' => $branch, 'created_at' => now()->toIso8601String(), 'files' => []];
        $this->checked(['bundle', 'create', $root.'/repository.bundle', '--all']);
        $this->checked(['bundle', 'verify', $root.'/repository.bundle']);
        $archive = new ZipArchive;
        if ($archive->open($root.'/changes.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('No se pudo crear la recuperación.');
        }
        try {
            foreach (['working-tree.patch' => $this->checked(['diff', '--binary', 'HEAD', '--']), 'index.patch' => $this->checked(['diff', '--binary', '--cached', '--'])] as $path => $content) {
                if (! $archive->addFromString($path, $content)) {
                    throw new RuntimeException('No se pudo respaldar el parche.');
                }
                $manifest['files'][$path] = hash('sha256', $content);
            }
            foreach ($this->paths($this->checked(['ls-files', '--others', '--exclude-standard', '-z'])) as $path) {
                if (! $this->committable($path)) {
                    continue;
                }
                $absolute = $this->basePath.DIRECTORY_SEPARATOR.$path;
                if (is_link($absolute) || ! is_file($absolute) || ! str_starts_with(realpath($absolute) ?: '', $this->basePath.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('No se puede respaldar un archivo nuevo de forma segura.');
                }
                if (! $archive->addFile($absolute, 'untracked/'.$path)) {
                    throw new RuntimeException('No se pudo respaldar un archivo nuevo.');
                }
                $manifest['files']['untracked/'.$path] = hash_file('sha256', $absolute);
            }
        } finally {
            if (! $archive->close()) {
                throw new RuntimeException('No se pudo finalizar la recuperación.');
            }
        }
        $check = new ZipArchive;
        if ($check->open($root.'/changes.zip') !== true) {
            throw new RuntimeException('No se pudo abrir la recuperación.');
        }
        try {
            foreach ($manifest['files'] as $path => $hash) {
                $stream = $check->getStream($path);
                if ($stream === false) {
                    throw new RuntimeException('La recuperación está incompleta.');
                }
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);
                if (! hash_equals($hash, hash_final($context))) {
                    throw new RuntimeException('La recuperación no coincide con el original.');
                }
            }
        } finally {
            $check->close();
        }
        $manifest['bundle_sha256'] = hash_file('sha256', $root.'/repository.bundle');
        $manifest['archive_sha256'] = hash_file('sha256', $root.'/changes.zip');
        File::put($root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (json_decode(File::get($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR) !== $manifest) {
            throw new RuntimeException('No se pudo registrar el destino y la recuperación.');
        }

        return ['path' => $root, ...$manifest];
    }

    private function operational(string $path): bool
    {
        $path = str_replace('\\', '/', strtolower($path));
        if (basename($path) === '.gitignore' || $path === 'docker/testing/init.sql' || in_array($path, ['.env.example', '.env.production.example', '.env.docker.local.example', '.env.render.example'], true)) {
            return false;
        }

        return preg_match('~(^|/)(?:\.env(?:\..*)?|\.aws|\.ssh|\.venv|venv|__pycache__|secrets?|credentials?|service[-_]account|backups?|vendor|node_modules)([./]|$)|^(?:storage|bootstrap/cache|public/(?:storage|uploads))/|\.(?:pem|key|p12|pfx|sqlite|sqlite3|dump|sql|bak|log)$~', $path) === 1;
    }

    private function committable(string $path): bool
    {
        if ($this->operational($path) || preg_match('~(^|/)(?:\.\.?|\.git)(/|$)|[\x00-\x1f]~', $path)) {
            return false;
        }
        $rootFiles = ['Dockerfile', 'artisan', '.gitignore', '.gitattributes', '.dockerignore', '.editorconfig', '.env.example', '.env.production.example', '.env.docker.local.example', '.env.render.example', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'README.md', 'AGENTS.md', 'DEPLOY_RENDER_TIDB.md', 'render.yaml', 'playwright.config.js', 'vite.config.js', 'tailwind.config.js', 'postcss.config.js', 'jsconfig.json', 'docker/testing/init.sql'];
        if (in_array($path, $rootFiles, true)) {
            return true;
        }
        if (! preg_match('~^(?:app|bootstrap|config|database/(?:migrations|seeders|factories)|docker|docs|lang|ai_service|resources|routes|scripts|tests|public)/|^docker-compose[^/]*\.ya?ml$~', $path)) {
            return false;
        }

        return preg_match('~\.(?:php|py|toml|js|mjs|cjs|jsx|ts|tsx|json|css|scss|html|vue|md|yml|yaml|xml|lock|sh|ps1|svg|png|jpg|jpeg|webp|ico|woff2?|ttf|txt)$~i', $path) === 1
            || in_array(basename($path), ['Dockerfile', 'artisan', '.gitignore', '.dockerignore', '.editorconfig', '.env.example'], true);
    }

    private function paths(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), fn ($path) => $path !== ''));
    }

    private function parseCommitLine(string $line): ?array
    {
        $parts = explode("\0", rtrim($line, "\r\n"));
        if (count($parts) !== 6) {
            return null;
        }
        $date = CarbonImmutable::parse($parts[5]);

        return ['hash' => $parts[0], 'short_hash' => $parts[1], 'message' => $parts[2], 'author' => $parts[3], 'email' => $parts[4],
            'date' => $date->format('d/m/Y H:i:s'), 'date_iso' => $date->toIso8601String(), 'relative_date' => $date->diffForHumans()];
    }

    private function success(string $message): array
    {
        return ['successful' => true, 'message' => $message, 'output' => '', 'error' => ''];
    }

    private function deployer(): RepositoryDeploymentService
    {
        return $this->deployment ?? app(RepositoryDeploymentService::class);
    }

    private function checked(array $arguments): string
    {
        $result = $this->run($arguments);
        if (! $result['successful']) {
            throw new RuntimeException('Falló Git ('.$arguments[0].', código '.$result['exit_code'].'): '.$this->redact($result['error'] ?: $result['output']));
        }

        return $result['output'];
    }

    protected function run(array $arguments): array
    {
        // La confianza se limita a la raíz configurada y no modifica el Git global del servidor.
        $process = new Process(['git', '-c', 'safe.directory='.$this->basePath, '-c', 'protocol.ext.allow=never', ...$arguments], $this->basePath, ['GIT_TERMINAL_PROMPT' => '0', 'GCM_INTERACTIVE' => 'never', 'LC_ALL' => 'C.UTF-8']);
        $process->setTimeout(120);
        $process->run();

        return ['successful' => $process->isSuccessful(), 'exit_code' => $process->getExitCode(), 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()];
    }

    private function redact(string $text): string
    {
        return app(SystemErrorDetails::class)->sanitize(preg_replace('~([?&](?:token|password|secret|access_token)=)[^&\s]+~i', '$1[oculto]', preg_replace('~([a-z][a-z0-9+.-]*://)[^/\s@]+@~i', '$1[credencial]@', $text)));
    }
}
