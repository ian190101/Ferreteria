<?php

declare(strict_types=1);

use App\Modules\Settings\Services\MaintenanceBackupService;
use App\Modules\Settings\Services\ProductionLogService;
use App\Modules\SystemSuperadmin\Services\DatabasePurgeService;
use App\Modules\SystemSuperadmin\Services\PurgeScopePlanner;
use App\Support\OperationalWriteGate;
use App\Support\SystemRoles;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\OperationalScenario;

final class PurgeConcurrencyTest extends CommittedDatabaseTestCase
{
    public function test_la_barrera_permite_escrituras_concurrentes_y_excluye_la_purga(): void
    {
        config(['database.connections.competing' => config('database.connections.mysql')]);
        $competing = DB::connection('competing');
        $competing->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $gate = app(OperationalWriteGate::class);
        $gate->begin();
        $competing->beginTransaction();
        $gate->shared($competing);
        $this->assertSame(1, $competing->transactionLevel());
        $competing->rollBack();
        $gate->finish();

        try {
            $gate->exclusive(function () use ($competing, $gate): void {
                $name = 'calmina:purge:'.sha1(DB::connection()->getDatabaseName());
                $this->assertSame(0, (int) $competing->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
                $competing->beginTransaction();
                try {
                    $gate->shared($competing);
                    $this->fail('La purga debió bloquear las escrituras operativas.');
                } catch (QueryException $exception) {
                    $this->assertSame(1205, $exception->errorInfo[1]);
                } finally {
                    $competing->rollBack();
                }
            });
            $competing->beginTransaction();
            $gate->shared($competing);
            $competing->rollBack();
        } finally {
            DB::purge('competing');
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_resuelve_ciclos_anulables_y_bloquea_ciclos_obligatorios_sin_desactivar_integridad(): void
    {
        $scenario = OperationalScenario::create();
        $scenario['user']->assignRole(Role::findOrCreate(SystemRoles::SYSTEM_SUPERADMIN, 'web'));
        Schema::create('qa_cycle_left', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('right_id')->nullable();
        });
        Schema::create('qa_cycle_right', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('left_id')->nullable()->constrained('qa_cycle_left')->restrictOnDelete();
        });
        Schema::table('qa_cycle_left', function (Blueprint $table): void {
            $table->foreign('right_id')->references('id')->on('qa_cycle_right')->restrictOnDelete();
        });
        DB::table('qa_cycle_left')->insert(['id' => 1]);
        DB::table('qa_cycle_right')->insert(['id' => 1, 'left_id' => 1]);
        DB::table('qa_cycle_left')->where('id', 1)->update(['right_id' => 1]);
        $service = Mockery::mock(DatabasePurgeService::class, [app(MaintenanceBackupService::class), app(ProductionLogService::class)])->makePartial();
        $service->shouldReceive('modulesCatalog')->andReturn(['qa' => [
            'id' => 'qa', 'name' => 'QA', 'description' => 'QA', 'group' => 'QA', 'is_operational' => true,
            'tables' => ['qa_cycle_left', 'qa_cycle_right'],
        ]]);
        $options = ['scope' => 'selected', 'tables' => ['qa_cycle_left', 'qa_cycle_right'], 'create_backup' => false];
        $preview = $service->preview($options, $scenario['user']);
        $this->assertTrue($preview['can_execute']);
        $this->assertNotEmpty($preview['nullable_breaks']);
        $result = $service->executePurge([...$options, 'preview_token' => $preview['preview_token']], $scenario['user']);
        $this->assertSame(2, $result['total_deleted']);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);

        DB::table('qa_cycle_left')->insert(['id' => 1]);
        DB::table('qa_cycle_right')->insert(['id' => 1, 'left_id' => 1]);
        DB::table('qa_cycle_left')->where('id', 1)->update(['right_id' => 1]);
        Schema::table('qa_cycle_left', fn (Blueprint $table) => $table->unsignedBigInteger('right_id')->nullable(false)->change());
        Schema::table('qa_cycle_right', fn (Blueprint $table) => $table->unsignedBigInteger('left_id')->nullable(false)->change());
        $plan = app(PurgeScopePlanner::class)->calculate(['qa_cycle_left', 'qa_cycle_right'], false);
        $this->assertNotEmpty($plan['blockers']);
        $this->assertStringContainsString('circular obligatoria', implode(' ', $plan['blockers']));
    }
}
