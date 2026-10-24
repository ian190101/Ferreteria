<?php

use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ManagedLotScenario;

it('descuenta agregado y lote y rechaza una salida superior al saldo', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'ventas-lotes-prueba');
    $inventory = app(LotInventoryService::class);

    DB::transaction(function () use ($inventory, $product, $branch, $lot, $user): void {
        $inventory->apply($product->id, $branch->id, $lot->id, '5.000', 'purchase_lot_entry', $lot, 'sales-test-in', $user->id);
        $inventory->apply($product->id, $branch->id, $lot->id, '-2.000', 'sale_stock_out', $lot, 'sales-test-out', $user->id);
        expect((string) $lot->fresh()->available_meters)->toBe('3.000');
        expect(fn () => $inventory->apply($product->id, $branch->id, $lot->id, '-4.000',
            'sale_stock_out', $lot, 'sales-test-over', $user->id))->toThrow(ValidationException::class);
    });
});

it('impide que una venta global consuma una devolución histórica trazada con la regla apagada', function () {
    $profile = ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'ventas-historia-lotes');
    $configuration = $profile->configuration;
    $configuration['inventory']['lot_tracking_enabled'] = false;
    $profile->configuration = $configuration;
    $profile->save();
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
        $lot->id, '1.000', 'sale_return_entry', $lot, 'historic-return-entry', $user->id, true));

    $sale = Sale::query()->create([
        'branch_id' => $branch->id, 'user_id' => $user->id,
        'receipt_number' => 'HIST-LOT-'.uniqid(), 'sold_at' => now(),
        'document_type' => 'sale_note', 'status' => 'completed',
    ]);
    $sale->items()->create([
        'product_id' => $product->id, 'meters' => '1.000',
        'unit_price' => '3.0000', 'total' => '3.00',
    ]);
    $sale->load('items.product');
    expect(fn () => DB::transaction(fn () => app(SaleInventoryService::class)
        ->decrementForSale($sale, $user->id)))->toThrow(ValidationException::class);
    expect((string) $lot->fresh()->available_meters)->toBe('1.000')
        ->and((string) ProductBranchStock::query()->where('product_id', $product->id)
            ->value('available_meters'))->toBe('1.000');
});

it('registra una nota con su FK y snapshot y exige selección de lote por HTTP', function () {
    $profile = ManagedLotScenario::profile();
    $configuration = $profile->configuration;
    $configuration['sales']['workflow'] = 'direct_sale';
    $configuration['sales']['customer_mode'] = 'optional';
    $configuration['sales']['customer_required'] = false;
    $configuration['cash']['required_to_sell'] = false;
    $profile->configuration = $configuration;
    $profile->save();
    SystemCacheInvalidator::bumpOperational();

    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'ventas-lotes-http',
        ['sales.view', 'sales.manage', 'sales.prices.override']);
    $saleType = SaleType::query()->create(['name' => 'Venta lote', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs',
        'exchange_rate_to_bob' => '1', 'is_base' => true, 'is_active' => true,
    ]);
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
        $lot->id, '5.000', 'purchase_lot_entry', $lot, 'sales-http-in', $user->id));

    $payload = [
        'document_type' => 'sale_note', 'branch_id' => $branch->id,
        'sale_type_id' => $saleType->id, 'currency_id' => $currency->id,
        'receipt_number' => 'VENTA-LOT-'.uniqid(), 'customer_name' => 'Cliente lote',
        'sold_at' => now()->format('Y-m-d H:i:s'),
        'items' => [[
            'product_id' => $product->id, 'lot_id' => $lot->id,
            'description' => $product->name, 'unit_label' => 'm',
            'meters' => '2.000', 'unit_price' => '3.0000', 'discount_amount' => '0.00',
        ]],
    ];
    $this->actingAs($user)->post(route('sales.store'), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();
    $sale = Sale::query()->where('receipt_number', $payload['receipt_number'])->firstOrFail();
    expect((int) $sale->items()->firstOrFail()->product_coil_id)->toBe($lot->id)
        ->and($sale->items()->firstOrFail()->lot_code_snapshot)->toBe($lot->lot_code)
        ->and((string) $lot->fresh()->available_meters)->toBe('3.000')
        ->and((string) ProductBranchStock::query()->where('product_id', $product->id)
            ->value('available_meters'))->toBe('3.000');

    unset($payload['items'][0]['lot_id']);
    $payload['receipt_number'] = 'VENTA-LOT-'.uniqid();
    $this->actingAs($user)->post(route('sales.store'), $payload)
        ->assertSessionHasErrors('items.0.lot_id');
    expect(Sale::query()->where('receipt_number', $payload['receipt_number'])->exists())->toBeFalse();
});


