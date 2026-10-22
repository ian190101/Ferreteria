<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Purchases\Services\PurchaseService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Support\OperationalScenario;

function consistentVoidScenario(bool $release = true): array
{
    $scenario = OperationalScenario::create();
    foreach (['purchases.manage', 'payments.manage', 'inventory.reservations.manage'] as $permission) {
        $scenario['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    if ($release) {
        $scenario['reservation']->update(['status' => 'released', 'released_at' => now()]);
    }

    return $scenario;
}

it('bloquea la bobina reservada y permite anular después de liberarla por su flujo', function () {
    $scenario = consistentVoidScenario(false);
    $purchase = $scenario['purchases'][0];
    $account = OperationalScenario::addBankPayments($scenario);
    $before = OperationalScenario::snapshot();

    $this->actingAs($scenario['user'])->patchJson(route('purchases.void', $purchase), ['reason' => 'Intento con reserva'])
        ->assertUnprocessable()->assertJsonValidationErrors('purchase');
    expect(OperationalScenario::snapshot())->toBe($before);

    $this->patch(route('inventory.reservations.release', $scenario['reservation']))->assertRedirect();
    $this->patchJson(route('purchases.void', $purchase), ['reason' => 'Reserva liberada'])->assertRedirect();

    expect($purchase->fresh()->status)->toBe('voided')
        ->and((float) $scenario['stock']->fresh()->available_meters)->toBe(60.0)
        ->and((float) $scenario['orderItem']->fresh()->received_meters)->toBe(60.0)
        ->and($scenario['order']->fresh()->status)->toBe('partial_received')
        ->and($scenario['purchases'][1]->fresh()->status)->toBe('received')
        ->and($purchase->payments()->count())->toBe(0)
        ->and((float) $account->fresh()->current_balance)->toBe(1000.0);
    $this->assertSoftDeleted('product_coils', ['id' => $scenario['coil']->id]);
    $this->assertDatabaseHas('purchase_order_receipts', ['purchase_id' => $purchase->id, 'voided_at' => $purchase->fresh()->voided_at->format('Y-m-d H:i:s')]);

    $after = OperationalScenario::snapshot();
    $this->patchJson(route('purchases.void', $purchase), ['reason' => 'Reintento'])->assertUnprocessable();
    expect(OperationalScenario::snapshot())->toBe($after);

    $this->patchJson(route('purchases.void', $scenario['purchases'][1]), ['reason' => 'Anular la otra recepción'])->assertRedirect();
    expect((float) $scenario['orderItem']->fresh()->received_meters)->toBe(0.0)
        ->and($scenario['order']->fresh()->status)->toBe('approved')
        ->and((float) $scenario['stock']->fresh()->available_meters)->toBe(0.0);
});

it('detiene inventario faltante consumo cantidades agregadas y recepciones inconsistentes sin cambios', function (string $failure) {
    $scenario = consistentVoidScenario();
    $purchase = $scenario['purchases'][0];
    match ($failure) {
        'consumo' => $scenario['coil']->update(['available_meters' => 99]),
        'consumo historico' => InventoryMovement::query()->create([
            'branch_id' => $scenario['branch']->id, 'product_id' => $scenario['products']['coil']->id,
            'product_coil_id' => $scenario['coil']->id, 'user_id' => $scenario['user']->id,
            'type' => 'sale_stock_out', 'meters_delta' => -1, 'meters_before' => 100, 'meters_after' => 99,
        ]),
        'bobina faltante' => $scenario['coil']->delete(),
        'stock faltante' => $scenario['stock']->delete(),
        'recepcion inconsistente' => $scenario['orderItem']->update(['received_meters' => 30]),
        'stock reservado' => $scenario['stock']->update(['reserved_meters' => 70]),
        'cantidad agregada' => $purchase->items()->create(['product_id' => $scenario['products']['global']->id, 'meters' => 70, 'unit_cost' => 10]),
    };
    $before = OperationalScenario::snapshot();

    $this->actingAs($scenario['user'])->patchJson(route('purchases.void', $purchase), ['reason' => 'Validación integral'])
        ->assertUnprocessable()->assertJsonValidationErrors('purchase');

    expect(OperationalScenario::snapshot())->toBe($before);
})->with(['consumo', 'consumo historico', 'bobina faltante', 'stock faltante', 'recepcion inconsistente', 'stock reservado', 'cantidad agregada']);

it('revierte inventario pagos bancos recepciones auditoria y caches cuando falla una etapa', function (string $sqlFragment) {
    $scenario = consistentVoidScenario();
    OperationalScenario::addBankPayments($scenario);
    Cache::put('banks:summary_version', 70);
    $version = SystemCacheInvalidator::operationalVersion();
    $before = OperationalScenario::snapshot();
    $triggered = false;
    DB::listen(function (QueryExecuted $query) use ($sqlFragment, &$triggered): void {
        if (! $triggered && str_contains(strtolower($query->sql), $sqlFragment)) {
            $triggered = true;
            throw new RuntimeException('Fallo simulado de una etapa transaccional.');
        }
    });

    try {
        app(PurchaseService::class)->voidPurchase($scenario['purchases'][0], $scenario['user']->id, 'Prueba de reversión');
        $this->fail('Debió propagarse el fallo de la etapa.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Fallo simulado de una etapa transaccional.');
    }

    expect($triggered)->toBeTrue()
        ->and(OperationalScenario::snapshot())->toBe($before)
        ->and(Cache::get('banks:summary_version'))->toBe(70)
        ->and(SystemCacheInvalidator::operationalVersion())->toBe($version);
})->with([
    'kardex' => ['insert into `inventory_movements`'],
    'bancos' => ['update `bank_transactions`'],
    'pagos' => ['update `purchase_payments`'],
    'recepciones' => ['update `purchase_order_receipts`'],
    'auditoria final' => ['update `purchases` set `status`'],
]);
