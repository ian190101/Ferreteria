<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\AdvanceOption;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\DocumentSequence;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function salesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'ventas-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Doble via',
        'code' => 'DOBLE',
        'barcode' => 'BR-DOBLE',
        'phone' => '77300567',
        'secondary_phone' => '69010531',
        'point_of_sale_name' => 'Doble via',
        'address' => 'Av. Doble via la guardia km8',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function salesStock(Branch|int $branch, Product|int $product, float $meters): ProductBranchStock
{
    return ProductBranchStock::query()->create([
        'branch_id' => $branch instanceof Branch ? $branch->id : $branch,
        'product_id' => $product instanceof Product ? $product->id : $product,
        'available_meters' => $meters,
        'reserved_meters' => 0,
    ]);
}

it('genera nota de venta con moneda, anticipo y saldo calculados', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Dolares',
        'code' => 'USD',
        'symbol' => '$',
        'exchange_rate_to_bob' => 10,
        'is_base' => false,
        'is_active' => true,
    ]);
    $advance = AdvanceOption::query()->create(['name' => '30%', 'percentage' => 30, 'is_active' => true]);
    $product = Product::query()->create([
        'name' => 'Calamina',
        'sku' => 'CAL-001',
        'barcode' => 'PR-CAL-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 100);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => $advance->id,
            'receipt_number' => '000001',
            'customer_name' => 'Camacho Ruben',
            'customer_document' => '85911',
            'customer_contact' => '70775320',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'terms' => 'NOTA: NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES.',
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 3.50M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 5,
            ]],
        ])
        ->assertRedirect();

    $sale = Sale::query()->where('receipt_number', '000001')->firstOrFail();

    expect((float) $sale->total)->toBe(195.0)
        ->and((float) $sale->advance_amount)->toBe(58.5)
        ->and((float) $sale->balance_due)->toBe(136.5)
        ->and((float) $sale->exchange_rate_to_bob)->toBe(10.0)
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(90.0)
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->where('product_id', $product->id)->exists())->toBeTrue();
});

it('bloquea nota de venta con stock global insuficiente', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina sin stock',
        'sku' => 'CAL-SIN',
        'barcode' => 'PR-CAL-SIN',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 5);

    $this->actingAs($user)
        ->from(route('sales.create', ['type' => 'sale_note']))
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'SIN-STOCK-001',
            'customer_name' => 'Cliente sin stock',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect(route('sales.create', ['type' => 'sale_note']))
        ->assertSessionHasErrors('items');
});

it('descuenta nota de venta desde bobina individual', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Bobina azul',
        'sku' => 'BOB-AZUL',
        'barcode' => 'PR-BOB-AZUL',
        'inventory_tracking_mode' => Product::TRACKING_COIL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    $coil = ProductCoil::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'barcode' => 'COIL-VENTA-001',
        'lot_number' => 'LOTE-VENTA-001',
        'initial_meters' => 25,
        'available_meters' => 25,
        'initial_kg' => null,
        'status' => 'available',
    ]);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'BOBINA-001',
            'customer_name' => 'Cliente bobina',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => $coil->id,
                'description' => 'BOBINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    $coil->refresh();

    expect((float) $coil->available_meters)->toBe(15.0)
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->where('product_coil_id', $coil->id)->exists())->toBeTrue();
});

it('no descuenta inventario al generar cotizacion', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina cotizada',
        'sku' => 'CAL-COT',
        'barcode' => 'PR-CAL-COT',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 5);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'quotation',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'COT-STOCK-001',
            'customer_name' => 'Cliente cotizacion',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 100M',
                'unit_label' => 'M',
                'meters' => 100,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(5.0)
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->where('product_id', $product->id)->exists())->toBeFalse();
});

it('convierte cotizacion vigente en nota de venta descontando inventario', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina conversion',
        'sku' => 'CAL-CONV',
        'barcode' => 'PR-CAL-CONV',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 100);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'quotation',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'COT-CONV-001',
            'customer_name' => 'Cliente conversion',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    $quotation = Sale::query()->where('receipt_number', 'COT-CONV-001')->firstOrFail();

    $this->actingAs($user)
        ->post(route('sales.convert', $quotation->id), [
            'receipt_number' => 'NV-CONV-001',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect();

    $quotation->refresh();
    $newSale = Sale::query()->where('receipt_number', 'NV-CONV-001')->with('items')->firstOrFail();

    expect($quotation->status)->toBe('converted')
        ->and($newSale->document_type)->toBe('sale_note')
        ->and($newSale->status)->toBe('issued')
        ->and($newSale->items)->toHaveCount(1)
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(90.0)
        ->and(InventoryMovement::query()->where('type', 'sale_stock_out')->where('source_id', $newSale->items->first()->id)->exists())->toBeTrue();
});

