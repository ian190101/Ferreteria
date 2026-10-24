<?php

use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Services\PurchaseReceiptService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagedLotScenario;

it('convierte unidades comerciales a metros y rechaza unidades habilitadas sin equivalencia', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $product->update(['allowed_units' => ['m', 'plancha', 'kg']]);
    $unit = \App\Modules\Inventory\Models\ProductUnit::query()->firstOrCreate(['symbol' => 'plancha'], ['name' => 'Plancha', 'is_active' => true]);
    $product->unitConversions()->create(['product_unit_id' => $unit->id, 'factor_to_base' => '2.500000', 'is_active' => true]);
    $user = ManagedLotScenario::user($branch, 'comprador-equivalencias', ['purchases.view', 'purchases.manage']);
    $payload = ['branch_id' => $branch->id, 'status' => 'received', 'items' => [[
        'product_id' => $product->id, 'calculation_mode' => 'direct', 'display_quantity' => '10',
        'display_unit_label' => 'plancha', 'unit_cost' => '2.0000', 'new_lot_code' => 'CONVERSION01',
    ]]];
    $this->actingAs($user)->post(route('purchases.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('25.000')
        ->and((string) $product->lots()->firstOrFail()->available_meters)->toBe('25.000');

    $payload['items'][0]['display_unit_label'] = 'kg';
    $payload['items'][0]['new_lot_code'] = 'SINCONVERSION';
    $this->actingAs($user)->postJson(route('purchases.store'), $payload)->assertUnprocessable();
    expect((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('25.000')
        ->and($product->lots()->count())->toBe(1);
});

it('recibe sobre una identidad existente sin crear otra bobina', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'compras-lotes-prueba');
    $product = ManagedLotScenario::product($branch);
    $purchase = Purchase::query()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'document_number' => 'COMPRA-LOT-'.uniqid(),
        'purchase_date' => now()->toDateString(),
        'total_amount' => '30.00',
        'paid_amount' => '0.00',
        'balance_due' => '30.00',
        'payment_status' => 'unpaid',
        'status' => 'received',
    ]);
    $first = $purchase->items()->create(['product_id' => $product->id, 'meters' => '10.000', 'unit_cost' => '2.0000']);
    $second = $purchase->items()->create(['product_id' => $product->id, 'meters' => '2.000', 'unit_cost' => '2.0000']);

    DB::transaction(function () use ($first, $second, $branch, $user): void {
        $service = app(PurchaseReceiptService::class);
        $lot = $service->receiveManaged($first, ['new_lot_code' => 'lt2026a01'], $branch->id, $user->id);
        $same = $service->receiveManaged($second, ['lot_id' => $lot->id], $branch->id, $user->id);
        expect($same->id)->toBe($lot->id)
            ->and((string) $same->available_meters)->toBe('12.000');
    });

    expect((string) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe('12.000');
});

it('compra por HTTP con lote nuevo y repone la misma identidad sin otra bobina', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $user = ManagedLotScenario::user($branch, 'compras-lotes-http', ['purchases.view', 'purchases.manage']);

    $this->actingAs($user)->post(route('purchases.store'), [
        'branch_id' => $branch->id, 'status' => 'received',
        'items' => [[
            'product_id' => $product->id, 'meters' => '2.000',
            'unit_cost' => '5.0000', 'new_lot_code' => 'lt2026g01',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $lot = \App\Modules\Inventory\Models\ProductCoil::query()->where('product_id', $product->id)
        ->where('lot_code', 'LT2026G01')->firstOrFail();
    $firstItem = Purchase::query()->latest('id')->firstOrFail()->items()->firstOrFail();
    expect((int) $firstItem->product_coil_id)->toBe($lot->id)
        ->and($firstItem->lot_number)->toBe('LT2026G01');

    $this->actingAs($user)->post(route('purchases.store'), [
        'branch_id' => $branch->id, 'status' => 'received',
        'items' => [[
            'product_id' => $product->id, 'meters' => '1.000',
            'unit_cost' => '5.0000', 'lot_id' => $lot->id,
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $lot->fresh()->available_meters)->toBe('3.000')
        ->and(\App\Modules\Inventory\Models\ProductCoil::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and((string) ProductBranchStock::query()->where('product_id', $product->id)
            ->value('available_meters'))->toBe('3.000');
});


it('alta rápida crea un producto y tres líneas de bobina sin duplicar existencias', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'compra-alta-multiple', ['purchases.view', 'purchases.manage']);
    $draft = ManagedLotScenario::catalogPayload($branch);
    $this->actingAs($user)->post(route('purchases.store'), [
        'branch_id' => $branch->id, 'status' => 'received',
        'items' => [['new_product' => $draft, 'display_unit_label' => 'm', 'description' => '', 'unit_cost' => '2.0000']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $product = \App\Modules\Inventory\Models\Product::query()->where('name', $draft['name'])->firstOrFail();
    $purchase = Purchase::query()->latest('id')->firstOrFail();
    expect(\App\Modules\Inventory\Models\Product::query()->where('name', $draft['name'])->count())->toBe(1)
        ->and($product->lots()->count())->toBe(3)->and($purchase->items()->count())->toBe(3)
        ->and((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('0.600')
        ->and((string) $purchase->total_amount)->toBe('1.20')
        ->and($product->lots()->where('lot_code', '12345')->firstOrFail()->initial_kg)->toBe('0.600')
        ->and((string) $product->unitConversions()->firstOrFail()->factor_to_base)->toBe('2.500000')
        ->and(\App\Modules\Inventory\Models\InventoryMovement::query()->where('product_id', $product->id)->count())->toBe(3);
});


it('recibe varios lotes de una orden y valida su cantidad acumulada antes de mutar stock', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $user = ManagedLotScenario::user($branch, 'recepcion-multilote', ['purchases.view', 'purchases.manage']);
    $order = \App\Modules\Purchases\Models\PurchaseOrder::query()->create([
        'branch_id' => $branch->id, 'user_id' => $user->id, 'order_number' => 'ORD-'.uniqid(),
        'ordered_at' => now(), 'total_amount' => '2.00', 'status' => 'approved',
    ]);
    $item = $order->items()->create(['product_id' => $product->id, 'meters' => '1.000', 'received_meters' => '0.000', 'unit_cost' => '2.0000']);
    $payload = ['items' => [
        ['purchase_order_item_id' => $item->id, 'meters' => '0.600', 'kilograms' => '0.001', 'weight_unit' => 'ton', 'new_lot_code' => 'G260427D03'],
        ['purchase_order_item_id' => $item->id, 'meters' => '0.600', 'kilograms' => '0.800', 'new_lot_code' => 'G260427D04'],
    ]];
    $this->actingAs($user)->post(route('purchases.orders.receipts.store', $order), $payload)->assertSessionHasErrors('items');
    expect($product->lots()->count())->toBe(0)->and(Purchase::query()->count())->toBe(0);
    $payload['items'][1]['meters'] = '0.400';
    $this->post(route('purchases.orders.receipts.store', $order), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect($product->lots()->count())->toBe(2)
        ->and($product->lots()->where('lot_code', 'G260427D03')->firstOrFail()->initial_kg)->toBe('1.000')
        ->and((string) $item->fresh()->received_meters)->toBe('1.000')
        ->and((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('1.000');
});
