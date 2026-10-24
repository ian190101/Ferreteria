<?php

use App\Modules\Inventory\Services\LotInventoryService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Support\SystemCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagedLotScenario;

it('ofrece al POS solo los lotes activos del producto y sucursal autorizados', function () {
    ManagedLotScenario::profile();
    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'pos-lotes-prueba', ['sales.manage']);
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
        $lot->id, '4.000', 'purchase_lot_entry', $lot, 'pos-test-in', $user->id));

    $this->actingAs($user)->getJson(route('inventory.lots.options', $product).'?branch_id='.$branch->id.'&context=pos')
        ->assertOk()->assertJsonPath('data.0.lot_id', $lot->id)
        ->assertJsonPath('data.0.lot_code', 'LT2026A01');
});

it('cobra en POS y descuenta únicamente el lote seleccionado', function () {
    $profile = ManagedLotScenario::profile();
    $configuration = $profile->configuration;
    $configuration['modules']['pos'] = true;
    $configuration['modules']['sales_notes'] = true;
    $configuration['capabilities']['uses_pos'] = true;
    $configuration['capabilities']['requires_cash_session'] = false;
    $configuration['sales']['workflow'] = 'pos';
    $configuration['sales']['quotation_mode'] = 'disabled';
    $configuration['sales']['customer_mode'] = 'optional';
    $configuration['cash']['required_to_sell'] = false;
    $profile->configuration = $configuration;
    $profile->save();
    SystemCacheInvalidator::bumpOperational();

    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $lot = ManagedLotScenario::lot($product, $branch);
    $user = ManagedLotScenario::user($branch, 'pos-lotes-http',
        ['sales.view', 'sales.manage', 'sales.prices.override']);
    $saleType = SaleType::query()->create(['name' => 'POS lote', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs',
        'exchange_rate_to_bob' => '1', 'is_base' => true, 'is_active' => true,
    ]);
    $paymentMethod = PaymentMethod::query()->create([
        'name' => 'Efectivo', 'code' => 'cash',
        'requires_reference' => false, 'is_active' => true,
    ]);
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
        $lot->id, '4.000', 'purchase_lot_entry', $lot, 'pos-http-in', $user->id));

    $this->actingAs($user)->post(route('sales.store'), [
        'from_pos' => true, 'document_type' => 'sale_note',
        'branch_id' => $branch->id, 'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id, 'customer_name' => 'Cliente POS',
        'advance_mode' => 'none', 'requires_delivery' => false,
        'receipt_number' => 'POS-LOT-'.uniqid(),
        'pos_payment_method_id' => $paymentMethod->id,
        'pos_payment_amount' => '6.00',
        'pos_context' => ['mode' => 'supermarket', 'channel' => 'counter'],
        'items' => [[
            'product_id' => $product->id, 'lot_id' => $lot->id,
            'description' => $product->name, 'unit_label' => 'm',
            'display_quantity' => '2.000', 'display_unit_label' => 'm',
            'calculation_mode' => 'direct', 'meters' => '2.000',
            'unit_price' => '3.0000', 'discount_amount' => '0.00',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $sale = Sale::query()->where('receipt_number', 'like', 'POS-LOT-%')->firstOrFail();
    expect((int) $sale->items()->firstOrFail()->product_coil_id)->toBe($lot->id)
        ->and((string) $lot->fresh()->available_meters)->toBe('2.000');
});

it('cobra una conversión en POS y avisa al agotar exactamente su bobina', function () {
    $profile = ManagedLotScenario::profile();
    $configuration = $profile->configuration;
    $configuration['modules']['pos'] = true;
    $configuration['modules']['sales_notes'] = true;
    $configuration['capabilities']['uses_pos'] = true;
    $configuration['capabilities']['requires_cash_session'] = false;
    $configuration['sales']['workflow'] = 'pos';
    $configuration['sales']['quotation_mode'] = 'disabled';
    $configuration['sales']['customer_mode'] = 'optional';
    $configuration['cash']['required_to_sell'] = false;
    $profile->configuration = $configuration;
    $profile->save();
    SystemCacheInvalidator::bumpOperational();

    $branch = ManagedLotScenario::branch();
    $product = ManagedLotScenario::product($branch);
    $sheet = \App\Modules\Inventory\Models\ProductUnit::query()->firstOrCreate(['symbol' => 'plancha'], ['name' => 'Plancha', 'is_active' => true]);
    $product->update(['allowed_units' => ['m', 'plancha']]);
    $product->unitConversions()->create(['product_unit_id' => $sheet->id, 'factor_to_base' => '2.000000', 'is_active' => true]);
    $lot = ManagedLotScenario::lot($product, $branch, 'G260427D03');
    $user = ManagedLotScenario::user($branch, 'pos-lotes-http',
        ['sales.view', 'sales.manage', 'sales.prices.override']);
    $saleType = SaleType::query()->create(['name' => 'POS lote', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs',
        'exchange_rate_to_bob' => '1', 'is_base' => true, 'is_active' => true,
    ]);
    $paymentMethod = PaymentMethod::query()->create([
        'name' => 'Efectivo', 'code' => 'cash',
        'requires_reference' => false, 'is_active' => true,
    ]);
    DB::transaction(fn () => app(LotInventoryService::class)->apply($product->id, $branch->id,
        $lot->id, '4.000', 'purchase_lot_entry', $lot, 'pos-http-in', $user->id));

    $this->actingAs($user)->post(route('sales.store'), [
        'from_pos' => true, 'document_type' => 'sale_note',
        'branch_id' => $branch->id, 'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id, 'customer_name' => 'Cliente POS',
        'advance_mode' => 'none', 'requires_delivery' => false,
        'receipt_number' => 'POS-LOT-'.uniqid(),
        'pos_payment_method_id' => $paymentMethod->id,
        'pos_payment_amount' => '12.00',
        'pos_context' => ['mode' => 'supermarket', 'channel' => 'counter'],
        'items' => [[
            'product_id' => $product->id, 'lot_id' => $lot->id,
            'description' => $product->name, 'unit_label' => 'm',
            'display_quantity' => '2.000', 'display_unit_label' => 'plancha',
            'calculation_mode' => 'direct', 'meters' => '0.001',
            'unit_price' => '3.0000', 'discount_amount' => '0.00',
        ]],
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($message) => str_contains($message, 'G260427D03'));

    $sale = Sale::query()->where('receipt_number', 'like', 'POS-LOT-%')->firstOrFail();
    expect((int) $sale->items()->firstOrFail()->product_coil_id)->toBe($lot->id)
        ->and((string) $lot->fresh()->available_meters)->toBe('0.000')->and($lot->fresh()->status)->toBe('depleted');
    $this->get(route('pos.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('Pos/Index', false)->where('products.0.unit_conversions.0.symbol', 'plancha')
        ->where('products.0.unit_conversions.0.factor_to_base', '2.000000'));
});
