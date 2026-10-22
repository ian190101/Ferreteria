<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Purchases\Services\PurchaseService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\CommittedDatabaseTestCase;
use Tests\Support\OperationalScenario;

final class PurchaseVoidConcurrencyTest extends CommittedDatabaseTestCase
{
    private function scenario(): array
    {
        $scenario = OperationalScenario::create();
        foreach (['purchases.manage', 'payments.manage'] as $permission) {
            $scenario['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $scenario['reservation']->update(['status' => 'released', 'released_at' => now()]);
        config(['database.connections.competing' => config('database.connections.mysql')]);

        return $scenario;
    }

    public function test_un_bloqueo_concurrente_no_deja_reversos_parciales_y_permite_reintentar(): void
    {
        $scenario = $this->scenario();
        $before = OperationalScenario::snapshot();
        $competing = DB::connection('competing');
        $competing->beginTransaction();
        $competing->table('product_coils')->where('id', $scenario['coil']->id)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(PurchaseService::class)->voidPurchase($scenario['purchases'][0], $scenario['user']->id, 'Concurrencia');
            $this->fail('Debió impedirse el acceso a la bobina bloqueada.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
            $this->assertSame($before, OperationalScenario::snapshot());
        } finally {
            $competing->rollBack();
            DB::purge('competing');
        }

        app(PurchaseService::class)->voidPurchase($scenario['purchases'][0], $scenario['user']->id, 'Reintento autorizado');
        $this->assertSame('voided', $scenario['purchases'][0]->fresh()->status);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_la_anulacion_mantiene_bloqueada_la_bobina_hasta_confirmar(): void
    {
        $scenario = $this->scenario();
        $competing = DB::connection('competing');
        $competing->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $observedLock = false;
        DB::listen(function (QueryExecuted $query) use ($competing, $scenario, &$observedLock): void {
            if ($observedLock || ! str_contains($query->sql, 'update `purchases` set `status`')) {
                return;
            }
            $competing->beginTransaction();
            try {
                $competing->table('product_coils')->where('id', $scenario['coil']->id)->lockForUpdate()->first();
                $this->fail('La bobina debió permanecer bloqueada hasta el commit.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
                $observedLock = true;
            } finally {
                $competing->rollBack();
            }
        });

        try {
            app(PurchaseService::class)->voidPurchase($scenario['purchases'][0], $scenario['user']->id, 'Bloqueo hasta confirmar');
            $this->assertTrue($observedLock);
            $this->assertNull(ProductCoil::on('competing')->find($scenario['coil']->id));
        } finally {
            DB::purge('competing');
        }
    }
}
