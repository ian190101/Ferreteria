<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInteraction;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Currency;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleType;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function customersUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'clientes-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Clientes central',
        'code' => 'CLI',
        'barcode' => 'BR-CLI',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea clientes con tipo y los lista paginados', function () {
    $user = customersUser(['customers.view', 'customers.manage']);
    $type = CustomerType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('customers.store'), [
            'customer_type_id' => $type->id,
            'name' => 'Camacho Ruben',
            'document_number' => '85911',
            'phone' => '70775320',
            'email' => null,
            'address' => 'Santa Cruz',
            'is_active' => true,
        ])
        ->assertRedirect(route('customers.index'));

    $this->actingAs($user)
        ->get(route('customers.index', ['search' => '85911']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index', false)
            ->has('customers.data', 1)
            ->where('customers.data.0.name', 'Camacho Ruben')
            ->where('customers.data.0.type.name', 'Ocasionales')
        );
});

it('bloquea clientes sin permiso', function () {
    $user = customersUser([]);

    $this->actingAs($user)
        ->get(route('customers.index'))
        ->assertForbidden();
});

it('gestiona tipos de cliente con edicion y desactivacion', function () {
    $user = customersUser(['customers.view', 'customers.manage']);
    $type = CustomerType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Index', false)
            ->has('typeCatalog.data', 1)
            ->where('typeCatalog.data.0.name', 'Ocasionales')
        );

    $this->actingAs($user)
        ->put(route('customers.types.update', $type->id), [
            'name' => 'Mayoristas',
            'is_active' => true,
        ])
        ->assertRedirect(route('customers.index'));

    $type->refresh();

    expect($type->name)->toBe('Mayoristas')
        ->and($type->is_active)->toBeTrue();

    $this->actingAs($user)
        ->delete(route('customers.types.destroy', $type->id))
        ->assertRedirect(route('customers.index'));

    $type->refresh();

    expect($type->is_active)->toBeFalse()
        ->and($type->trashed())->toBeTrue();
});

it('bloquea tipos de cliente duplicados', function () {
    $user = customersUser(['customers.view', 'customers.manage']);

    CustomerType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);

    $this->actingAs($user)
        ->from(route('customers.index'))
        ->post(route('customers.types.store'), [
            'name' => 'Ocasionales',
            'is_active' => true,
        ])
        ->assertRedirect(route('customers.index'))
        ->assertSessionHasErrors('name');
});

