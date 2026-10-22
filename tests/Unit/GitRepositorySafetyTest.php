<?php

declare(strict_types=1);

use App\Modules\SystemSuperadmin\Services\GitVersioningService;
use App\Modules\SystemSuperadmin\Services\RepositoryDeploymentService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class QaRepositoryDeployment extends RepositoryDeploymentService
{
    public bool $fail = false;

    public array $calls = [];

    public ?Closure $afterPrepare = null;

    public function isConfigured(): bool
    {
        return true;
    }

    public function assertReady(string $root): void
    {
        $this->calls[] = 'ready';
    }

    public function coordinate(Closure $action): array
    {
        return $action();
    }

    public function prepare(string $root): void
    {
        $this->calls[] = 'prepared';
        ($this->afterPrepare ?? static fn () => null)();
    }

    public function complete(string $root): array
    {
        $this->calls[] = 'complete';
        if ($this->fail) {
            throw new RuntimeException('Falló la compilación frontend');
        }

        return ['dependencias', 'frontend', 'migraciones', 'cachés', 'reinicio'];
    }
}

final class GitRepositorySafetyTest extends TestCase
{
    private string $root;

    private string $repo;

    private QaRepositoryDeployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('qa/git-'.bin2hex(random_bytes(8)));
        $this->repo = $this->root.'/repositorio con espacios';
        File::makeDirectory($this->repo.'/app', 0700, true);
        $this->git($this->root, ['init', '--bare', $this->root.'/remote.git']);
        $this->git($this->repo, ['init', '--initial-branch=main']);
        $this->git($this->repo, ['config', 'user.name', 'Pruebas QA']);
        $this->git($this->repo, ['config', 'user.email', 'qa@example.test']);
        File::put($this->repo.'/app/code.php', '<?php // inicial');
        File::put($this->repo.'/.gitignore', ".env\nstorage/\n");
        $this->git($this->repo, ['add', '--', 'app/code.php', '.gitignore']);
        $this->git($this->repo, ['commit', '-m', 'Inicial | con separador']);
        $this->git($this->repo, ['remote', 'add', 'origin', $this->root.'/remote.git']);
        $this->git($this->repo, ['push', '-u', 'origin', 'main']);
        $this->deployment = new QaRepositoryDeployment;
    }

    protected function tearDown(): void
    {
        $resolved = realpath($this->root);
        $allowed = realpath(storage_path('qa'));
        if ($resolved && $allowed && str_starts_with($resolved, $allowed.DIRECTORY_SEPARATOR) && str_starts_with(basename($resolved), 'git-')) {
            File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_fetch_fallido_no_descarta_cambios_ni_crea_una_recuperacion_inutil(): void
    {
        File::put($this->repo.'/app/code.php', 'cambio local');
        File::put($this->repo.'/app/nuevo.php', 'nuevo local');
        $before = $this->git($this->repo, ['rev-parse', 'HEAD']);
        $this->git($this->repo, ['remote', 'set-url', 'origin', $this->root.'/no-existe.git']);
        $result = $this->service()->resetToRemote();
        $this->assertFalse($result['successful']);
        $this->assertSame($before, $this->git($this->repo, ['rev-parse', 'HEAD']));
        $this->assertSame('cambio local', File::get($this->repo.'/app/code.php'));
        $this->assertSame('nuevo local', File::get($this->repo.'/app/nuevo.php'));
        $this->assertSame([], $this->deployment->calls);
    }

    public function test_reset_conserva_operativos_y_permite_recuperar_historia_parches_y_archivos_nuevos(): void
    {
        $target = trim($this->git($this->repo, ['rev-parse', 'HEAD']));
        File::put($this->repo.'/app/code.php', 'segundo commit');
        $this->git($this->repo, ['commit', '-am', 'Solo local']);
        $before = trim($this->git($this->repo, ['rev-parse', 'HEAD']));
        File::put($this->repo.'/app/code.php', 'cambio sin confirmar');
        File::put($this->repo.'/app/nuevo.php', 'nuevo sin confirmar');
        File::put($this->repo.'/.env', 'DATO=privado-de-prueba');
        File::makeDirectory($this->repo.'/storage', 0700);
        File::put($this->repo.'/storage/operativo.json', '{"conservar":true}');
        $result = $this->service()->resetToRemote();
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame($target, $result['target_commit']);
        $this->assertSame($before, $result['before_commit']);
        $this->assertSame('<?php // inicial', File::get($this->repo.'/app/code.php'));
        $this->assertFileDoesNotExist($this->repo.'/app/nuevo.php');
        $this->assertSame('DATO=privado-de-prueba', File::get($this->repo.'/.env'));
        $this->assertSame('{"conservar":true}', File::get($this->repo.'/storage/operativo.json'));
        $recovery = $result['recovery'];
        $this->assertSame($recovery['archive_sha256'], hash_file('sha256', $recovery['path'].'/changes.zip'));
        $restore = $this->root.'/recuperacion';
        $this->git($this->root, ['clone', $recovery['path'].'/repository.bundle', $restore]);
        $this->git($restore, ['checkout', '--detach', $before]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($recovery['path'].'/changes.zip') === true);
        File::put($this->root.'/recuperar.patch', $zip->getFromName('working-tree.patch'));
        $this->git($restore, ['apply', $this->root.'/recuperar.patch']);
        File::put($restore.'/app/nuevo.php', $zip->getFromName('untracked/app/nuevo.php'));
        $zip->close();
        $this->assertSame('cambio sin confirmar', File::get($restore.'/app/code.php'));
        $this->assertSame('nuevo sin confirmar', File::get($restore.'/app/nuevo.php'));
        $this->assertSame(['ready', 'prepared', 'complete'], $this->deployment->calls);
    }

    public function test_una_limpieza_fallida_no_se_informa_como_actualizacion_completada(): void
    {
        File::put($this->repo.'/app/nuevo.php', 'conservar en recuperación');
        $service = new class($this->repo, $this->deployment) extends GitVersioningService
        {
            protected function run(array $arguments): array
            {
                return $arguments[0] === 'clean' ? ['successful' => false, 'exit_code' => 1, 'output' => '', 'error' => 'Limpieza bloqueada'] : parent::run($arguments);
            }
        };
        $result = $service->resetToRemote();
        $this->assertFalse($result['successful']);
        $this->assertTrue($result['code_changed']);
        $this->assertFileExists($result['recovery']['path'].'/manifest.json');
        $this->assertFileExists($this->repo.'/app/nuevo.php');
        $this->assertNotContains('complete', $this->deployment->calls);
    }

    public function test_un_fallo_del_pipeline_conserva_destino_y_recuperacion_y_no_informa_exito(): void
    {
        $this->deployment->fail = true;
        $result = $this->service()->resetToRemote();
        $this->assertFalse($result['successful']);
        $this->assertTrue($result['code_changed']);
        $this->assertStringContainsString('compilación', $result['error']);
        $this->assertFileExists($result['recovery']['path'].'/repository.bundle');
    }

    public function test_commit_general_excluye_secretos_y_datos_y_conserva_literalmente_el_mensaje(): void
    {
        File::put($this->repo.'/app/nuevo.php', '<?php // código');
        File::put($this->repo.'/.env', 'TOKEN=solo-prueba');
        File::put($this->repo.'/credentials.json', '{"token":"solo-prueba"}');
        $message = 'Mensaje | autor && echo literal $(sin-ejecutar)';
        $result = $this->service()->commit($message);
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame($message, $this->service()->getLatestCommit()['message']);
        $tracked = $this->git($this->repo, ['ls-files']);
        $this->assertStringNotContainsString('.env', $tracked);
        $this->assertStringNotContainsString('credentials.json', $tracked);
        $this->git($this->repo, ['add', '-f', '--', '.env']);
        $head = $this->git($this->repo, ['rev-parse', 'HEAD']);
        $this->assertFalse($this->service()->commit('No incluir secreto')['successful']);
        $this->assertSame($head, $this->git($this->repo, ['rev-parse', 'HEAD']));
    }

    public function test_preserva_estados_con_espacios_y_renombrados_y_bloquea_operaciones_simultaneas(): void
    {
        $this->git($this->repo, ['mv', 'app/code.php', 'app/archivo nuevo.php']);
        File::append($this->repo.'/app/archivo nuevo.php', ' modificado');
        $file = $this->service()->getWorkingTreeStatus()['files'][0];
        $this->assertSame('RM', $file['code']);
        $this->assertSame('app/archivo nuevo.php', $file['path']);
        $this->assertSame('app/code.php', $file['original_path']);
        $lockRoot = storage_path('app/private/git-maintenance');
        File::ensureDirectoryExists($lockRoot);
        $lock = fopen($lockRoot.'/'.hash('sha256', realpath($this->repo)).'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $result = $this->service()->fetchRemote();
            $this->assertFalse($result['successful']);
            $this->assertStringContainsString('otra operación', $result['error']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_pull_rechaza_cambios_y_divergencia_y_el_modo_imagen_muestra_su_version(): void
    {
        File::put($this->repo.'/app/code.php', 'pendiente');
        $this->assertFalse($this->service()->pull()['successful']);
        $this->git($this->repo, ['commit', '-am', 'Adelanto local']);
        $this->assertFalse($this->service()->pull()['successful']);
        config(['deployment.commit' => 'revision-publicada', 'system_info.version' => 'QA-6']);
        $status = (new GitVersioningService($this->root))->getRepositoryStatus();
        $this->assertFalse($status['is_available']);
        $this->assertSame('image', $status['mode']);
        $this->assertSame('revision-publicada', $status['release']['commit']);
        $this->assertSame('QA-6', $status['release']['version']);
    }

    public function test_el_destino_no_puede_sobrescribir_un_archivo_local_excluido(): void
    {
        File::put($this->repo.'/app/datos.dat', 'rastreo remoto');
        $this->git($this->repo, ['add', '--', 'app/datos.dat']);
        $this->git($this->repo, ['commit', '-m', 'Destino con archivo de datos']);
        $this->git($this->repo, ['push', 'origin', 'main']);
        $this->git($this->repo, ['reset', '--hard', 'HEAD~1']);
        File::put($this->repo.'/app/datos.dat', 'conservar local');
        $result = $this->service()->resetToRemote();
        $this->assertFalse($result['successful']);
        $this->assertSame('conservar local', File::get($this->repo.'/app/datos.dat'));
    }

    public function test_el_commit_selectivo_no_incluye_otros_archivos_preparados(): void
    {
        File::put($this->repo.'/app/seleccionado.php', 'seleccionado');
        File::put($this->repo.'/app/pendiente.php', 'pendiente');
        $this->git($this->repo, ['add', '--', 'app/pendiente.php']);
        $result = $this->service()->commit('Solo selección', false, ['app/seleccionado.php']);
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame("app/seleccionado.php\n", str_replace("\r\n", "\n", $this->git($this->repo, ['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD'])));
        $this->assertStringContainsString('app/pendiente.php', $this->git($this->repo, ['diff', '--cached', '--name-only']));
        $this->assertFalse($this->service()->commit('Selección inválida', false, ['.env'])['successful']);
    }

    public function test_publica_commits_existentes_y_rechaza_sobrescribir_un_remoto_adelantado(): void
    {
        File::put($this->repo.'/app/code.php', 'nuevo');
        $this->assertTrue($this->service()->commit('Nuevo commit')['successful']);
        $this->assertTrue($this->service()->push()['successful']);
        $this->git($this->repo, ['reset', '--hard', 'HEAD~1']);
        $result = $this->service()->push();
        $this->assertFalse($result['successful']);
        $this->assertStringContainsString('código 1', $result['error']);
    }

    public function test_consulta_ramas_etiquetas_anotadas_y_compara_un_commit(): void
    {
        $this->git($this->repo, ['branch', 'mejora']);
        $this->git($this->repo, ['tag', '-a', 'v1.0', '-m', 'Primera versión']);
        File::put($this->repo.'/app/code.php', 'segunda versión');
        $this->service()->commit('Segunda versión');
        $refs = $this->service()->references();
        $this->assertContains('refs/tags/v1.0', array_column($refs, 'ref'));
        $this->assertContains('refs/remotes/origin/main', array_column($refs, 'ref'));
        $inspection = $this->service()->inspect('refs/tags/v1.0');
        $this->assertCount(1, $inspection['history']);
        $this->assertStringContainsString('app/code.php', $inspection['comparison']);
        $this->assertCount(1, $this->service()->getCommitHistory(30, 'HEAD', 1));
    }

    public function test_instala_una_revision_elegida_con_recuperacion_sin_modificar_el_remoto(): void
    {
        $target = trim($this->git($this->repo, ['rev-parse', 'HEAD']));
        File::put($this->repo.'/app/code.php', 'versión posterior');
        $this->service()->commit('Posterior');
        $plan = $this->service()->previewDeployment('revision', $target);
        $result = $this->service()->deployPlan($plan);
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame($target, trim($this->git($this->repo, ['rev-parse', 'HEAD'])));
        $this->assertFileExists($result['recovery']['path'].'/manifest.json');
        $this->assertSame(['ready', 'prepared', 'complete'], $this->deployment->calls);
    }

    public function test_rechaza_vistas_previas_vencidas_y_cambios_de_contenido_posteriores(): void
    {
        File::put($this->repo.'/app/nuevo.php', 'contenido inicial');
        $plan = $this->service()->previewDeployment('revision', 'HEAD');
        File::put($this->repo.'/app/nuevo.php', 'contenido diferente con el mismo estado');
        $result = $this->service()->deployPlan($plan);
        $this->assertFalse($result['successful']);
        $this->assertStringContainsString('cambió desde la vista previa', $result['error']);
        $this->assertSame([], $this->deployment->calls);
        $plan['expires_at'] = now()->subSecond()->timestamp;
        $this->assertSame('GIT_PREVIEW_EXPIRED', $this->service()->deployPlan($plan)['error']);
    }

    public function test_cambia_a_una_rama_local_o_remota_y_conserva_los_operativos(): void
    {
        $this->git($this->repo, ['branch', 'mejora']);
        $this->git($this->repo, ['push', 'origin', 'main:refs/heads/remota']);
        $this->service()->fetchRemote();
        File::put($this->repo.'/.env', 'CONSERVAR=1');
        $result = $this->service()->deployPlan($this->service()->previewDeployment('branch', 'refs/heads/mejora'));
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame('mejora', $this->service()->getCurrentBranch());
        $result = $this->service()->deployPlan($this->service()->previewDeployment('branch', 'refs/remotes/origin/remota'));
        $this->assertTrue($result['successful'], $result['error']);
        $this->assertSame('remota', $this->service()->getCurrentBranch());
        $this->assertSame('CONSERVAR=1', File::get($this->repo.'/.env'));
    }

    public function test_no_cambia_de_rama_con_trabajo_pendiente_ni_acepta_referencias_como_opciones(): void
    {
        $this->git($this->repo, ['branch', 'mejora']);
        File::put($this->repo.'/app/code.php', 'pendiente');
        try {
            $this->service()->previewDeployment('branch', 'refs/heads/mejora');
            $this->fail('No rechazó los cambios pendientes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cambios locales', $exception->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->service()->inspect('--upload-pack=invalid');
    }

    public function test_detiene_el_cambio_si_el_codigo_se_modifica_durante_el_respaldo(): void
    {
        $before = trim($this->git($this->repo, ['rev-parse', 'HEAD']));
        $plan = $this->service()->previewDeployment('revision', $before);
        $this->deployment->afterPrepare = fn () => File::put($this->repo.'/app/code.php', 'cambio concurrente');
        $result = $this->service()->deployPlan($plan);
        $this->assertFalse($result['successful']);
        $this->assertFalse($result['code_changed']);
        $this->assertStringContainsString('durante la preparación', $result['error']);
        $this->assertSame('cambio concurrente', File::get($this->repo.'/app/code.php'));
        $this->assertNotContains('complete', $this->deployment->calls);
    }

    public function test_head_separado_permite_consultar_el_historial_sin_presentarse_como_imagen(): void
    {
        $this->git($this->repo, ['checkout', '--detach', 'HEAD']);
        $status = $this->service()->getRepositoryStatus();
        $this->assertTrue($status['is_available']);
        $this->assertTrue($status['detached']);
        $this->assertFalse($status['updates_enabled']);
        $this->assertNotEmpty($status['history']);
    }

    private function service(): GitVersioningService
    {
        return new GitVersioningService($this->repo, $this->deployment);
    }

    private function git(string $root, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $root, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }
}
