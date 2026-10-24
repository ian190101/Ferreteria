<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Currency;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleType;
use App\Support\SystemCacheInvalidator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ManagedLotScenario;

function lotFlowContext(string $timing): array
{
    $profile = ManagedLotScenario::profile(true, true);
    $configuration = $profile->configuration;
    $configuration['sales']['workflow'] = 'direct_sale';
    $configuration['sales']['quotation_mode'] = 'optional';
    $configuration['sales']['customer_mode'] = 'optional';
    $configuration['sales']['customer_required'] = false;
    $configuration['sales']['inventory_discount_timing'] = $timing;
    $configuration['cash']['required_to_sell'] = false;
    $profile->update(['configuration' => $configuration]);
    SystemCacheInvalidator::bumpOperational();
    $branch = ManagedLotScenario::branch();
    $user = ManagedLotScenario::user($branch, 'auditor-flujo-lotes', [
        'inventory.products.view', 'inventory.products.manage', 'inventory.coils.manage',
        'inventory.movements.view', 'inventory.adjustments.manage', 'inventory.adjustments.view',
        'purchases.view', 'purchases.manage', 'sales.view', 'sales.manage', 'sales.prices.override',
        'sales.returns.view', 'sales.returns.manage', 'sales.deliveries.view', 'sales.deliveries.manage',
        'payments.view', 'payments.manage',
    ]);
    $type = SaleType::query()->create(['name' => 'Venta ejemplo '.$timing, 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs',
        'exchange_rate_to_bob' => '1.000000', 'is_base' => true, 'is_active' => true,
    ]);

    return compact('branch', 'user', 'type', 'currency');
}

function lotFlowSalePayload(array $context, Product $product, ProductCoil $lot, array $item = [], array $header = []): array
{
    return array_replace([
        'document_type' => 'sale_note', 'branch_id' => $context['branch']->id,
        'sale_type_id' => $context['type']->id, 'currency_id' => $context['currency']->id,
        'receipt_number' => 'FLUJO-'.uniqid(), 'customer_name' => 'Cliente ejemplo',
        'sold_at' => now()->format('Y-m-d H:i:s'),
        'items' => [array_replace([
            'product_id' => $product->id, 'lot_id' => $lot->id, 'description' => $product->name,
            'display_unit_label' => 'm', 'unit_label' => 'm', 'calculation_mode' => 'direct',
            'display_quantity' => '10.000', 'unit_price' => '3.0000', 'discount_amount' => '0.00',
        ], $item)],
    ], $header);
}

function assertLotFlowBalances(Product $product, string $physical, string $reserved = '0.000'): void
{
    $stock = $product->branchStocks()->sole();
    $lots = $product->lots()->orderBy('id')->get();
    $sum = $lots->reduce(fn (string $total, ProductCoil $lot) => bcadd($total, (string) $lot->available_meters, 3), '0.000');
    $committed = (string) InventoryReservation::query()->where('product_id', $product->id)
        ->where('status', 'active')->sum('remaining_meters');
    expect((string) $stock->available_meters)->toBe($physical)->and($sum)->toBe($physical)
        ->and((string) $stock->reserved_meters)->toBe($reserved)->and(bcadd($committed, '0', 3))->toBe($reserved);

    $previous = '0.000';
    $lotBalances = [];
    foreach (InventoryMovement::query()->where('product_id', $product->id)->orderBy('id')->get() as $movement) {
        expect((string) $movement->meters_before)->toBe($previous)
            ->and((string) $movement->lot_quantity_before)->toBe($lotBalances[$movement->product_coil_id] ?? '0.000')
            ->and((string) $movement->meters_after)->toBe(bcadd($previous, (string) $movement->meters_delta, 3))
            ->and((string) $movement->lot_quantity_after)->toBe(bcadd((string) $movement->lot_quantity_before, (string) $movement->meters_delta, 3))
            ->and($movement->lot_code_snapshot)->not->toBeNull()->and($movement->idempotency_key)->not->toBeNull();
        $previous = (string) $movement->meters_after;
        $lotBalances[$movement->product_coil_id] = (string) $movement->lot_quantity_after;
    }
    expect($previous)->toBe($physical);
}

