<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Services\InventoryReservationService;
use App\Modules\Inventory\Services\ProductLotService;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessLotRuleTransitionGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.mysql.host') !== '127.0.0.1'
    || (string) config('database.connections.mysql.port') !== '3307'
    || config('database.connections.mysql.database') !== 'fabrica_calmina_aroma_testing'
    || config('database.connections.mysql.username') !== 'calmina_test') {
    throw new RuntimeException('El trabajador no está conectado a la base aislada.');
}

[$script, $barrier, $label, $productId, $branchId, $lotId, $userId] = $argv;
$mode = $argv[7] ?? 'decrement';
$otherLotId = $argv[8] ?? null;
file_put_contents($barrier.'/'.$label.'.ready', 'ready');
$deadline = microtime(true) + 60;
while (! is_file($barrier.'/start') && microtime(true) < $deadline) {
    clearstatcache();
    usleep(10000);
}
if (! is_file($barrier.'/start')) {
    throw new RuntimeException('No se abrió la barrera de concurrencia.');
}

try {
    DB::transaction(function () use ($productId, $branchId, $lotId, $userId, $label, $mode, $otherLotId): void {
        if ($mode === 'create') {
            app(ProductLotService::class)->create(Product::query()->findOrFail($productId),
                (int) $branchId, 'LT2026C01');
            return;
        }
        if ($mode === 'disable') {
            $profile = BusinessProfile::query()->where('status', 'active')
                ->orderByDesc('applied_at')->orderByDesc('id')->firstOrFail();
            $next = $profile->configuration;
            $next['inventory']['lot_tracking_enabled'] = false;
            app(BusinessLotRuleTransitionGuard::class)->assertTransition($next);
            $profile->configuration = $next;
            $profile->save();
            return;
        }
        if ($mode === 'void') {
            app(ProductLotService::class)->void(\App\Models\User::query()->findOrFail($userId),
                Product::query()->findOrFail($productId), ProductCoil::query()->findOrFail($lotId), 'Carrera de prueba');
            return;
        }
        if ($mode === 'reserve') {
            [$saleId, $saleItemId] = explode(',', (string) $otherLotId);
            app(InventoryReservationService::class)->reserve((int) $lotId, (int) $saleId,
                (int) $saleItemId, '1.000', (int) $userId);
            return;
        }
        $inventory = app(LotInventoryService::class);
        if ($mode === 'double') {
            $ordered = $label === 'a' ? [$lotId, $otherLotId] : [$otherLotId, $lotId];
            $inventory->lockBatch(array_map(fn ($id) => [
                'product_id' => (int) $productId, 'branch_id' => (int) $branchId, 'lot_id' => (int) $id,
            ], $ordered));
            foreach ($ordered as $id) {
                $inventory->apply((int) $productId, (int) $branchId, (int) $id, '-1.000',
                    'sale_stock_out', ProductCoil::query()->findOrFail($id), 'race-double-'.$label.'-'.$id, (int) $userId);
            }
            return;
        }
        $delta = $mode === 'purchase' ? '1.000' : '-1.000';
        $inventory->apply((int) $productId, (int) $branchId,
            (int) $lotId, $delta, $mode === 'purchase' ? 'purchase_lot_entry' : 'sale_stock_out',
            ProductCoil::query()->findOrFail($lotId), 'race-'.$mode.'-'.$label, (int) $userId);
    }, 3);
    echo 'ok';
} catch (ValidationException $exception) {
    echo 'rejected';
}
