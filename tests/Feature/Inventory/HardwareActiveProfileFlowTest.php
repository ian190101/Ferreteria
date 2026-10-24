<?php

declare(strict_types=1);

use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\InventoryTransfer;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\SystemCacheInvalidator;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\HardwareActiveProfileScenario as Hardware;
use Tests\Support\ManagedLotScenario;

beforeEach(function () {
    $this->hardware = Hardware::prepare($this);
});

afterEach(function () {
    expect($this->hardware['profile']->fresh()->configuration)->toBe($this->hardware['configuration']);
});

it('ferretería actual exige cotización cliente y caja sin reservar automáticamente ni descontar dos veces', function () {
    $context = $this->hardware;
    expect(ActiveBusinessProfile::payload()['businessType'])->toBe('hardware_store')
        ->and(ActiveBusinessProfile::payload()['sales']['workflow'])->toBe('quotation_to_sale_note')
        ->and(ActiveBusinessProfile::payload()['modules']['pos'])->toBeFalse()
        ->and(ActiveBusinessProfile::payload()['modules']['reservations'])->toBeFalse();
    $product = Hardware::product($this, $context, 'Calamina ferretería actual');
    Hardware::receive($this, $context, $product, 'FER240426A01', '50.000');
    $lot = $product->lots()->sole();
    $payload = Hardware::quotationPayload($context, $product, $lot);
    $direct = $payload;
    $direct['document_type'] = 'sale_note';
    $this->postJson(route('sales.store'), $direct)->assertUnprocessable()->assertJsonValidationErrors('source_quotation_id');
    $withoutCustomer = $payload;
    unset($withoutCustomer['customer_name']);
    $this->postJson(route('sales.store'), $withoutCustomer)->assertUnprocessable()->assertJsonValidationErrors('customer_name');
    $quotation = Hardware::quote($this, $context, $product, $lot);
    Hardware::stock($product, '50.000');
    expect(InventoryReservation::query()->count())->toBe(0);
    $this->postJson(route('sales.convert', $quotation))->assertUnprocessable()->assertJsonValidationErrors('cash_session');
    Hardware::openCash($this, $context);
    $sale = Hardware::convert($this, $quotation);
    Hardware::stock($product, '40.000');
    expect($sale->items()->sole()->product_coil_id)->toBe($lot->id)
        ->and($sale->items()->sole()->lot_code_snapshot)->toBe($lot->lot_code);
    $this->postJson(route('sales.convert', $quotation))->assertUnprocessable();
    expect(Sale::query()->where('document_type', 'sale_note')->count())->toBe(1);
    Hardware::stock($product, '40.000');
});

it('ferretería actual revalida dos cotizaciones competidoras antes de convertir y conserva el lote elegido', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina cotizaciones competidoras');
    Hardware::receive($this, $context, $product, 'FER240426B01', '10.000');
    $lot = $product->lots()->sole();
    $first = Hardware::quote($this, $context, $product, $lot, '8.000');
    $second = Hardware::quote($this, $context, $product, $lot, '8.000');
    Hardware::openCash($this, $context);
    Hardware::convert($this, $first);
    Hardware::stock($product, '2.000');
    $this->postJson(route('sales.convert', $second))->assertUnprocessable();
    expect($second->fresh()->status)->toBe('quoted')
        ->and(InventoryReservation::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->count())->toBe(1);
    Hardware::stock($product, '2.000');
});

