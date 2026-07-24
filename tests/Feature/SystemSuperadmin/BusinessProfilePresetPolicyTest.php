<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchases\Services\PurchaseWorkflowPolicy;
use App\Modules\Sales\Models\ProductPriceRule;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\CommercialPolicy;
use App\Modules\Sales\Services\DeliveryWorkflowPolicy;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Illuminate\Support\Facades\Cache;

function presetBusinessProfile(string $name, string $type, array $configuration): BusinessProfile
{
    Cache::flush();

    return BusinessProfile::query()->create([
        'name' => $name,
        'business_type' => $type,
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized($configuration),
        'applied_at' => now(),
    ]);
}

it('aplica el preset ferreteria con cotizacion y nota', function () {
    presetBusinessProfile('Ferreteria cotizacion', 'hardware_store', [
        'sales' => [
            'workflow' => 'quotation_to_sale_note',
            'quotation_mode' => 'required',
            'quotation_label' => 'Cotizacion',
            'sale_note_label' => 'Nota de venta',
            'visible_columns' => ['description', 'model', 'quantity', 'unit', 'base', 'price', 'subtotal'],
            'allowed_payment_methods' => ['cash', 'qr'],
            'payment_methods_by_flow' => [
                'sales' => ['cash', 'qr'],
                'pos' => ['cash'],
                'collections' => ['cash', 'qr'],
            ],
        ],
        'deliveries' => ['mode' => 'optional'],
    ]);

    expect(app(SalesWorkflowPolicy::class)->summary())
        ->requiresSourceQuotationForSaleNote->toBeTrue()
        ->canCreateQuotation->toBeTrue()
        ->canCreateSaleNote->toBeTrue();

    expect(app(SalesDocumentPolicy::class)->summary())
        ->quotationLabel->toBe('Cotizacion')
        ->saleNoteLabel->toBe('Nota de venta')
        ->allowedPaymentMethodCodes->toBe(['cash', 'qr'])
        ->paymentMethodsByFlow->toBe([
            'sales' => ['cash', 'qr'],
            'pos' => ['cash'],
            'collections' => ['cash', 'qr'],
        ]);
});

it('aplica el preset ferreteria POS', function () {
    presetBusinessProfile('Ferreteria POS', 'hardware_store', [
        'sales' => [
            'workflow' => 'pos',
            'quotation_mode' => 'disabled',
            'document_main' => 'ticket',
            'ticket_label' => 'Ticket POS',
            'allowed_payment_methods' => ['cash', 'qr', 'transfer'],
        ],
        'purchases' => [
            'workflow' => 'barcode_purchase',
            'barcode_entry' => true,
        ],
    ]);

    expect(app(SalesWorkflowPolicy::class)->summary())
        ->mode->toBe('pos')
        ->canCreateQuotation->toBeFalse()
        ->canCreateSaleNote->toBeTrue();

    expect(app(SalesDocumentPolicy::class)->summary())
        ->documentMain->toBe('ticket')
        ->ticketLabel->toBe('Ticket POS');

    expect(app(PurchaseWorkflowPolicy::class)->summary())
        ->barcodeEntryEnabled->toBeTrue();
});

it('aplica el preset supermercado', function () {
    presetBusinessProfile('Supermercado', 'supermarket', [
        'sales' => [
            'workflow' => 'pos',
            'quotation_mode' => 'disabled',
            'customer_mode' => 'hidden',
            'allow_negative_stock' => false,
            'allowed_payment_methods' => ['cash', 'qr', 'card'],
        ],
        'deliveries' => ['mode' => 'disabled'],
    ]);

    expect(app(SalesWorkflowPolicy::class)->summary())
        ->mode->toBe('pos')
        ->customerHidden->toBeTrue()
        ->allowsNegativeStock->toBeFalse();

    expect(app(DeliveryWorkflowPolicy::class)->summary())
        ->enabled->toBeFalse();
});

it('aplica el preset servicios', function () {
    presetBusinessProfile('Servicios', 'services', [
        'modules' => [
            'inventory' => false,
            'purchases' => false,
            'deliveries' => false,
        ],
        'sales' => [
            'workflow' => 'service_sale',
            'quotation_mode' => 'optional',
            'customer_mode' => 'required',
            'inventory_discount_timing' => 'manual',
        ],
    ]);

    expect(app(SalesWorkflowPolicy::class)->summary())
        ->mode->toBe('service_sale')
        ->customerRequired->toBeTrue()
        ->inventoryDiscountTiming->toBe('manual');

    expect(app(DeliveryWorkflowPolicy::class)->summary())
        ->enabled->toBeFalse();
});

