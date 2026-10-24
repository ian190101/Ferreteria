<?php

use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Sales\Models\Sale;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagedLotScenario;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InventoryLotConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('127.0.0.1', config('database.connections.mysql.host'));
        self::assertSame('3307', (string) config('database.connections.mysql.port'));
        self::assertSame('fabrica_calmina_aroma_testing', config('database.connections.mysql.database'));
        self::assertSame('calmina_test', config('database.connections.mysql.username'));
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_dos_procesos_compiten_por_la_ultima_unidad(): void
    {
        self::assertSame('127.0.0.1', config('database.connections.mysql.host'));
        self::assertSame('3307', (string) config('database.connections.mysql.port'));
        self::assertSame('fabrica_calmina_aroma_testing', config('database.connections.mysql.database'));
        ManagedLotScenario::profile();
        $branch = ManagedLotScenario::branch();
        $product = ManagedLotScenario::product($branch);
        $lot = ManagedLotScenario::lot($product, $branch);
        $user = ManagedLotScenario::user($branch, 'competidor-lotes');
        DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
            $lot->id, '1.000', 'purchase_lot_entry', $lot, 'race-initial', $user->id));

        $outcomes = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'decrement'],
            ['label' => 'b', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'decrement'],
        ]);
        sort($outcomes);
        self::assertSame(['ok', 'rejected'], $outcomes);
        self::assertSame('0.000', (string) $lot->fresh()->available_meters);
    }

    public function test_altas_codigo_compra_anulacion_y_orden_inverso_se_serializan(): void
    {
        ManagedLotScenario::profile();
        $branch = ManagedLotScenario::branch();
        $product = ManagedLotScenario::product($branch);
        $lot = ManagedLotScenario::lot($product, $branch);
        $user = ManagedLotScenario::user($branch, 'competidor-lotes-segundo');
        $admin = ManagedLotScenario::user($branch, SystemRoles::BUSINESS_ADMIN);

        $codes = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'create'],
            ['label' => 'b', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'create'],
        ]);
        sort($codes);
        self::assertSame(['ok', 'rejected'], $codes);
        self::assertSame(1, ProductCoil::query()->where('product_id', $product->id)
            ->where('branch_id', $branch->id)->where('lot_code', 'LT2026C01')->count());

        $outcomes = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'purchase'],
            ['label' => 'b', 'lot' => $lot->id, 'user' => $admin->id, 'mode' => 'void'],
        ]);
        self::assertSame('ok', $outcomes[1]);
        self::assertContains($outcomes[0], ['ok', 'rejected']);
        self::assertNotNull($lot->fresh()->voided_at);
        self::assertSame('0.000', (string) $lot->fresh()->available_meters);

        $reserveLot = ManagedLotScenario::lot($product, $branch, 'LT2026F01');
        DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
            $reserveLot->id, '1.000', 'purchase_lot_entry', $reserveLot, 'race-reserve-in', $user->id));
        $quotation = Sale::query()->create([
            'branch_id' => $branch->id, 'user_id' => $user->id,
            'receipt_number' => 'RACE-QUOTE-'.uniqid(), 'sold_at' => now(),
            'document_type' => 'quotation', 'status' => 'draft',
        ]);
        $line = $quotation->items()->create([
            'product_id' => $product->id, 'product_coil_id' => $reserveLot->id,
            'lot_code_snapshot' => $reserveLot->lot_code, 'meters' => '1.000',
            'unit_price' => '3.0000', 'total' => '3.00',
        ]);
        $reservationRace = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $reserveLot->id, 'user' => $user->id, 'mode' => 'reserve',
                'other' => $quotation->id.','.$line->id],
            ['label' => 'b', 'lot' => $reserveLot->id, 'user' => $admin->id, 'mode' => 'void'],
        ]);
        sort($reservationRace);
        self::assertSame(['ok', 'rejected'], $reservationRace);
        self::assertFalse($reserveLot->fresh()->isVoided()
            && InventoryReservation::query()->where('product_coil_id', $reserveLot->id)
                ->where('status', InventoryReservation::STATUS_ACTIVE)->exists());

        $first = ManagedLotScenario::lot($product, $branch, 'LT2026D01');
        $second = ManagedLotScenario::lot($product, $branch, 'LT2026E01');
        DB::transaction(function () use ($product, $branch, $first, $second, $user): void {
            foreach ([$first, $second] as $entry) {
                app(LotInventoryService::class)->apply($product->id, $branch->id, $entry->id,
                    '2.000', 'purchase_lot_entry', $entry, 'race-double-in-'.$entry->id, $user->id);
            }
        });
        $double = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $first->id, 'other' => $second->id, 'user' => $user->id, 'mode' => 'double'],
            ['label' => 'b', 'lot' => $first->id, 'other' => $second->id, 'user' => $user->id, 'mode' => 'double'],
        ]);
        self::assertSame(['ok', 'ok'], $double);
        self::assertSame('0.000', (string) $first->fresh()->available_meters);
        self::assertSame('0.000', (string) $second->fresh()->available_meters);
    }

    public function test_desactivar_el_perfil_compite_con_la_entrada_de_mercancia(): void
    {
        $profile = ManagedLotScenario::profile();
        $branch = ManagedLotScenario::branch();
        $product = ManagedLotScenario::product($branch);
        $lot = ManagedLotScenario::lot($product, $branch);
        $user = ManagedLotScenario::user($branch, 'competidor-perfil-lotes');
        self::assertSame($profile->id, \App\Modules\SystemSuperadmin\Models\BusinessProfile::query()
            ->where('status', 'active')->orderByDesc('applied_at')->orderByDesc('id')->value('id'));

        $outcomes = $this->race($product->id, $branch->id, [
            ['label' => 'a', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'purchase'],
            ['label' => 'b', 'lot' => $lot->id, 'user' => $user->id, 'mode' => 'disable'],
        ]);
        sort($outcomes);
        $enabled = $profile->fresh()->configuration['inventory']['lot_tracking_enabled'];
        $balance = (string) $lot->fresh()->available_meters;
        self::assertSame(['ok', 'rejected'], $outcomes,
            json_encode(['enabled' => $enabled, 'balance' => $balance]));
        self::assertSame($enabled ? '1.000' : '0.000', (string) $lot->fresh()->available_meters);
    }

    private function race(int $productId, int $branchId, array $actors): array
    {
        $barrier = storage_path('framework/testing/lot-race-'.uniqid());
        mkdir($barrier, 0777, true);
        $workers = [];
        try {
            foreach ($actors as $actor) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/lot_concurrency_worker.php'),
                    $barrier, $actor['label'], (string) $productId, (string) $branchId,
                    (string) $actor['lot'], (string) $actor['user'], $actor['mode'],
                    (string) ($actor['other'] ?? 0)]);
                $worker->setTimeout(90);
                $worker->start();
                $workers[] = $worker;
            }
            // El arranque de PHP/Laravel en Windows puede tardar más que la operación concurrente.
            $deadline = microtime(true) + 60;
            while ((! is_file($barrier.'/a.ready') || ! is_file($barrier.'/b.ready')) && microtime(true) < $deadline) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        self::fail('El trabajador terminó antes de la barrera: '.$worker->getErrorOutput());
                    }
                }
                clearstatcache();
                usleep(10000);
            }
            self::assertFileExists($barrier.'/a.ready');
            self::assertFileExists($barrier.'/b.ready');
            file_put_contents($barrier.'/start', 'start');
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $outcomes = array_map(fn (Process $worker) => trim($worker->getOutput()), $workers);
            return $outcomes;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (['a.ready', 'b.ready', 'start'] as $name) {
                if (is_file($barrier.'/'.$name)) {
                    unlink($barrier.'/'.$name);
                }
            }
            rmdir($barrier);
        }
    }
}
