<?php

use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Services\ProductLotService;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ManagedLotScenario;

it('configura el peso por metro y su recíproco exacto desde espesores y catálogos', function (string $routeName, string $updateRoute) {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'configurador-unidades-lotes', ['inventory.products.manage']);
    $payload = ['name' => 'Espesor exacto '.uniqid(), 'millimeters' => '0.7123', 'kg_per_meter' => '3.130000', 'is_active' => true];

    $this->actingAs($user)->post(route($routeName), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $thickness = \App\Modules\Inventory\Models\Thickness::query()->where('name', $payload['name'])->firstOrFail();
    expect((string) $thickness->kg_to_meter_factor)->toBe('0.319489')
        ->and(app(InventoryQuantityService::class)->metersFromWeightExact('31.300', (string) $thickness->kg_per_meter, (string) $thickness->kg_to_meter_factor))->toBe('10.000');

    $payload['kg_per_meter'] = '2.500000';
    $this->actingAs($user)->put(route($updateRoute, $thickness), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $thickness->fresh()->kg_to_meter_factor)->toBe('0.400000');

    $payload['kg_per_meter'] = '2.5e0';
    $this->actingAs($user)->putJson(route($updateRoute, $thickness), $payload)->assertUnprocessable()->assertJsonValidationErrors('kg_per_meter');
    expect((string) $thickness->fresh()->kg_to_meter_factor)->toBe('0.400000');
})->with([
    ['inventory.thicknesses.store', 'inventory.thicknesses.update'],
    ['inventory.products.catalogs.thicknesses.store', 'inventory.products.catalogs.thicknesses.update'],
]);

it('mantiene cada bobina en su lote y suma décimas exactamente', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $user = ManagedLotScenario::user($branch, 'gestor-lotes-prueba');
    $lots = app(ProductLotService::class);
    $inventory = app(LotInventoryService::class);

    DB::transaction(function () use ($lots, $inventory, $product, $branch, $user): void {
        $first = $lots->create($product, $branch->id, ' lt2026a01 ');
        $second = $lots->create($product, $branch->id, 'LT-2026-A02');
        expect($first->id)->not->toBe($second->id);
        $inventory->apply($product->id, $branch->id, $first->id, '0.100', 'purchase_entry_coil', $first, 'test-entry-a', $user->id);
        $inventory->apply($product->id, $branch->id, $first->id, '0.200', 'purchase_entry_coil', $first, 'test-entry-b', $user->id);
        expect((string) $first->fresh()->available_meters)->toBe('0.300');
    });

    expect((string) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe('0.300')
        ->and(app(InventoryQuantityService::class)->addExact('0.100', '0.200'))->toBe('0.300');
});

it('corrige el código sin cambiar la identidad y anula exactamente su saldo', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $admin = ManagedLotScenario::user($branch, SystemRoles::BUSINESS_ADMIN);
    $service = app(ProductLotService::class);
    $inventory = app(LotInventoryService::class);
    $first = ManagedLotScenario::lot($product, $branch);
    $second = ManagedLotScenario::lot($product, $branch, 'LT2026B01');

    DB::transaction(function () use ($inventory, $product, $branch, $first, $second, $admin): void {
        $inventory->apply($product->id, $branch->id, $first->id, '10.000', 'purchase_lot_entry', $first, 'test-void-a', $admin->id);
        $inventory->apply($product->id, $branch->id, $second->id, '5.000', 'purchase_lot_entry', $second, 'test-void-b', $admin->id);
    });

    $updated = $service->update($admin, $product, $first, ' lt-2026-a01 ', 'Código corregido');
    expect($updated->id)->toBe($first->id)
        ->and($updated->lot_code)->toBe('LT-2026-A01');
    $voided = $service->void($admin, $product, $first, 'Mercancía dañada');
    expect((string) $voided->available_meters)->toBe('0.000')
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->voided_by)->toBe($admin->id)
        ->and((string) $second->fresh()->available_meters)->toBe('5.000')
        ->and((string) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe('5.000');
    expect(fn () => $service->void($admin, $product, $voided, 'Repetición'))
        ->toThrow(ValidationException::class);
});

it('rechaza códigos inválidos y reutilizar el código de un lote existente', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $first = ManagedLotScenario::lot($product, $branch);
    $service = app(ProductLotService::class);

    foreach (['', '---', 'LT 2026', 'LT/2026', str_repeat('A', 80).'1'] as $code) {
        expect(fn () => $service->normalizeCode($code))->toThrow(ValidationException::class);
    }
    expect(fn () => DB::transaction(fn () => $service->create($product, $branch->id, $first->lot_code)))
        ->toThrow(ValidationException::class);
    expect($service->normalizeCode(' lt-2026-a01 '))->toBe('LT-2026-A01');
    expect($service->normalizeCode('12345'))->toBe('12345')
        ->and($service->normalizeCode('00000'))->toBe('00000')
        ->and($service->normalizeCode('solo-letras'))->toBe('SOLO-LETRAS');
});