it('aplica el preset fabrica simple', function () {
    presetBusinessProfile('Fabrica simple', 'factory', [
        'modules' => [
            'production' => true,
            'deliveries' => true,
        ],
        'sales' => [
            'workflow' => 'quotation_to_sale_note',
            'quotation_mode' => 'optional',
            'inventory_discount_timing' => 'delivery',
        ],
        'deliveries' => [
            'mode' => 'required',
            'driver_required' => true,
            'truck_required' => true,
        ],
    ]);

    expect(app(SalesWorkflowPolicy::class)->summary())
        ->requiresSourceQuotationForSaleNote->toBeFalse()
        ->inventoryDiscountTiming->toBe('delivery');

    expect(app(DeliveryWorkflowPolicy::class)->summary())
        ->required->toBeTrue()
        ->driverRequired->toBeTrue()
        ->truckRequired->toBeTrue();
});

it('aplica politicas comerciales avanzadas por perfil', function () {
    $user = User::factory()->create();
    presetBusinessProfile('Politicas avanzadas', 'mixed', [
        'sales' => [
            'negative_stock_policy' => 'role',
            'negative_stock_roles' => ['supervisor'],
            'discount_policy' => 'always_with_limit',
            'max_discount_percent' => 10,
            'price_policy' => 'mixed',
            'credit_limit_policy' => 'block',
            'default_credit_limit' => 500,
        ],
    ]);

    $policy = app(CommercialPolicy::class);

    expect($policy->summary())
        ->pricePolicy->toBe('mixed')
        ->discountPolicy->toBe('always_with_limit')
        ->creditLimitPolicy->toBe('block')
        ->defaultCreditLimit->toBe(500.0)
        ->and($policy->canApplyDiscount($user))->toBeTrue()
        ->and($policy->discountExceedsLimit(100, 11))->toBeTrue()
        ->and($policy->discountExceedsLimit(100, 10))->toBeFalse()
        ->and($policy->canSellNegativeStock($user))->toBeFalse();
});

it('resuelve listas de precios por cliente y sucursal segun el perfil', function () {
    $branch = Branch::query()->create([
        'name' => 'Sucursal precio',
        'code' => 'PRC-'.uniqid(),
        'barcode' => 'BR-PRC-'.uniqid(),
        'is_active' => true,
    ]);
    $customer = Customer::query()->create([
        'name' => 'Cliente precio',
        'document_number' => 'C-'.uniqid(),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Producto precio',
        'sku' => 'SKU-'.uniqid(),
        'barcode' => 'BAR-'.uniqid(),
        'base_unit' => 'unidad',
        'sale_price' => 100,
        'purchase_price' => 70,
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'is_active' => true,
    ]);

    ProductPriceRule::query()->create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'price' => 90,
    ]);
    ProductPriceRule::query()->create([
        'product_id' => $product->id,
        'customer_id' => $customer->id,
        'price' => 80,
    ]);

    presetBusinessProfile('Lista mixta', 'mixed', [
        'sales' => ['price_policy' => 'mixed'],
    ]);

    $policy = app(CommercialPolicy::class);

    expect($policy->priceFor($product, $branch->id, $customer->id))->toBe(80.0)
        ->and($policy->priceFor($product, $branch->id))->toBe(90.0)
        ->and($policy->priceFor($product))->toBe(100.0);
});

it('bloquea credito cuando el cliente supera su limite configurado', function () {
    $customer = Customer::query()->create([
        'name' => 'Cliente credito',
        'document_number' => 'CR-'.uniqid(),
        'credit_limit' => 100,
        'is_active' => true,
    ]);

    Sale::query()->create([
        'branch_id' => Branch::query()->create([
            'name' => 'Sucursal credito',
            'code' => 'CRD-'.uniqid(),
            'barcode' => 'BR-CRD-'.uniqid(),
            'is_active' => true,
        ])->id,
        'user_id' => User::factory()->create()->id,
        'customer_id' => $customer->id,
        'receipt_number' => 'CRD-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => $customer->name,
        'sold_at' => now(),
        'subtotal' => 80,
        'total' => 80,
        'balance_due' => 80,
        'status' => 'issued',
    ]);

    presetBusinessProfile('Credito bloqueado', 'mixed', [
        'sales' => ['credit_limit_policy' => 'block'],
    ]);

    app(CommercialPolicy::class)->assertCreditAllowed($customer, 30);
})->throws(\Illuminate\Validation\ValidationException::class);
