<?php

declare(strict_types=1);

use App\Jobs\PurgeDatabaseJob;
use App\Jobs\RestoreMaintenanceBackupJob;
use App\Modules\Sales\Models\Sale;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\RecoveryJournal;
use App\Modules\SystemSuperadmin\Models\MaintenancePurgeRun;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Modules\SystemSuperadmin\Services\PurgeNumberingPolicy;
use App\Support\AuthSessionCache;
use App\Support\OperationalCacheOutbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\MaintenanceScenario;

final class MaintenanceExecutionTest extends CommittedDatabaseTestCase
{
    #[DataProvider('runtimeCacheStates')]
    public function test_la_restauracion_conserva_cache_cola_y_sesiones_activas_sin_resucitar_trabajos_antiguos(bool $missingCache): void
    {
        Storage::fake('local');
        config(['cache.default' => 'database']);
        $user = MaintenanceScenario::user();
        $sale = MaintenanceScenario::sale($user);
        $authVersion = AuthSessionCache::version();
        Cache::put('qa-runtime', 'antes', 600);
        Cache::put('qa-expirado', 'historico', 600);
        $oldJob = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $service = app(MaintenanceBackupService::class);
        $backup = $service->generate('sql', $user);
        Cache::forget('qa-expirado');
        DB::table('jobs')->where('id', $oldJob)->delete();
        $currentJob = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        DB::table('sessions')->insert(['id' => 'qa-runtime', 'user_id' => $user->id, 'payload' => 'QA', 'last_activity' => time()]);
        $lock = Cache::lock('qa-runtime-lock', 600);
        $this->assertTrue($lock->get());
        if ($missingCache) {
            DB::statement('DROP TABLE `cache`');
        }
        DB::table('sales')->where('id', $sale->id)->update(['total' => 999]);

        // Una consulta de progreso puede escribir caché justo antes del cotejo de datos restaurados.
        $result = $service->restoreSql($backup, function (string $stage): void {
            if ($stage === 'verifying_source') {
                Cache::put('qa-runtime', 'durante', 600);
            }
        });

        $this->assertTrue($result['ok']);
        $this->assertSame('100.00', (string) $sale->fresh()->total);
        $this->assertSame('durante', Cache::get('qa-runtime'));
        $this->assertNull(Cache::get('qa-expirado'));
        $this->assertNotSame($authVersion, AuthSessionCache::version());
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
        $this->assertFalse(DB::table('jobs')->where('id', $oldJob)->exists());
        $this->assertTrue(DB::table('jobs')->where('id', $currentJob)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'qa-runtime')->exists());
        $this->assertFalse(Cache::lock('qa-runtime-lock', 600)->get());
        $this->assertSame(0, DB::table('cache_invalidation_outbox')->where('scope', 'recovery')->count());
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
        $lock->release();
    }

    public static function runtimeCacheStates(): array
    {
        return ['cache en uso' => [false], 'estructura de cache ausente' => [true]];
    }

    public function test_la_cola_ejecuta_la_purga_en_otro_proceso_sin_repetir_entregas(): void
    {
        config(['maintenance.queue_connection' => 'maintenance']);
        $user = MaintenanceScenario::user();
        $sale = MaintenanceScenario::sale($user);
        $options = MaintenanceScenario::purgeOptions($user, ['sales']);
        $run = MaintenancePurgeRun::query()->create(['user_id' => $user->id, 'options' => $options, 'status' => 'pending', 'stage' => 'queued']);
        PurgeDatabaseJob::dispatch($run->id);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertTrue(Sale::query()->whereKey($sale->id)->exists());
        $this->assertStringNotContainsString('preview_token', DB::table('maintenance_purge_runs')->where('id', $run->id)->value('options'));
        $this->assertArrayNotHasKey('options', $run->toArray());

        $this->workOnce();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertFalse(Sale::withTrashed()->whereKey($sale->id)->exists());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'maintenance')->count());
        $confirmedResult = $run->fresh()->result;
        PurgeDatabaseJob::dispatch($run->id);
        $this->workOnce();
        $this->assertSame($confirmedResult, $run->fresh()->result);
        $this->assertSame('completed', $run->fresh()->status);
    }

    #[DataProvider('cacheScopes')]
    public function test_un_fallo_de_cache_despues_del_commit_deja_un_pendiente_recuperable(string $scope): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('Caché temporalmente inaccesible'));
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('Caché temporalmente inaccesible'));
        Cache::shouldReceive('forever')->andThrow(new RuntimeException('Caché temporalmente inaccesible'));
        $outbox = app(OperationalCacheOutbox::class);
        DB::transaction(fn () => $outbox->schedule($scope));
        $pending = DB::table('cache_invalidation_outbox')->first();
        $this->assertNotNull($pending);
        $this->assertSame(1, (int) $pending->attempts);
        Cache::clearResolvedInstance('cache');
        app()->forgetInstance('cache');
        $this->assertSame(1, $outbox->flushPending());
        $this->assertSame(0, DB::table('cache_invalidation_outbox')->count());
        if ($scope === 'recovery') {
            $this->assertNotNull(Cache::get('illuminate:queue:restart'));
        }
    }

    public static function cacheScopes(): array
    {
        return ['financiera' => ['financial'], 'recuperacion' => ['recovery']];
    }

    public function test_restaura_la_base_original_y_cancela_purgas_pendientes_del_respaldo(): void
    {
        Storage::fake('local');
        $user = MaintenanceScenario::user();
        $sale = MaintenanceScenario::sale($user);
        $run = MaintenancePurgeRun::query()->create(['user_id' => $user->id, 'options' => [], 'status' => 'pending', 'stage' => 'queued']);
        $service = app(MaintenanceBackupService::class);
        $backup = $service->generate('sql', $user);
        DB::table('sales')->where('id', $sale->id)->update(['total' => 888]);
        $journalRoot = storage_path('qa/recovery-'.bin2hex(random_bytes(8)));
        $journal = new RecoveryJournal($journalRoot);
        app()->instance(RecoveryJournal::class, $journal);
        $recovery = $journal->create($user->id, $backup->id, DB::connection()->getDatabaseName());
        $otherRecovery = $journal->create($user->id, $backup->id, DB::connection()->getDatabaseName());
        $this->beforeApplicationDestroyed(function () use ($journalRoot): void {
            $resolved = realpath($journalRoot);
            if ($resolved && str_starts_with($resolved, realpath(storage_path('qa')).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        });
        config(['maintenance.queue_connection' => 'maintenance']);
        RestoreMaintenanceBackupJob::dispatch($recovery['id']);
        RestoreMaintenanceBackupJob::dispatch($otherRecovery['id']);
        $workerEnvironment = ['QA_RECOVERY_JOURNAL_PATH' => $journalRoot, 'QA_BACKUP_DISK_PATH' => Storage::disk('local')->path('')];
        $this->workOnce($workerEnvironment);
        $this->assertSame('completed', $journal->read($recovery['id'])['status'], DB::table('failed_jobs')->value('exception') ?? '');
        $this->assertSame('cancelled_by_restore', $journal->read($otherRecovery['id'])['stage']);
        $this->assertSame('100.00', (string) $sale->fresh()->total);
        $this->assertSame('cancelled_by_restore', $run->fresh()->stage);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
        $this->assertSame('fabrica_calmina_aroma_testing', DB::connection()->getDatabaseName());
        $this->assertTrue(MaintenanceBackup::query()->where('status', 'restored')->exists());
        $this->assertSame(1, DB::table('maintenance_barriers')->where('name', 'database-purge')->count());
        DB::table('sales')->where('id', $sale->id)->update(['total' => 777]);
        RestoreMaintenanceBackupJob::dispatch($recovery['id']);
        $this->workOnce($workerEnvironment);
        $this->assertSame('777.00', (string) $sale->fresh()->total);
    }

    public function test_el_reinicio_completo_ejecuta_ddl_fuera_de_la_transaccion_y_reporta_su_fallo_aparte(): void
    {
        $user = MaintenanceScenario::user();
        MaintenanceScenario::sale($user);
        $service = app(DatabasePurgeService::class);
        $options = MaintenanceScenario::purgeOptions($user, [], ['scope' => 'full_reset', 'reset_auto_increment' => true]);
        $levels = [];
        $failOnce = true;
        DB::listen(function ($query) use (&$levels, &$failOnce): void {
            if (str_starts_with($query->sql, 'ALTER TABLE `')) {
                $levels[] = DB::transactionLevel();
                if ($failOnce && str_contains($query->sql, '`sales`')) {
                    $failOnce = false;
                    throw new RuntimeException('Fallo simulado después del DDL');
                }
            }
        });
        $result = $service->executePurge($options, $user);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['identifier_reset']['ok']);
        $this->assertArrayHasKey('sales', $result['identifier_reset']['errors']);
        $this->assertNotEmpty($levels);
        $this->assertSame([0], array_values(array_unique($levels)));
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertTrue(app(PurgeNumberingPolicy::class)->resetIdentifiers(['sales'])['ok']);
        $this->assertSame(1, MaintenanceScenario::sale($user, 'QA-RESET-000001')->id);
    }

    private function workOnce(array $overrides = []): void
    {
        $environment = [];
        foreach ($_ENV as $key => $value) {
            if (is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }
        $environment['APP_KEY'] = config('app.key');
        $environment['MAINTENANCE_QUEUE_CONNECTION'] = 'maintenance';
        $environment = [...$environment, ...$overrides];
        $process = new Process([PHP_BINARY, base_path('tests/Support/queue-worker.php'), 'queue:work', 'maintenance', '--once', '--queue=maintenance', '--sleep=0', '--tries=1', '--timeout=1800'], base_path(), $environment);
        $process->setTimeout(120);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