it('bloquea convertir cotizacion si el stock ya no alcanza', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina sin stock conversion',
        'sku' => 'CAL-CONV-SIN',
        'barcode' => 'PR-CAL-CONV-SIN',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 5);

    $quotation = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id,
        'receipt_number' => 'COT-SIN-CONV',
        'document_type' => 'quotation',
        'customer_name' => 'Cliente sin stock conversion',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 200,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 200,
        'total' => 200,
        'status' => 'quoted',
    ]);
    $quotation->items()->create([
        'product_id' => $product->id,
        'product_coil_id' => null,
        'description' => 'CALAMINA 10M',
        'unit_label' => 'M',
        'meters' => 10,
        'unit_price' => 20,
        'discount_amount' => 0,
        'total' => 200,
    ]);

    $this->actingAs($user)
        ->from(route('sales.show', $quotation->id))
        ->post(route('sales.convert', $quotation->id), [
            'receipt_number' => 'NV-SIN-CONV',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('sales.show', $quotation->id))
        ->assertSessionHasErrors('items');

    $quotation->refresh();

    expect($quotation->status)->toBe('quoted')
        ->and(Sale::query()->where('receipt_number', 'NV-SIN-CONV')->exists())->toBeFalse()
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(5.0);
});

it('bloquea convertir documentos que no son cotizaciones vigentes', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-NO-CONV',
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente no conversion',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    $this->actingAs($user)
        ->from(route('sales.show', $sale->id))
        ->post(route('sales.convert', $sale->id), [
            'receipt_number' => 'NV-NO-CONV-2',
            'sold_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('sales.show', $sale->id))
        ->assertSessionHasErrors('quotation');
});

it('gestiona catalogos comerciales con paginacion y permiso superior', function () {
    $user = salesUser(['settings.manage']);

    SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    AdvanceOption::query()->create(['name' => '10%', 'percentage' => 10, 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('sales.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Settings', false)
            ->has('saleTypes.data', 1)
            ->has('currencies.data', 1)
            ->has('advanceOptions.data', 1)
            ->has('documentSequences.data', 0)
        );
});

it('gestiona secuencias de numeracion por sucursal y documento', function () {
    $user = salesUser(['settings.manage']);

    $this->actingAs($user)
        ->post(route('sales.settings.store'), [
            'kind' => 'document_sequence',
            'branch_id' => $user->branch_id,
            'document_type' => 'sale_note',
            'name' => 'Notas doble via',
            'prefix' => 'NV-',
            'next_number' => 7,
            'padding' => 5,
            'is_active' => true,
        ])
        ->assertRedirect(route('sales.settings.index'));

    $sequence = DocumentSequence::query()->where('branch_id', $user->branch_id)->firstOrFail();

    expect($sequence->preview())->toBe('NV-00007');

    $this->actingAs($user)
        ->put(route('sales.settings.update', ['kind' => 'document_sequence', 'setting' => $sequence->id]), [
            'branch_id' => $user->branch_id,
            'document_type' => 'sale_note',
            'name' => 'Notas principal',
            'prefix' => 'N-',
            'next_number' => 8,
            'padding' => 4,
            'is_active' => true,
        ])
        ->assertRedirect(route('sales.settings.index'));

    $sequence->refresh();

    expect($sequence->name)->toBe('Notas principal')
        ->and($sequence->preview())->toBe('N-0008');
});

it('genera numero automatico al dejar vacio el recibo de la nota', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina automatica',
        'sku' => 'CAL-AUTO',
        'barcode' => 'PR-CAL-AUTO',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 100);
    DocumentSequence::query()->create([
        'branch_id' => $user->branch_id,
        'document_type' => 'sale_note',
        'name' => 'Notas automaticas',
        'prefix' => 'NV-',
        'next_number' => 7,
        'padding' => 4,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => '',
            'customer_name' => 'Cliente automatico',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    $sale = Sale::query()->where('receipt_number', 'NV-0007')->firstOrFail();
    $sequence = DocumentSequence::query()->where('branch_id', $user->branch_id)->firstOrFail();

    expect($sale->document_type)->toBe('sale_note')
        ->and($sequence->next_number)->toBe(8);
});

it('anula nota de venta sin pagos activos y guarda motivo', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'VOID-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente anulacion',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 100,
        'total' => 100,
        'status' => 'issued',
    ]);

    $this->actingAs($user)
        ->patch(route('sales.void', $sale->id), [
            'reason' => 'Documento duplicado',
        ])
        ->assertRedirect(route('sales.index'));

    $sale->refresh();

    expect($sale->status)->toBe('void')
        ->and((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->internal_notes)->toContain('Documento duplicado');
});