it('vende con conversión exacta del producto y avisa al agotar el lote elegido', function () {
    $profile = ManagedLotScenario::profile();
    $configuration = $profile->configuration;
    $configuration['sales']['workflow'] = 'direct_sale';
    $configuration['sales']['customer_mode'] = 'optional';
    $configuration['sales']['customer_required'] = false;
    $configuration['cash']['required_to_sell'] = false;
    $profile->update(['configuration' => $configuration]);
    SystemCacheInvalidator::bumpOperational();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'venta-conversion-lote', ['inventory.products.view', 'inventory.products.manage', 'sales.view', 'sales.manage', 'sales.prices.override']);
    $draft = ManagedLotScenario::catalogPayload($branch);
    $draft['lot_registrations'][0]['initial_meters'] = '5.000';
    $draft['lot_registrations'][0]['initial_kg'] = '10.000';
    $this->actingAs($user)->post(route('inventory.products.store'), $draft)->assertSessionHasNoErrors();
    $product = \App\Modules\Inventory\Models\Product::query()->where('name', $draft['name'])->firstOrFail();
    $lot = $product->lots()->where('lot_code', 'G260427D03')->firstOrFail();
    $saleType = SaleType::query()->create(['name' => 'Venta conversión lote', 'is_active' => true]);
    $currency = Currency::query()->create(['name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs', 'exchange_rate_to_bob' => '1', 'is_base' => true, 'is_active' => true]);
    $this->post(route('sales.store'), [
        'document_type' => 'sale_note', 'branch_id' => $branch->id, 'sale_type_id' => $saleType->id, 'currency_id' => $currency->id,
        'receipt_number' => 'AGOTAR-'.uniqid(), 'customer_name' => 'Cliente lote', 'sold_at' => now()->format('Y-m-d H:i:s'),
        'items' => [['product_id' => $product->id, 'lot_id' => $lot->id, 'description' => $product->name, 'unit_label' => 'plancha', 'display_unit_label' => 'plancha',
            'display_quantity' => '2', 'calculation_mode' => 'direct', 'meters' => '0.001', 'unit_price' => '3.0000', 'discount_amount' => '0.00']],
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($message) => str_contains($message, 'G260427D03') && str_contains($message, 'Agotado'));
    expect($lot->fresh()->available_meters)->toBe('0.000')->and($lot->fresh()->status)->toBe('depleted')
        ->and((string) $product->branchStocks()->firstOrFail()->available_meters)->toBe('0.500')
        ->and(Sale::query()->latest('id')->firstOrFail()->items()->firstOrFail()->meters)->toBe('5.000');
    $sale = Sale::query()->latest('id')->firstOrFail();
    $this->get(route('sales.show', $sale))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('Sales/Show', false)->where('flash.warning', fn ($message) => str_contains($message, 'G260427D03')));
    $this->getJson(route('inventory.lots.options', ['product' => $product->id, 'branch_id' => $branch->id, 'context' => 'sale']))
        ->assertOk()->assertJsonFragment(['lot_code' => 'G260427D03', 'depleted' => true, 'selectable' => false]);
});