it('ferretería actual cobra efectivo QR transferencia revierte un cobro despacha y cierra caja sin duplicar stock', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina cobros y despacho');
    Hardware::receive($this, $context, $product, 'FER240426C01', '20.000');
    $quotation = Hardware::quote($this, $context, $product, $product->lots()->sole());
    $session = Hardware::openCash($this, $context);
    $sale = Hardware::convert($this, $quotation);
    foreach (['cash', 'qr', 'transfer'] as $code) {
        $this->post(route('payments.store'), [
            'sale_id' => $sale->id, 'payment_method_id' => $context['methods'][$code]->id,
            'amount' => '10.00', 'reference' => 'FER-PAGO-'.$code,
        ])->assertRedirect()->assertSessionHasNoErrors();
        Hardware::stock($product, '10.000');
    }
    expect((string) $context['bank']->fresh()->current_balance)->toBe('1020.00')
        ->and((string) $sale->fresh()->balance_due)->toBe('0.00');
    $qrPayment = SalePayment::query()->where('payment_method_id', $context['methods']['qr']->id)->sole();
    $this->patch(route('payments.void', $qrPayment), ['reason' => 'Corregir el medio de cobro'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $context['bank']->fresh()->current_balance)->toBe('1010.00')
        ->and((string) $sale->fresh()->balance_due)->toBe('10.00');
    $this->post(route('payments.store'), [
        'sale_id' => $sale->id, 'payment_method_id' => $context['methods']['cash']->id, 'amount' => '10.00',
    ])->assertRedirect()->assertSessionHasNoErrors();
    foreach (['FER-DESP-1', 'FER-DESP-2'] as $number) {
        $this->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id, 'delivery_number' => $number,
            'items' => [['sale_item_id' => $sale->items()->sole()->id, 'meters' => '5.000']],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    Hardware::stock($product, '10.000');
    expect(InventoryMovement::query()->where('type', 'sale_stock_out')->count())->toBe(1)
        ->and(InventoryMovement::query()->where('type', 'delivery_stock_out')->count())->toBe(0);
    $cashCount = array_fill_keys(array_keys(CashRegisterSession::CASH_DENOMINATIONS), 0);
    $cashCount['bill_100'] = 1;
    $cashCount['bill_20'] = 1;
    $this->put(route('cash.close', $session), ['cash_count' => $cashCount])->assertRedirect()->assertSessionHasNoErrors();
    expect($session->fresh()->expected_cash_amount)->toBe('120.00')
        ->and($session->fresh()->difference_amount)->toBe('0.00')
        ->and($session->fresh()->bank_income_amount)->toBe('10.00');
});

it('ferretería actual recibe una orden parcialmente repone el mismo lote y revierte solo la recepción elegida', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina orden parcial');
    $this->post(route('purchases.orders.store'), [
        'branch_id' => $context['branch']->id, 'order_number' => 'FER-OC-001',
        'ordered_at' => now()->toDateString(), 'status' => 'approved',
        'items' => [['product_id' => $product->id, 'meters' => '100.000', 'unit_cost' => '2.0000', 'new_lot_code' => 'FER240426D01']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();
    $item = $order->items()->sole();
    Hardware::stock($product, '0.000');
    $this->post(route('purchases.orders.receipts.store', $order), ['items' => [[
        'purchase_order_item_id' => $item->id, 'meters' => '33.333', 'new_lot_code' => 'FER240426D01',
    ]]])->assertRedirect()->assertSessionHasNoErrors();
    $firstReceipt = $order->receipts()->sole();
    $lot = $product->lots()->sole();
    Hardware::stock($product, '33.333');
    $this->post(route('purchases.orders.receipts.store', $order), ['items' => [[
        'purchase_order_item_id' => $item->id, 'meters' => '66.668', 'lot_id' => $lot->id,
    ]]])->assertSessionHasErrors('items.0.meters');
    Hardware::stock($product, '33.333');
    $this->post(route('purchases.orders.receipts.store', $order), ['items' => [[
        'purchase_order_item_id' => $item->id, 'meters' => '66.667', 'lot_id' => $lot->id,
    ]]])->assertRedirect()->assertSessionHasNoErrors();
    Hardware::stock($product, '100.000');
    expect((string) $item->fresh()->received_meters)->toBe('100.000')->and($product->lots()->count())->toBe(1);
    $this->patch(route('purchases.void', $firstReceipt->purchase_id), ['reason' => 'Revertir solo la primera recepción'])
        ->assertRedirect()->assertSessionHasNoErrors();
    Hardware::stock($product, '66.667');
    expect((string) $item->fresh()->received_meters)->toBe('66.667')->and($lot->fresh()->voided_at)->toBeNull();
});

it('ferretería actual registra pago a proveedor y su anulación integral con bancos gastos y kardex conciliados', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina proveedor');
    $purchase = Hardware::receive($this, $context, $product, 'FER240426E01', '10.000');
    Hardware::openCash($this, $context);
    $this->post(route('payments.purchase-payments.store'), [
        'purchase_id' => $purchase->id, 'payment_method_id' => $context['methods']['transfer']->id,
        'amount' => '12.00', 'reference' => 'FER-PROV-001',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $context['bank']->fresh()->current_balance)->toBe('988.00')
        ->and((string) $purchase->fresh()->balance_due)->toBe('8.00');
    Hardware::stock($product, '10.000');
    $payment = $purchase->payments()->sole();
    $this->patch(route('payments.purchase-payments.void', $payment), ['reason' => 'Anular pago y compra local de prueba'])
        ->assertRedirect()->assertSessionHasNoErrors();
    Hardware::stock($product, '0.000');
    expect((string) $context['bank']->fresh()->current_balance)->toBe('1000.00')
        ->and($purchase->fresh()->status)->toBe('voided')
        ->and(BankTransaction::query()->where('status', 'registered')->count())->toBe(0)
        ->and(Expense::query()->where('status', Expense::STATUS_REGISTERED)->count())->toBe(0);
});

it('ferretería actual calcula órdenes por kg por metro sin acumular el redondeo del factor inverso', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina peso exacto de orden');
    $product->thickness()->firstOrFail()->update(['kg_per_meter' => '3.130000', 'kg_to_meter_factor' => '0.319489']);
    $this->post(route('purchases.orders.store'), [
        'branch_id' => $context['branch']->id, 'order_number' => 'FER-OC-PESO',
        'ordered_at' => now()->toDateString(), 'status' => 'approved',
        'items' => [['product_id' => $product->id, 'kilograms' => '4428.000', 'weight_unit' => 'kg',
            'unit_cost' => '2.0000', 'new_lot_code' => 'FER240426P01']],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();
    expect($order->items()->sole()->meters)->toBe('1414.696');
    $this->post(route('purchases.orders.receipts.store', $order), ['items' => [[
        'purchase_order_item_id' => $order->items()->sole()->id, 'kilograms' => '31.300',
        'weight_unit' => 'kg', 'new_lot_code' => 'FER240426P01',
    ]]])->assertRedirect()->assertSessionHasNoErrors();
    Hardware::stock($product, '10.000');
});

it('ferretería actual permite transferir stock simple y bloquea transferir lotes sin alterar existencias', function () {
    $context = $this->hardware;
    $destination = ManagedLotScenario::branch();
    $context['user']->accessibleBranches()->sync([$context['branch']->id, $destination->id]);
    $context['user']->unsetRelation('accessibleBranches');
    SystemCacheInvalidator::bumpOperational();
    $simple = Hardware::product($this, $context, 'Tornillos sin lote', false);
    $simple->branchStocks()->firstOrCreate(['branch_id' => $destination->id], ['available_meters' => '0', 'reserved_meters' => '0', 'is_enabled' => true]);
    Hardware::receive($this, $context, $simple, '', '20.000');
    $payload = ['from_branch_id' => $context['branch']->id, 'to_branch_id' => $destination->id,
        'product_id' => $simple->id, 'transfer_number' => 'FER-TR-001', 'meters' => '5.000', 'reason' => 'Reposición de sucursal'];
    $this->post(route('inventory.transfers.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect((string) $simple->branchStocks()->where('branch_id', $context['branch']->id)->sole()->available_meters)->toBe('15.000')
        ->and((string) $simple->branchStocks()->where('branch_id', $destination->id)->sole()->available_meters)->toBe('5.000');
    $managed = Hardware::product($this, $context, 'Calamina no transferible por ruta global');
    Hardware::receive($this, $context, $managed, 'FER240426F01', '20.000');
    $payload['product_id'] = $managed->id;
    $payload['transfer_number'] = 'FER-TR-002';
    $this->postJson(route('inventory.transfers.store'), $payload)->assertUnprocessable();
    expect(InventoryTransfer::query()->count())->toBe(1)
        ->and((string) $managed->lots()->sole()->available_meters)->toBe('20.000');
});

it('ferretería actual cotiza y vende un servicio permitido sin mover el inventario del producto físico', function () {
    $context = $this->hardware;
    $physical = Hardware::product($this, $context, 'Calamina con servicio de corte');
    Hardware::receive($this, $context, $physical, 'FER240426G01', '20.000');
    $service = Hardware::product($this, $context, 'Servicio de corte', false, true);
    $quotation = Hardware::quote($this, $context, $service, null, '2.000');
    Hardware::openCash($this, $context);
    $sale = Hardware::convert($this, $quotation);
    expect((string) $sale->total)->toBe('6.00')
        ->and(InventoryMovement::query()->where('product_id', $service->id)->count())->toBe(0);
    Hardware::stock($physical, '20.000');
});

it('ferretería actual conserva dos lotes del mismo producto en una cotización mixta con servicio de corte', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina dos bobinas y corte');
    Hardware::receive($this, $context, $product, 'FER240426M01', '10.000');
    Hardware::receive($this, $context, $product, 'FER240426M02', '15.000');
    $lots = $product->lots()->orderBy('id')->get();
    $service = Hardware::product($this, $context, 'Corte en cotización mixta', false, true);
    $payload = Hardware::quotationPayload($context, $product, $lots[0], '4.000');
    $payload['items'][] = array_replace($payload['items'][0], ['lot_id' => $lots[1]->id, 'display_quantity' => '6.000']);
    $payload['items'][] = Hardware::quotationPayload($context, $service, null, '2.000')['items'][0];
    $this->post(route('sales.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $quotation = Sale::query()->where('receipt_number', $payload['receipt_number'])->sole();
    expect($quotation->items()->count())->toBe(3)->and(InventoryReservation::query()->count())->toBe(0);
    Hardware::openCash($this, $context);
    $sale = Hardware::convert($this, $quotation);
    expect($sale->items()->count())->toBe(3)->and($sale->total)->toBe('36.00')
        ->and($lots[0]->fresh()->available_meters)->toBe('6.000')
        ->and($lots[1]->fresh()->available_meters)->toBe('9.000')
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->count())->toBe(2);
    Hardware::stock($product, '15.000');
});

it('ferretería actual exporta stock una sola vez y protege los reportes de otra sucursal', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina reportes de ferretería');
    Hardware::receive($this, $context, $product, 'FER240426H01', '10.000');
    Hardware::receive($this, $context, $product, 'FER240426H02', '5.000');
    $request = Request::create('/exports', 'GET', ['modules' => ['inventory'], 'fields' => ['inventory' => ['available']]]);
    $request->setUserResolver(fn () => $context['user']);
    $dataset = app(ExportDatasetService::class)->build($request);
    expect($dataset['sections'][0]['rows'])->toHaveCount(1)
        ->and((string) $dataset['sections'][0]['rows'][0][0])->toBe('15');
    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index', false)->where('metrics.active_coils', 2)->where('profileFeatures.production', false));
    $this->get(route('dashboard'))->assertOk();
    $other = ManagedLotScenario::branch();
    $this->getJson(route('reports.index', ['branch_id' => $other->id]))->assertForbidden();
    $this->getJson(route('dashboard', ['branch_id' => $other->id]))->assertForbidden();
});

it('ferretería actual excluye cotizaciones y notas anuladas de las ventas y utilidades de reportes', function () {
    // Las páginas modulares están fuera del directorio convencional que usa el recargador de pruebas.
    config(['inertia.testing.ensure_pages_exist' => false]);
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina ventas reales en reportes');
    Hardware::receive($this, $context, $product, 'FER240426R01', '10.000');
    $lot = $product->lots()->sole();
    $quotation = Hardware::quote($this, $context, $product, $lot, '4.000');
    Hardware::openCash($this, $context);
    $sale = Hardware::convert($this, $quotation);
    Hardware::quote($this, $context, $product, $lot, '2.000');
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Index', false)
        ->loadDeferredProps('dashboard-metrics', fn (Assert $partial) => $partial
            ->where('metrics.sales_range_total', 12)->where('metrics.sales_range_count', 1))
        ->loadDeferredProps('dashboard-charts', fn (Assert $partial) => $partial
            ->where('charts.topProducts.0.value', 4)));
    $this->get(route('reports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index', false)->where('metrics.sales_total', 12)->where('metrics.sales_count', 1)
        ->where('metrics.quotations_count', 2)->where('metrics.gross_profit', 4));
    $this->patch(route('sales.void', $sale), ['reason' => 'La venta anulada deja de generar ingresos'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('reports.index', ['from' => now()->subDay()->toDateString()]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Index', false)->where('metrics.sales_total', 0)->where('metrics.sales_count', 0)
        ->where('metrics.gross_profit', 0));
    Hardware::stock($product, '10.000');
});

it('ferretería actual rechaza notación exponencial en órdenes y recepciones sin crear documentos ni stock', function () {
    $context = $this->hardware;
    $product = Hardware::product($this, $context, 'Calamina validación decimal');
    $payload = ['branch_id' => $context['branch']->id, 'order_number' => 'FER-OC-DECIMAL',
        'ordered_at' => now()->toDateString(), 'status' => 'approved',
        'items' => [['product_id' => $product->id, 'kilograms' => '1e2', 'unit_cost' => '2.0000', 'new_lot_code' => 'FER240426X01']]];
    $this->postJson(route('purchases.orders.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.kilograms');
    expect(PurchaseOrder::query()->count())->toBe(0);
    $payload['items'][0]['kilograms'] = '100.000';
    $payload['items'][0]['unit_cost'] = '2e0';
    $this->postJson(route('purchases.orders.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_cost');
    $payload['items'][0]['unit_cost'] = '2.0000';
    $this->post(route('purchases.orders.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $order = PurchaseOrder::query()->sole();
    $this->postJson(route('purchases.orders.receipts.store', $order), ['items' => [[
        'purchase_order_item_id' => $order->items()->sole()->id, 'kilograms' => '2e1', 'new_lot_code' => 'FER240426X01',
    ]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.kilograms');
    Hardware::stock($product, '0.000');
    expect($order->receipts()->count())->toBe(0)->and($product->lots()->count())->toBe(0);
});
