<?php

use App\Jobs\DeployRepositoryJob;
use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\SystemSuperadmin\Models\MaintenanceDeploymentRun;
use App\Modules\SystemSuperadmin\Services\GitVersioningService;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

beforeEach(function () {
    // La matriz HTTP no depende del repositorio del desarrollador ni puede modificarlo.
    $root = storage_path('qa/git-http-'.bin2hex(random_bytes(8)));
    File::makeDirectory($root, 0700, true);
    foreach ([['init', '--initial-branch=main'], ['config', 'user.name', 'HTTP QA'], ['config', 'user.email', 'http-qa@example.test']] as $arguments) {
        (new Process(['git', ...$arguments], $root))->mustRun();
    }
    File::put($root.'/README.md', 'Repositorio HTTP de pruebas');
    (new Process(['git', 'add', '--', 'README.md'], $root))->mustRun();
    (new Process(['git', 'commit', '-m', 'Inicial HTTP'], $root))->mustRun();
    app()->instance(GitVersioningService::class, new GitVersioningService($root));
    $this->beforeApplicationDestroyed(function () use ($root): void {
        $resolved = realpath($root);
        $allowed = realpath(storage_path('qa'));
        if ($resolved && $allowed && str_starts_with($resolved, $allowed.DIRECTORY_SEPARATOR) && str_starts_with(basename($resolved), 'git-http-')) {
            File::deleteDirectory($resolved);
        }
    });
});

