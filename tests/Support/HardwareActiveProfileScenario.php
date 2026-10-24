<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use Tests\TestCase;

final class HardwareActiveProfileScenario
{
    public static function prepare(TestCase $test): array
    {
        $snapshot = json_decode(file_get_contents(base_path('tests/Fixtures/hardware-active-profile.json')), true, flags: JSON_THROW_ON_ERROR);
        $configuration = BusinessProfileConfiguration::normalized($snapshot['configuration']);
        $profile = BusinessProfile::query()->create([
            'name' => 'Ferretería activa: copia aislada', 'business_type' => $snapshot['business_type'],
            'status' => 'active', 'configuration' => $configuration, 'applied_at' => now(),
        ]);
        SystemCacheInvalidator::bumpOperational();
        $branch = ManagedLotScenario::branch();
        $user = ManagedLotScenario::user($branch, 'operador-ferreteria-activa', [
            'inventory.products.view', 'inventory.products.manage', 'inventory.coils.manage',
            'inventory.movements.view', 'inventory.adjustments.view', 'inventory.adjustments.manage',
            'inventory.transfers.view', 'inventory.transfers.manage', 'purchases.view', 'purchases.manage',
            'sales.view', 'sales.manage', 'sales.prices.override', 'sales.returns.view', 'sales.returns.manage',
            'sales.deliveries.view', 'sales.deliveries.manage', 'payments.view', 'payments.manage',
            'cash.view', 'cash.manage', 'banks.view', 'banks.manage', 'cash-flow.view',
            'reports.view', 'exports.view', 'settings.manage', 'expenses.view', 'expenses.manage',
            'customers.view', 'customers.manage', 'dashboard.view',
        ]);
        $user->accessibleBranches()->sync([$branch->id]);
        $currency = Currency::query()->create([
            'name' => 'Bolivianos', 'code' => 'BOB', 'symbol' => 'Bs', 'exchange_rate_to_bob' => '1.000000',
            'is_base' => true, 'is_active' => true,
        ]);
        $type = SaleType::query()->create(['name' => 'Venta ferretería', 'is_active' => true]);
        $methods = collect(['cash', 'qr', 'transfer'])->mapWithKeys(fn (string $code) => [$code => PaymentMethod::query()->create([
            'name' => $code, 'code' => $code, 'is_active' => true, 'requires_reference' => $code !== 'cash',
        ])]);
        $bank = BankAccount::query()->create([
            'branch_id' => $branch->id, 'name' => 'Banco sucursal', 'bank_name' => 'Banco de prueba',
            'account_number' => 'PRUEBA-001', 'currency_code' => 'BOB',
            'opening_balance' => '1000.00', 'current_balance' => '1000.00', 'is_active' => true,
        ]);
        $test->actingAs($user);

        return compact('profile', 'configuration', 'branch', 'user', 'currency', 'type', 'methods', 'bank');
    }

    public static function product(TestCase $test, array $context, string $name, bool $managed = true, bool $service = false): Product
    {
        $draft = $test->hardwareCatalogTemplate ??= ManagedLotScenario::catalogPayload($context['branch']);
        $draft['name'] = $name;
        $draft['lot_registrations'] = [];
        $draft['requires_lot'] = $managed;
        $draft['item_type'] = $service ? 'service' : 'physical';
        $draft['is_inventory_item'] = ! $service;
        $test->post(route('inventory.products.store'), $draft)->assertRedirect()->assertSessionHasNoErrors();

        return Product::query()->where('name', $name)->sole();
    }

    public static function receive(TestCase $test, array $context, Product $product, string $code, string $meters): Purchase
    {
        $test->post(route('purchases.store'), [
            'branch_id' => $context['branch']->id, 'status' => 'received', 'items' => [[
                'product_id' => $product->id, 'meters' => $meters, 'unit_cost' => '2.0000',
                ...($product->requires_lot ? ['new_lot_code' => $code] : []),
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Purchase::query()->latest('id')->firstOrFail();
    }

    public static function quotationPayload(array $context, Product $product, ?ProductCoil $lot, string $quantity = '10.000'): array
    {
        return [
            'document_type' => 'quotation', 'branch_id' => $context['branch']->id,
            'sale_type_id' => $context['type']->id, 'currency_id' => $context['currency']->id,
            'receipt_number' => 'FER-COT-'.uniqid(), 'customer_name' => 'Cliente ferretería',
            'sold_at' => now()->format('Y-m-d H:i:s'), 'requires_delivery' => true,
            'items' => [[
                'product_id' => $product->id, 'lot_id' => $lot?->id, 'description' => $product->name,
                'unit_label' => 'm', 'display_unit_label' => 'm', 'display_quantity' => $quantity,
                'calculation_mode' => 'direct', 'unit_price' => '3.0000', 'discount_amount' => '0.00',
            ]],
        ];
    }

    public static function quote(TestCase $test, array $context, Product $product, ?ProductCoil $lot, string $quantity = '10.000'): Sale
    {
        $payload = self::quotationPayload($context, $product, $lot, $quantity);
        $test->post(route('sales.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        return Sale::query()->where('receipt_number', $payload['receipt_number'])->sole();
    }

    public static function openCash(TestCase $test, array $context): CashRegisterSession
    {
        $test->post(route('cash.open'), [
            'branch_id' => $context['branch']->id, 'opening_amount' => '100.00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return CashRegisterSession::query()->where('opened_by', $context['user']->id)->where('status', 'open')->sole();
    }

    public static function convert(TestCase $test, Sale $quotation): Sale
    {
        $test->post(route('sales.convert', $quotation), ['requires_delivery' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        return Sale::query()->where('document_type', 'sale_note')->latest('id')->firstOrFail();
    }

    public static function stock(Product $product, string $expected): void
    {
        expect((string) $product->branchStocks()->sole()->available_meters)->toBe($expected)
            ->and((string) $product->branchStocks()->sole()->reserved_meters)->toBe('0.000');
        if ($product->requires_lot) {
            expect(bcadd((string) $product->lots()->sum('available_meters'), '0', 3))->toBe($expected);
        }
    }
}