it('repetir la misma clave de movimiento no duplica el kardex ni el saldo', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'idempotencia-lotes');
    $inventory = app(LotInventoryService::class);

    DB::transaction(function () use ($inventory, $product, $branch, $lot, $user): void {
        $first = $inventory->apply($product->id, $branch->id, $lot->id,
            '1.000', 'purchase_lot_entry', $lot, 'repeatable-lot-entry', $user->id);
        $second = $inventory->apply($product->id, $branch->id, $lot->id,
            '1.000', 'purchase_lot_entry', $lot, 'repeatable-lot-entry', $user->id);
        expect($second->id)->toBe($first->id);
        expect(fn () => $inventory->apply($product->id, $branch->id, $lot->id,
            '2.000', 'purchase_lot_entry', $lot, 'repeatable-lot-entry', $user->id))
            ->toThrow(ValidationException::class);
    });

    expect((string) $lot->fresh()->available_meters)->toBe('1.000')
        ->and((string) ProductBranchStock::query()->where('product_id', $product->id)
            ->value('available_meters'))->toBe('1.000');
});


it('crea el producto con espesor conversiones y varios lotes sumados exactamente', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'alta-multiple-lotes', ['inventory.products.view', 'inventory.products.manage']);
    $payload = ManagedLotScenario::catalogPayload($branch);
    $this->actingAs($user)->post(route('inventory.products.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $product = \App\Modules\Inventory\Models\Product::query()->where('name', $payload['name'])->firstOrFail();
    $lots = $product->lots()->orderBy('id')->get();
    expect($lots)->toHaveCount(3)
        ->and($lots->pluck('lot_code')->sort()->values()->all())->toBe(['12345', 'G260427D03', 'G260427D04'])
        ->and((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('0.600')
        ->and((string) $product->unitConversions()->firstOrFail()->factor_to_base)->toBe('2.500000')
        ->and($product->thickness_id)->toBe($payload['thickness_id'])
        ->and($product->lots()->where('lot_code', '12345')->firstOrFail()->initial_meters)->toBe('0.300')
        ->and($product->lots()->where('lot_code', '12345')->firstOrFail()->initial_kg)->toBe('0.600');
    expect(\App\Modules\Inventory\Models\InventoryMovement::query()->where('product_id', $product->id)->count())->toBe(3);
    $this->getJson(route('inventory.lots.index', $product))->assertOk()->assertJsonPath('summary.total', 3)->assertJsonCount(3, 'lots.data');

    $payload['lot_registrations'] = [['branch_id' => $branch->id, 'lot_code' => 'H150826A01', 'initial_kg' => '2.000', 'initial_meters' => '1.000']];
    $this->put(route('inventory.products.update', $product), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect($product->lots()->count())->toBe(4)
        ->and((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('1.600');
});

it('revierte producto lotes y stock si el alta múltiple contiene un código duplicado', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'alta-lotes-rollback', ['inventory.products.manage']);
    $payload = ManagedLotScenario::catalogPayload($branch);
    $payload['lot_registrations'][1]['lot_code'] = 'G260427D03';
    $this->actingAs($user)->post(route('inventory.products.store'), $payload)->assertSessionHasErrors();
    expect(\App\Modules\Inventory\Models\Product::query()->where('name', $payload['name'])->exists())->toBeFalse()
        ->and(\App\Modules\Inventory\Models\ProductCoil::query()->count())->toBe(0)
        ->and(\App\Modules\Inventory\Models\InventoryMovement::query()->count())->toBe(0);
});

it('muestra agotados en selectores sin permitir venderlos y permite reingreso en compras', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch, 'G260427D03');
    $user = ManagedLotScenario::user($branch, 'opciones-lotes-agotados', ['sales.manage', 'purchases.manage', 'inventory.products.view']);
    $this->actingAs($user)->getJson(route('inventory.lots.options', ['product' => $product->id, 'branch_id' => $branch->id, 'context' => 'sale']))
        ->assertOk()->assertJsonPath('data.0.lot_code', 'G260427D03')->assertJsonPath('data.0.depleted', true)->assertJsonPath('data.0.selectable', false);
    $this->getJson(route('inventory.lots.options', ['product' => $product->id, 'branch_id' => $branch->id, 'context' => 'purchase']))
        ->assertOk()->assertJsonPath('data.0.selectable', true);
    $this->getJson(route('inventory.lots.index', $product))->assertOk()->assertJsonPath('summary.depleted', '1');
});


it('avisa al agotar un lote con un ajuste y lo incluye en las alertas operativas', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch, 'G260427D03');
    $user = ManagedLotScenario::user($branch, 'ajuste-agota-lote', ['inventory.products.view', 'inventory.adjustments.manage', 'alerts.view']);
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id, $lot->id, '0.300', 'test_entry', $lot, 'test-alert-in', $user->id));
    $this->actingAs($user)->post(route('inventory.adjustments.store'), [
        'branch_id' => $branch->id, 'product_id' => $product->id, 'lot_id' => $lot->id, 'adjustment_number' => 'AJUSTE-'.uniqid(),
        'type' => 'decrease', 'meters' => '0.300', 'reason' => 'Fin de bobina',
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($message) => str_contains($message, 'G260427D03'));
    expect($lot->fresh()->status)->toBe('depleted')->and($lot->fresh()->available_meters)->toBe('0.000');
    $this->get(route('alerts.index', ['type' => 'depleted_lot']))->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Alerts/Index', false)->has('alerts.data', 1)->where('alerts.data.0.title', 'Lote en 0 (Agotado)'));
});