it('bloquea anular nota de venta con pagos activos', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'VOID-BLOCK-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente anulacion',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 60,
        'total' => 100,
        'status' => 'partial_paid',
    ]);

    SalePayment::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $sale->branch_id,
        'user_id' => $user->id,
        'payment_method_id' => $method->id,
        'paid_at' => now(),
        'amount' => 40,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 40,
    ]);

    $this->actingAs($user)
        ->from(route('sales.index'))
        ->patch(route('sales.void', $sale->id), [
            'reason' => 'Cliente cancelo',
        ])
        ->assertRedirect(route('sales.index'))
        ->assertSessionHasErrors('sale');

    $sale->refresh();

    expect($sale->status)->toBe('partial_paid');
});

it('devuelve stock global al anular nota de venta emitida', function () {
    $user = salesUser(['sales.view', 'sales.manage']);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Calamina retorno',
        'sku' => 'CAL-RET',
        'barcode' => 'PR-CAL-RET',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    salesStock($user->branch_id, $product, 100);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'advance_option_id' => null,
            'receipt_number' => 'RETORNO-001',
            'customer_name' => 'Cliente retorno',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 10M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    $sale = Sale::query()->where('receipt_number', 'RETORNO-001')->firstOrFail();

    $this->actingAs($user)
        ->patch(route('sales.void', $sale->id), [
            'reason' => 'Documento duplicado',
        ])
        ->assertRedirect(route('sales.index'));

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(100.0)
        ->and(InventoryMovement::query()->where('type', 'sale_void_return')->where('product_id', $product->id)->exists())->toBeTrue();
});

it('crea moneda base y actualiza cambio de moneda comercial', function () {
    $user = salesUser(['settings.manage']);
    $bob = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('sales.settings.store'), [
            'kind' => 'currency',
            'name' => 'Dolares',
            'code' => 'usd',
            'symbol' => '$',
            'exchange_rate_to_bob' => 10,
            'is_base' => false,
            'is_active' => true,
        ])
        ->assertRedirect(route('sales.settings.index'));

    $usd = Currency::query()->where('code', 'USD')->firstOrFail();

    $this->actingAs($user)
        ->put(route('sales.settings.update', ['kind' => 'currency', 'setting' => $usd->id]), [
            'name' => 'Dolares americanos',
            'code' => 'USD',
            'symbol' => '$',
            'exchange_rate_to_bob' => 6.96,
            'is_base' => true,
            'is_active' => true,
        ])
        ->assertRedirect(route('sales.settings.index'));

    $bob->refresh();
    $usd->refresh();

    expect($bob->is_base)->toBeFalse()
        ->and($usd->is_base)->toBeTrue()
        ->and((float) $usd->exchange_rate_to_bob)->toBe(1.0);
});

it('bloquea eliminar moneda base y permite actualizar anticipos', function () {
    $user = salesUser(['settings.manage']);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $advance = AdvanceOption::query()->create(['name' => '30%', 'percentage' => 30, 'is_active' => true]);

    $this->actingAs($user)
        ->from(route('sales.settings.index'))
        ->delete(route('sales.settings.destroy', ['kind' => 'currency', 'setting' => $currency->id]))
        ->assertRedirect(route('sales.settings.index'))
        ->assertSessionHasErrors('currency');

    $this->actingAs($user)
        ->put(route('sales.settings.update', ['kind' => 'advance_option', 'setting' => $advance->id]), [
            'name' => '50%',
            'percentage' => 50,
            'is_active' => true,
        ])
        ->assertRedirect(route('sales.settings.index'));

    $advance->refresh();

    expect($advance->name)->toBe('50%')
        ->and((float) $advance->percentage)->toBe(50.0);
});