it('ejemplo 1 compra tres lotes vende por largo devuelve parcialmente y revierte sin duplicar kardex', function () {
    $context = lotFlowContext('sale_note');
    $this->actingAs($context['user']);
    $draft = ManagedLotScenario::catalogPayload($context['branch']);
    $draft['name'] = 'Calamina roja ejemplo 1';
    foreach ($draft['lot_registrations'] as $index => &$row) {
        $row['initial_meters'] = ['100.000', '50.000', '25.000'][$index];
        $row['initial_kg'] = ['200.000', '100.000', '50.000'][$index];
    }
    unset($row);
    $this->post(route('purchases.store'), [
        'branch_id' => $context['branch']->id, 'status' => 'received',
        'items' => [['new_product' => $draft, 'display_unit_label' => 'm', 'unit_cost' => '2.0000']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $product = Product::query()->where('name', $draft['name'])->sole();
    $purchase = Purchase::query()->sole();
    $lot = $product->lots()->where('lot_code', 'G260427D03')->sole();
    assertLotFlowBalances($product, '175.000');
    $this->get(route('inventory.stock.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Stock/Index', false)->where('globalStocks.data.0.available_meters', '175.000')->has('coilStocks', 0)->has('coilSummary', 3));
    $this->get(route('purchases.show', $purchase))->assertOk();
    $this->post(route('sales.store'), lotFlowSalePayload($context, $product, $lot, [
        'calculation_mode' => 'length', 'display_quantity' => '10', 'base_quantity' => '3.000',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $sale = Sale::query()->where('document_type', 'sale_note')->sole();
    $item = $sale->items()->sole();
    assertLotFlowBalances($product, '145.000');
    $this->post(route('sales.returns.store'), [
        'sale_id' => $sale->id, 'return_number' => 'EJ1-DEV', 'reason' => 'Devolución parcial',
        'items' => [['sale_item_id' => $item->id, 'meters' => '12.000']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '157.000');
    $this->patch(route('purchases.void', $purchase), ['reason' => 'No debe anular con consumo pendiente'])
        ->assertSessionHasErrors();
    assertLotFlowBalances($product, '157.000');
    $this->patch(route('sales.void', $sale), ['reason' => 'Revertir únicamente los 18 metros restantes'])
        ->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '175.000');
    $this->patch(route('purchases.void', $purchase), ['reason' => 'Toda salida fue restituida'])
        ->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '0.000');
    expect($product->lots()->count())->toBe(3)->and($product->lots()->whereNotNull('voided_at')->count())->toBe(0);
    $this->get(route('inventory.stock.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Stock/Index', false)->where('globalStocks.data.0.available_meters', '0.000')->has('coilStocks', 0));
    $this->get(route('inventory.movements.index', ['product_id' => $product->id, 'per_page' => 100]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/Movements/Index', false)
            ->has('movements.data', 9)->where('movements.data.0.product.base_unit', 'm'));
});

it('ejemplo 2 compra por peso cobra sin doble salida agota reingresa y ajusta el mismo lote', function () {
    $context = lotFlowContext('payment');
    $this->actingAs($context['user']);
    $draft = ManagedLotScenario::catalogPayload($context['branch']);
    $draft['name'] = 'Calamina azul ejemplo 2';
    $draft['lot_registrations'] = [];
    $this->post(route('inventory.products.store'), $draft)->assertRedirect()->assertSessionHasNoErrors();
    $product = Product::query()->where('name', $draft['name'])->sole();
    $this->post(route('purchases.store'), [
        'branch_id' => $context['branch']->id, 'status' => 'received', 'items' => [[
            'product_id' => $product->id, 'calculation_mode' => 'weight', 'kilograms' => '0.100',
            'weight_unit' => 'ton', 'display_unit_label' => 'm', 'unit_cost' => '2.0000', 'new_lot_code' => 'AZUL260424A1',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $lot = $product->lots()->sole();
    expect($lot->initial_kg)->toBe('100.000');
    assertLotFlowBalances($product, '50.000');
    $this->post(route('sales.store'), lotFlowSalePayload($context, $product, $lot, [
        'calculation_mode' => 'weight', 'entered_weight' => '100.000', 'weight_unit' => 'kg',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $sale = Sale::query()->sole();
    assertLotFlowBalances($product, '50.000', '50.000');
    $method = PaymentMethod::query()->create(['name' => 'Efectivo ejemplo', 'code' => 'cash', 'is_active' => true]);
    $this->post(route('payments.store'), ['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'amount' => '50.00'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning');
    assertLotFlowBalances($product, '0.000');
    expect($lot->fresh()->status)->toBe('depleted');
    $this->post(route('payments.store'), ['sale_id' => $sale->id, 'payment_method_id' => $method->id, 'amount' => '100.00'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(InventoryMovement::query()->where('product_id', $product->id)->where('type', 'sale_stock_out')->count())->toBe(1);
    $this->post(route('purchases.store'), [
        'branch_id' => $context['branch']->id, 'status' => 'received', 'items' => [[
            'product_id' => $product->id, 'display_quantity' => '7.500', 'display_unit_label' => 'm',
            'unit_cost' => '2.0000', 'kilograms' => '15.000', 'lot_id' => $lot->id,
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '7.500');
    $this->post(route('inventory.adjustments.store'), [
        'branch_id' => $context['branch']->id, 'product_id' => $product->id, 'lot_id' => $lot->id,
        'adjustment_number' => 'EJ2-AJUSTE', 'type' => 'decrease', 'meters' => '0.125', 'reason' => 'Merma medida',
    ])->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '7.375');
    expect($product->lots()->count())->toBe(1)->and($lot->fresh()->initial_meters)->toBe('50.000');
    $admin = ManagedLotScenario::user($context['branch'], \App\Support\SystemRoles::BUSINESS_ADMIN);
    $this->actingAs($admin)->post(route('inventory.lots.void', [$product, $lot]), ['void_reason' => 'Retirar saldo de bobina'])
        ->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '0.000');
    $this->actingAs($context['user'])->post(route('sales.returns.store'), [
        'sale_id' => $sale->id, 'return_number' => 'EJ2-DEV-BLOQUEADA', 'reason' => 'Devolución a su identidad original',
        'items' => [['sale_item_id' => $sale->items()->sole()->id, 'meters' => '5.000']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '5.000');
    expect($lot->fresh()->voided_at)->not->toBeNull();
    $this->getJson(route('inventory.lots.options', ['product' => $product, 'branch_id' => $context['branch']->id, 'context' => 'sale']))
        ->assertOk()->assertJsonMissing(['lot_code' => $lot->lot_code]);
    $adjustment = ['branch_id' => $context['branch']->id, 'product_id' => $product->id, 'lot_id' => $lot->id,
        'adjustment_number' => 'EJ2-BAJA-BLOQUEADA', 'type' => 'decrease', 'meters' => '5.000', 'reason' => 'Retiro de stock bloqueado'];
    $this->postJson(route('inventory.adjustments.store'), $adjustment)->assertForbidden();
    $this->actingAs($admin)->post(route('inventory.adjustments.store'), $adjustment)->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '0.000');
});

it('ejemplo 3 cotiza reserva convierte despacha por equivalencia y devuelve solo salidas reales', function () {
    $context = lotFlowContext('delivery');
    $this->actingAs($context['user']);
    $draft = ManagedLotScenario::catalogPayload($context['branch']);
    $draft['name'] = 'Calamina verde ejemplo 3';
    $draft['lot_registrations'] = [];
    $this->post(route('inventory.products.store'), $draft)->assertRedirect()->assertSessionHasNoErrors();
    $product = Product::query()->where('name', $draft['name'])->sole();
    $this->post(route('purchases.store'), [
        'branch_id' => $context['branch']->id, 'status' => 'received', 'items' => [[
            'product_id' => $product->id, 'display_quantity' => '20', 'display_unit_label' => 'plancha',
            'unit_cost' => '2.0000', 'new_lot_code' => 'VERDE260424A1',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $lot = $product->lots()->sole();
    $this->post(route('sales.store'), lotFlowSalePayload($context, $product, $lot, [
        'display_quantity' => '12', 'display_unit_label' => 'plancha', 'unit_label' => 'plancha',
    ], ['document_type' => 'quotation', 'requires_delivery' => true]))->assertRedirect()->assertSessionHasNoErrors();
    $quotation = Sale::query()->where('document_type', 'quotation')->sole();
    assertLotFlowBalances($product, '50.000', '30.000');
    $this->post(route('sales.store'), lotFlowSalePayload($context, $product, $lot, [], ['document_type' => 'quotation']))
        ->assertRedirect()->assertSessionHasNoErrors();
    $cancelledQuotation = Sale::query()->where('document_type', 'quotation')->latest('id')->firstOrFail();
    assertLotFlowBalances($product, '50.000', '40.000');
    $this->patch(route('sales.void', $cancelledQuotation), ['reason' => 'Cancelar cotización y liberar reserva'])
        ->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '50.000', '30.000');
    $this->post(route('sales.convert', $quotation), ['requires_delivery' => true])->assertRedirect()->assertSessionHasNoErrors();
    $sale = Sale::query()->where('document_type', 'sale_note')->sole();
    $item = $sale->items()->sole();
    assertLotFlowBalances($product, '50.000', '30.000');
    $this->patchJson(route('sales.void', $quotation), ['reason' => 'No liberar compromisos de una cotización convertida'])
        ->assertUnprocessable();
    assertLotFlowBalances($product, '50.000', '30.000');
    $this->post(route('sales.store'), lotFlowSalePayload($context, $product, $lot, [], ['requires_delivery' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $cancelledSale = Sale::query()->where('document_type', 'sale_note')->latest('id')->firstOrFail();
    assertLotFlowBalances($product, '50.000', '40.000');
    $this->patch(route('sales.void', $cancelledSale), ['reason' => 'Cancelar venta sin salida física'])
        ->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '50.000', '30.000');
    $returnPayload = ['sale_id' => $sale->id, 'return_number' => 'EJ3-DEV', 'reason' => 'Retorno real',
        'items' => [['sale_item_id' => $item->id, 'meters' => '2.500']]];
    $this->get(route('sales.returns.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Sales/Returns/Index', false)->has('saleItems', 0));
    $this->postJson(route('sales.returns.store'), $returnPayload)->assertUnprocessable();
    expect(SaleReturn::query()->count())->toBe(0);
    assertLotFlowBalances($product, '50.000', '30.000');
    $delivery = ['sale_id' => $sale->id, 'delivery_number' => 'EJ3-DESP1', 'recipient_name' => 'Cliente ejemplo',
        'items' => [['sale_item_id' => $item->id, 'meters' => '10.000']]];
    $this->post(route('sales.deliveries.store'), $delivery)->assertRedirect()->assertSessionHasNoErrors();
    expect(\App\Modules\Sales\Models\DeliveryNoteItem::query()->sole()->display_quantity)->toBe('4.000');
    assertLotFlowBalances($product, '40.000', '20.000');
    $this->post(route('sales.returns.store'), $returnPayload)->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '42.500', '20.000');
    $delivery['delivery_number'] = 'EJ3-DESP2';
    unset($delivery['items'][0]['meters']);
    $delivery['items'][0]['quantity'] = '8';
    $this->post(route('sales.deliveries.store'), $delivery)->assertRedirect()->assertSessionHasNoErrors();
    assertLotFlowBalances($product, '22.500');
    $this->get(route('sales.deliveries.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Sales/Deliveries/Index', false)->where('saleItems', fn ($items) => count($items) === 0));
});