function gitTestUser(bool $systemSuperadmin = false): User
{
    $branch = Branch::query()->create([
        'name' => 'Sucursal Git Test',
        'code' => 'GIT-'.uniqid(),
        'barcode' => 'BR-GIT-'.uniqid(),
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate([
        'name' => $systemSuperadmin ? SystemRoles::SYSTEM_SUPERADMIN : 'usuario-comun-test',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('prohíbe el acceso a la vista de git versioning a usuarios no autenticados', function () {
    $this->get('/system-superadmin/git-versioning')
        ->assertRedirect('/login');
});

it('prohíbe el acceso a usuarios sin el rol de sistemasuperadmin', function () {
    $user = gitTestUser(false);

    $this->actingAs($user)
        ->get('/system-superadmin/git-versioning')
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson('/system-superadmin/git-versioning/status')
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/system-superadmin/git-versioning/commit', ['message' => 'Test commit'])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/system-superadmin/git-versioning/pull')
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/system-superadmin/git-versioning/reset')
        ->assertForbidden();
});

it('permite a sistemasuperadmin acceder a la vista de git versioning', function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    $superadmin = gitTestUser(true);

    $this->actingAs($superadmin)
        ->get('/system-superadmin/git-versioning')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SystemSuperadmin/GitVersioning/Index')
            ->has('gitStatus')
            ->where('gitStatus.is_available', true)
        );
});

it('permite a sistemasuperadmin consultar el estado del repositorio por api json', function () {
    $superadmin = gitTestUser(true);

    $response = $this->actingAs($superadmin)
        ->getJson('/system-superadmin/git-versioning/status')
        ->assertOk();

    $response->assertJsonStructure([
        'is_available',
        'git_version',
        'branch',
        'commit' => [
            'hash',
            'short_hash',
            'author',
            'date',
            'message',
        ],
        'remote' => [
            'name',
            'url',
            'web_url',
            'ahead',
            'behind',
        ],
        'working_tree' => [
            'is_clean',
            'total_changed_files',
            'files',
        ],
        'history',
        'checked_at',
    ]);
});

it('valida que el mensaje de commit sea requerido y tenga al menos 3 caracteres', function () {
    $superadmin = gitTestUser(true);

    $this->actingAs($superadmin)
        ->postJson('/system-superadmin/git-versioning/commit', [
            'message' => 'ab',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);

    $this->actingAs($superadmin)
        ->postJson('/system-superadmin/git-versioning/commit', [
            'message' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

it('obtiene correctamente el estado y metadatos del repositorio con GitVersioningService', function () {
    $service = app(GitVersioningService::class);
    $status = $service->getRepositoryStatus();

    expect($status)->toBeArray();
    expect($status)->toHaveKeys([
        'is_available',
        'git_version',
        'branch',
        'commit',
        'remote',
        'working_tree',
        'history',
    ]);

    expect($status['is_available'])->toBeTrue();
    expect($status['branch'])->not()->toBeEmpty();
    expect($status['commit'])->toHaveKeys(['hash', 'author', 'message']);
    expect(count($status['history']))->toBeGreaterThan(0);
});

it('consulta referencias e historial sin cambiar el codigo instalado', function () {
    $user = gitTestUser(true);
    $before = app(GitVersioningService::class)->getLatestCommit()['hash'];
    $this->actingAs($user)->getJson('/system-superadmin/git-versioning/inspect?ref=HEAD')
        ->assertOk()->assertJsonPath('commit.hash', $before)->assertJsonCount(1, 'history');
    $this->actingAs($user)->postJson('/system-superadmin/git-versioning/preview', ['operation' => 'revision', 'ref' => $before])
        ->assertOk()->assertJsonPath('plan.target_commit', $before)->assertJsonStructure(['token', 'plan' => ['fingerprint', 'database_policy', 'expires_at']]);
    expect(app(GitVersioningService::class)->getLatestCommit()['hash'])->toBe($before);
});

it('protege todas las operaciones nuevas con el rol del sistema', function () {
    $this->actingAs(gitTestUser(false));
    $this->getJson('/system-superadmin/git-versioning/inspect?ref=HEAD')->assertForbidden();
    foreach (['preview', 'deploy', 'push'] as $endpoint) {
        $this->postJson('/system-superadmin/git-versioning/'.$endpoint)->assertForbidden();
    }
});

it('rechaza un token adulterado y una vista previa perteneciente a otro usuario', function () {
    config(['deployment.restart_command' => ['supervisorctl', 'restart', 'app']]);
    $this->actingAs(gitTestUser(true));
    $response = $this->postJson('/system-superadmin/git-versioning/preview', ['operation' => 'revision', 'ref' => 'HEAD'])->assertOk();
    $this->postJson('/system-superadmin/git-versioning/deploy', ['token' => 'adulterado'])->assertUnprocessable();
    $this->actingAs(gitTestUser(true))->postJson('/system-superadmin/git-versioning/deploy', ['token' => $response->json('token')])->assertUnprocessable();
    $this->assertDatabaseCount('maintenance_deployment_runs', 0);
});

it('envia el destino firmado al trabajador una sola vez y conserva su resultado', function () {
    Queue::fake();
    config(['deployment.restart_command' => ['supervisorctl', 'restart', 'app']]);
    $this->actingAs(gitTestUser(true));
    $response = $this->postJson('/system-superadmin/git-versioning/preview', ['operation' => 'revision', 'ref' => 'HEAD'])->assertOk();
    $this->postJson('/system-superadmin/git-versioning/deploy', ['token' => $response->json('token')])->assertAccepted();
    $this->postJson('/system-superadmin/git-versioning/deploy', ['token' => $response->json('token')])->assertConflict();
    $run = MaintenanceDeploymentRun::query()->sole();
    expect($run->result['request']['target_commit'])->toBe($response->json('plan.target_commit'));
    Queue::assertPushed(DeployRepositoryJob::class, 1);
    $worker = Mockery::mock(GitVersioningService::class);
    $worker->shouldReceive('deployPlan')->once()->with($run->result['request'])->andReturn(['successful' => true, 'message' => 'Destino verificado e instalado.']);
    (new DeployRepositoryJob($run->id))->handle($worker);
    expect($run->fresh()->status)->toBe('completed');
});

it('rechaza una vista previa caducada antes de registrar un despliegue', function () {
    $this->actingAs(gitTestUser(true));
    $response = $this->postJson('/system-superadmin/git-versioning/preview', ['operation' => 'revision', 'ref' => 'HEAD'])->assertOk();
    $this->travel(11)->minutes();
    $this->postJson('/system-superadmin/git-versioning/deploy', ['token' => $response->json('token')])->assertUnprocessable();
    $this->travelBack();
    $this->assertDatabaseCount('maintenance_deployment_runs', 0);
});

it('muestra el fallo tecnico de Git en vez de ocultarlo en un flash', function () {
    $response = $this->actingAs(gitTestUser(true))->postJson('/system-superadmin/git-versioning/fetch')->assertUnprocessable();
    $response->assertJsonPath('system_error.code', 'VALIDATION_FAILED');
    expect($response->json('system_error.technical_message'))->toContain('código 2');
});

it('explica las referencias inexistentes como errores de validacion', function () {
    $this->actingAs(gitTestUser(true));
    $this->getJson('/system-superadmin/git-versioning/inspect?ref=referencia-inexistente')->assertUnprocessable()->assertJsonValidationErrors('ref');
    $this->postJson('/system-superadmin/git-versioning/preview', ['operation' => 'revision', 'ref' => 'referencia-inexistente'])->assertUnprocessable()->assertJsonValidationErrors('ref');
});