it('usa cliente registrado como snapshot en la nota de venta', function () {
    $user = customersUser(['sales.view', 'sales.manage', 'customers.view']);
    $customer = Customer::query()->create([
        'name' => 'Camacho Ruben',
        'document_number' => '85911',
        'phone' => '70775320',
        'is_active' => true,
    ]);
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
        'name' => 'Calamina cliente',
        'sku' => 'CLI-001',
        'barcode' => 'PR-CLI-001',
        'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);
    ProductBranchStock::query()->create([
        'branch_id' => $user->branch_id,
        'product_id' => $product->id,
        'available_meters' => 100,
        'reserved_meters' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('sales.store'), [
            'document_type' => 'sale_note',
            'branch_id' => $user->branch_id,
            'sale_type_id' => $saleType->id,
            'currency_id' => $currency->id,
            'customer_id' => $customer->id,
            'advance_option_id' => null,
            'receipt_number' => 'CLI-VENTA-001',
            'customer_name' => null,
            'customer_document' => null,
            'customer_contact' => null,
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'items' => [[
                'product_id' => $product->id,
                'product_coil_id' => null,
                'description' => 'CALAMINA 3.50M',
                'unit_label' => 'M',
                'meters' => 10,
                'unit_price' => 20,
                'discount_amount' => 0,
            ]],
        ])
        ->assertRedirect();

    $sale = Sale::query()->where('receipt_number', 'CLI-VENTA-001')->firstOrFail();

    expect($sale->customer_id)->toBe($customer->id)
        ->and($sale->customer_name)->toBe('Camacho Ruben')
        ->and($sale->customer_document)->toBe('85911')
        ->and($sale->customer_contact)->toBe('70775320');
});

it('muestra estado de cuenta de cliente con ventas pagos creditos y promesas', function () {
    $user = customersUser(['customers.view']);
    $customer = Customer::query()->create([
        'name' => 'Camacho Ruben',
        'document_number' => '85911',
        'phone' => '70775320',
        'is_active' => true,
    ]);
    $saleType = SaleType::query()->create(['name' => 'Ocasionales', 'is_active' => true]);
    $currency = Currency::query()->create([
        'name' => 'Bolivianos',
        'code' => 'BOB',
        'symbol' => 'Bs',
        'exchange_rate_to_bob' => 1,
        'is_base' => true,
        'is_active' => true,
    ]);
    $paymentMethod = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);
    $sale = Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'sale_type_id' => $saleType->id,
        'currency_id' => $currency->id,
        'customer_id' => $customer->id,
        'receipt_number' => 'NV-CLI-001',
        'document_type' => 'sale_note',
        'customer_name' => $customer->name,
        'customer_document' => $customer->document_number,
        'customer_contact' => $customer->phone,
        'sold_at' => now()->subDay(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 70,
        'total' => 100,
        'status' => 'partial',
    ]);

    SalePayment::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'payment_method_id' => $paymentMethod->id,
        'paid_at' => now(),
        'amount' => 20,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 20,
    ]);
    CreditNote::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'credit_number' => 'NC-CLI-001',
        'issued_at' => now(),
        'amount' => 10,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 10,
        'reason' => 'Ajuste comercial',
    ]);
    PaymentPromise::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'promise_number' => 'PP-CLI-001',
        'promised_date' => now()->addDays(3)->toDateString(),
        'promised_amount' => 30,
        'contact_name' => 'Ruben',
        'contact_phone' => '70775320',
        'channel' => 'phone',
        'status' => PaymentPromise::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->get(route('customers.statement', $customer->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Statement', false)
            ->where('customer.name', 'Camacho Ruben')
            ->where('metrics.sales_total', 100)
            ->where('metrics.balance_due', 70)
            ->where('metrics.payments_total', 20)
            ->where('metrics.credit_notes_total', 10)
            ->where('metrics.pending_promises_amount', 30)
            ->where('metrics.pending_promises_count', 1)
            ->has('sales.data', 1)
            ->has('payments.data', 1)
            ->has('creditNotes.data', 1)
            ->has('promises.data', 1)
            ->has('interactions.data', 0)
        );
});

it('registra seguimiento crm de cliente y permite completarlo', function () {
    $user = customersUser(['customers.view', 'customers.manage']);
    $customer = Customer::query()->create([
        'name' => 'Cliente seguimiento',
        'document_number' => '123',
        'phone' => '70000000',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('customers.interactions.store', $customer->id), [
            'type' => 'whatsapp',
            'contact_at' => now()->format('Y-m-d H:i:s'),
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'subject' => 'Enviar cotizacion actualizada',
            'notes' => 'Pidio confirmar disponibilidad.',
            'status' => CustomerInteraction::STATUS_PENDING,
        ])
        ->assertRedirect(route('customers.statement', $customer->id));

    $interaction = CustomerInteraction::query()->where('customer_id', $customer->id)->firstOrFail();

    $this->actingAs($user)
        ->get(route('customers.statement', $customer->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customers/Statement', false)
            ->has('interactions.data', 1)
            ->where('interactions.data.0.subject', 'Enviar cotizacion actualizada')
        );

    $this->actingAs($user)
        ->patch(route('customers.interactions.complete', [$customer->id, $interaction->id]))
        ->assertRedirect(route('customers.statement', $customer->id));

    $interaction->refresh();

    expect($interaction->status)->toBe(CustomerInteraction::STATUS_COMPLETED)
        ->and($interaction->completed_at)->not->toBeNull();
});
