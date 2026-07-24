<?php

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function e2ePresetUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'preset-e2e-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Sucursal E2E',
        'code' => 'E2E-'.uniqid(),
        'barcode' => 'BR-E2E-'.uniqid(),
        'point_of_sale_name' => 'Caja principal',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function e2ePresetProfile(string $name, string $type, array $configuration): void
{
    Cache::flush();

    BusinessProfile::query()->create([
        'name' => $name,
        'business_type' => $type,
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
}

function e2eCatalogs(): array
{
    return [
        SaleType::query()->firstOrCreate(['name' => 'Ocasionales'], ['is_active' => true]),
        Currency::query()->firstOrCreate(['code' => 'BOB'], [
            'name' => 'Bolivianos',
            'symbol' => 'Bs',
            'exchange_rate_to_bob' => 1,
            'is_base' => true,
            'is_active' => true,
        ]),
    ];
}

function e2eProduct(int $branchId, string $name = 'Producto E2E', float $stock = 100, float $price = 10): Product
{
    $product = Product::query()->create([
        'name' => $name,
        'sku' => 'SKU-'.uniqid(),
        'barcode' => 'BAR-'.uniqid(),
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'unidad',
        'allowed_units' => ['unidad'],
        'purchase_price' => 5,
        'sale_price' => $price,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    ProductBranchStock::query()->create([
        'branch_id' => $branchId,
        'product_id' => $product->id,
        'available_meters' => $stock,
        'reserved_meters' => 0,
        'is_enabled' => true,
    ]);

    return $product;
}

function e2eOpenCash(User $user): void
{
    CashRegisterSession::query()->create([
        'branch_id' => $user->branch_id,
        'opened_by' => $user->id,
        'opened_at' => now()->subMinute(),
        'opening_amount' => 100,
        'expected_cash_amount' => 100,
        'status' => CashRegisterSession::STATUS_OPEN,
    ]);
}

function e2eSalePayload(User $user, SaleType $saleType, Currency $currency, Product $product, array $overrides = []): array
{
    return array_replace_recursive([
        'document_type' => 'sale_note',
        'branch_id' => $user->branch_id,
        'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id,
        'customer_name' => 'Cliente E2E',
        'customer_document' => '123',
        'customer_contact' => '70000000',
        'advance_mode' => 'none',
        'terms' => '',
        'items' => [[
            'product_id' => $product->id,
            'product_coil_id' => null,
            'description' => $product->name,
            'unit_label' => 'unidad',
            'display_quantity' => 2,
            'display_unit_label' => 'unidad',
            'calculation_mode' => 'direct',
            'meters' => 2,
            'unit_price' => $product->sale_price,
            'discount_amount' => 0,
        ]],
    ], $overrides);
}

it('preset ferreteria cotizacion crea cotizacion y la convierte a nota real', function () {
    e2ePresetProfile('Ferreteria cotizacion E2E', 'hardware_store', [
        'sales' => ['workflow' => 'quotation_to_sale_note', 'quotation_mode' => 'required', 'inventory_discount_timing' => 'sale_note'],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Calamina E2E', 30, 15);
    e2eOpenCash($user);

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, [
            'document_type' => 'quotation',
            'receipt_number' => 'COT-E2E-001',
        ]))
        ->assertRedirect();

    $quotation = Sale::query()->where('receipt_number', 'COT-E2E-001')->firstOrFail();

    $this->actingAs($user)
        ->post(route('sales.convert', $quotation), ['receipt_number' => 'NV-E2E-001'])
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe('converted')
        ->and(Sale::query()->where('receipt_number', 'NV-E2E-001')->where('document_type', 'sale_note')->exists())->toBeTrue()
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(28.0);
});

it('preset ferreteria POS cobra por metodo permitido y genera pago real', function () {
    e2ePresetProfile('Ferreteria POS E2E', 'hardware_store', [
        'modules' => ['pos' => true, 'quotes' => false],
        'sales' => ['workflow' => 'pos', 'quotation_mode' => 'disabled', 'allowed_payment_methods' => ['cash', 'qr']],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Casco POS E2E', 10, 20);
    $cash = PaymentMethod::query()->create(['name' => 'Efectivo E2E', 'code' => 'cash', 'is_active' => true]);
    e2eOpenCash($user);

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, [
            'customer_name' => 'Cliente ocasional POS',
            'pos_payment_method_id' => $cash->id,
            'pos_payment_amount' => 40,
        ]))
        ->assertRedirect();

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect($sale->status)->toBe('paid')
        ->and((float) $sale->balance_due)->toBe(0.0)
        ->and(SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', $cash->id)->exists())->toBeTrue();
});

it('preset supermercado permite venta POS sin cliente y bloquea cotizaciones', function () {
    e2ePresetProfile('Supermercado E2E', 'supermarket', [
        'modules' => ['pos' => true, 'quotes' => false, 'deliveries' => false],
        'sales' => ['workflow' => 'pos', 'quotation_mode' => 'disabled', 'customer_mode' => 'hidden', 'allowed_payment_methods' => ['cash']],
        'cash' => ['required_to_sell' => false],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Galleta E2E', 20, 3);

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, [
            'customer_name' => '',
            'customer_document' => '',
            'customer_contact' => '',
        ]))
        ->assertRedirect();

    $this->actingAs($user)
        ->from(route('sales.index'))
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, ['document_type' => 'quotation']))
        ->assertSessionHasErrors('document_type');
});

it('preset servicios registra venta sin mover inventario automaticamente', function () {
    e2ePresetProfile('Servicios E2E', 'services', [
        'modules' => ['inventory' => false, 'deliveries' => false],
        'sales' => ['workflow' => 'service_sale', 'quotation_mode' => 'optional', 'inventory_discount_timing' => 'manual'],
        'cash' => ['required_to_sell' => false],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Servicio instalacion E2E', 1, 100);

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, [
            'items' => [[
                'product_id' => $product->id,
                'description' => 'Servicio instalacion E2E',
                'unit_label' => 'unidad',
                'display_quantity' => 1,
                'display_unit_label' => 'unidad',
                'calculation_mode' => 'direct',
                'meters' => 1,
                'unit_price' => 100,
                'discount_amount' => 0,
            ]],
        ]))
        ->assertRedirect();

    expect(InventoryMovement::query()->where('product_id', $product->id)->exists())->toBeFalse()
        ->and((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(1.0);
});

it('preset fabrica simple exige despacho cuando el descuento de inventario ocurre al despachar', function () {
    e2ePresetProfile('Fabrica simple E2E', 'factory', [
        'sales' => ['workflow' => 'quotation_to_sale_note', 'quotation_mode' => 'optional', 'inventory_discount_timing' => 'delivery'],
        'deliveries' => ['mode' => 'required'],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Produccion E2E', 50, 25);
    e2eOpenCash($user);

    $this->actingAs($user)
        ->from(route('sales.create', ['type' => 'sale_note']))
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, ['requires_delivery' => false]))
        ->assertSessionHasErrors('requires_delivery');

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, ['requires_delivery' => true]))
        ->assertRedirect();

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->value('available_meters'))->toBe(50.0);
});

it('compra recibida aumenta inventario por sucursal aunque el producto activo no tenga stock previo', function () {
    e2ePresetProfile('Inventario compras E2E', 'hardware_store', [
        'modules' => ['purchases' => true, 'inventory' => true, 'suppliers' => true],
        'purchases' => ['supplier_mode' => 'optional', 'allow_create_product' => true],
    ]);
    $user = e2ePresetUser(['purchases.view', 'purchases.manage', 'inventory.products.view']);
    $supplier = Supplier::query()->create(['name' => 'Proveedor inventario E2E', 'is_active' => true]);
    $product = Product::query()->create([
        'name' => 'Casco compra E2E',
        'sku' => 'CASCO-COMPRA-'.uniqid(),
        'barcode' => 'BAR-CASCO-COMPRA-'.uniqid(),
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'base_unit' => 'unidad',
        'allowed_units' => ['unidad'],
        'purchase_price' => 0,
        'sale_price' => 55,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('purchases.store'), [
            'branch_id' => $user->branch_id,
            'supplier_id' => $supplier->id,
            'document_number' => 'COMP-STOCK-E2E',
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'display_quantity' => 7,
                'display_unit_label' => 'unidad',
                'calculation_mode' => 'direct',
                'meters' => null,
                'kilograms' => null,
                'unit_cost' => 30,
                'lot_number' => null,
                'coil_barcode' => null,
                'description' => 'Ingreso de cascos',
            ]],
        ])
        ->assertRedirect();

    $stock = ProductBranchStock::query()
        ->where('branch_id', $user->branch_id)
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect((float) $stock->available_meters)->toBe(7.0)
        ->and($stock->is_enabled)->toBeTrue()
        ->and(InventoryMovement::query()
            ->where('branch_id', $user->branch_id)
            ->where('product_id', $product->id)
            ->where('type', 'purchase_entry_global')
            ->exists())->toBeTrue();
});

it('venta descuenta inventario y cobro QR se refleja en banco y cierre de caja de la sucursal', function () {
    e2ePresetProfile('Finanzas inventario E2E', 'hardware_store', [
        'modules' => ['sales_notes' => true, 'cash' => true, 'banks' => true, 'inventory' => true],
        'sales' => [
            'workflow' => 'pos',
            'quotation_mode' => 'disabled',
            'inventory_discount_timing' => 'sale_note',
            'allowed_payment_methods' => ['cash', 'qr'],
            'payment_methods_by_flow' => ['sales' => ['cash', 'qr'], 'pos' => ['cash', 'qr'], 'collections' => ['cash', 'qr']],
        ],
        'cash' => ['required_to_sell' => true, 'bank_reconciliation' => true],
        'banks' => ['reconciliation_mode' => 'automatic', 'require_branch_account' => true],
    ]);
    $user = e2ePresetUser(['sales.view', 'sales.manage', 'cash.view', 'cash.manage']);
    [$saleType, $currency] = e2eCatalogs();
    $product = e2eProduct($user->branch_id, 'Producto QR E2E', 12, 25);
    $qr = PaymentMethod::query()->firstOrCreate(['code' => 'qr'], ['name' => 'QR', 'is_active' => true, 'requires_reference' => true]);
    BankAccount::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Cuenta QR E2E',
        'bank_name' => 'Banco prueba',
        'account_number' => 'QR-E2E',
        'currency_code' => 'BOB',
        'current_balance' => 0,
        'is_active' => true,
    ]);
    e2eOpenCash($user);

    $this->actingAs($user)
        ->post(route('sales.store'), e2eSalePayload($user, $saleType, $currency, $product, [
            'pos_payment_method_id' => $qr->id,
            'pos_payment_amount' => 50,
            'pos_payment_reference' => 'QR-REF-E2E',
        ]))
        ->assertRedirect();

    $session = CashRegisterSession::query()->where('opened_by', $user->id)->where('status', CashRegisterSession::STATUS_OPEN)->firstOrFail();
    $this->actingAs($user)
        ->put(route('cash.close', $session), [
            'cash_count' => [
                'bill_200' => 0,
                'bill_100' => 1,
                'bill_50' => 0,
                'bill_20' => 0,
                'bill_10' => 0,
                'coin_5' => 0,
                'coin_2' => 0,
                'coin_1' => 0,
                'coin_050' => 0,
                'coin_020' => 0,
                'coin_010' => 0,
            ],
        ])
        ->assertRedirect(route('cash.index'));

    $sale = Sale::query()->latest('id')->firstOrFail();
    $session->refresh();

    expect((float) ProductBranchStock::query()->where('product_id', $product->id)->where('branch_id', $user->branch_id)->value('available_meters'))->toBe(10.0)
        ->and(SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', $qr->id)->exists())->toBeTrue()
        ->and(BankTransaction::query()->where('branch_id', $user->branch_id)->where('type', BankTransaction::TYPE_DEPOSIT)->where('amount', 50)->exists())->toBeTrue()
        ->and((float) $session->cash_income_amount)->toBe(0.0)
        ->and((float) $session->bank_income_amount)->toBe(50.0)
        ->and((float) $session->expected_cash_amount)->toBe(100.0);
});
