<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function paymentsUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'pagos-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Caja central',
        'code' => 'CAJA',
        'barcode' => 'BR-CAJA',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function paymentSale(User $user, float $balance = 100): Sale
{
    return Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'PAGO-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente pago',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 100,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => $balance,
        'total' => 100,
        'status' => 'issued',
    ]);
}

function paymentPurchase(User $user, float $balance = 100): Purchase
{
    $supplier = Supplier::query()->create([
        'name' => 'Proveedor pago',
        'is_active' => true,
    ]);

    return Purchase::query()->create([
        'branch_id' => $user->branch_id,
        'supplier_id' => $supplier->id,
        'user_id' => $user->id,
        'document_number' => 'COMP-PAGO-'.uniqid(),
        'purchase_date' => now()->toDateString(),
        'total_amount' => 100,
        'paid_amount' => 100 - $balance,
        'balance_due' => $balance,
        'payment_status' => $balance >= 100 ? 'unpaid' : 'partial_paid',
        'status' => 'received',
    ]);
}

it('registra pago parcial y actualiza saldo de la nota', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $sale = paymentSale($user, 100);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('payments.store'), [
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 40,
            'reference' => null,
            'notes' => 'Abono inicial',
        ])
        ->assertRedirect();

    $sale->refresh();

    expect((float) $sale->balance_due)->toBe(60.0)
        ->and($sale->status)->toBe('partial_paid')
        ->and(SalePayment::query()->where('sale_id', $sale->id)->count())->toBe(1);
});

it('marca como pagada cuando el pago cubre el saldo', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $sale = paymentSale($user, 60);
    $method = PaymentMethod::query()->create([
        'name' => 'Transferencia',
        'code' => 'transfer',
        'requires_reference' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('payments.store'), [
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 60,
            'reference' => 'TRX-001',
            'notes' => null,
        ])
        ->assertRedirect();

    $sale->refresh();

    expect((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->status)->toBe('paid');
});

it('bloquea pagos mayores al saldo pendiente', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $sale = paymentSale($user, 50);
    $method = PaymentMethod::query()->create([
        'name' => 'QR',
        'code' => 'qr',
        'requires_reference' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('payments.index'))
        ->post(route('payments.store'), [
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 70,
            'reference' => 'QR-001',
            'notes' => null,
        ])
        ->assertRedirect(route('payments.index'))
        ->assertSessionHasErrors('amount');
});

it('anula pago registrado y restaura saldo de la nota', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $sale = paymentSale($user, 100);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('payments.store'), [
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 40,
            'reference' => null,
            'notes' => 'Pago a anular',
        ])
        ->assertRedirect();

    $payment = SalePayment::query()->where('sale_id', $sale->id)->firstOrFail();

    $this->actingAs($user)
        ->patch(route('payments.void', $payment->id), [
            'reason' => 'Pago duplicado',
        ])
        ->assertRedirect();

    $sale->refresh();
    $voidedPayment = SalePayment::withTrashed()->findOrFail($payment->id);

    expect((float) $sale->balance_due)->toBe(100.0)
        ->and($sale->status)->toBe('issued')
        ->and($voidedPayment->trashed())->toBeTrue()
        ->and($voidedPayment->notes)->toContain('Pago duplicado');
});

it('lista pagos y cuentas por cobrar con permiso', function () {
    $user = paymentsUser(['payments.view']);
    paymentSale($user, 80);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Index', false)
            ->has('receivables', 1)
            ->has('payments.data', 0)
        );
});

it('registra pago parcial de compra y actualiza cuenta por pagar', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $purchase = paymentPurchase($user, 100);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash-provider',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('payments.purchase-payments.store'), [
            'purchase_id' => $purchase->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 45,
            'reference' => null,
            'notes' => 'Abono proveedor',
        ])
        ->assertRedirect();

    $purchase->refresh();

    expect((float) $purchase->paid_amount)->toBe(45.0)
        ->and((float) $purchase->balance_due)->toBe(55.0)
        ->and($purchase->payment_status)->toBe('partial_paid')
        ->and(PurchasePayment::query()->where('purchase_id', $purchase->id)->count())->toBe(1);
});

it('bloquea pago de compra mayor al saldo pendiente', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $purchase = paymentPurchase($user, 50);
    $method = PaymentMethod::query()->create([
        'name' => 'Transferencia proveedor',
        'code' => 'transfer-provider',
        'requires_reference' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('payments.purchase-payments.index'))
        ->post(route('payments.purchase-payments.store'), [
            'purchase_id' => $purchase->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 70,
            'reference' => 'TRX-PROV-001',
            'notes' => null,
        ])
        ->assertRedirect(route('payments.purchase-payments.index'))
        ->assertSessionHasErrors('amount');
});

it('anula pago de compra y restaura saldo por pagar', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $purchase = paymentPurchase($user, 100);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo proveedor',
        'code' => 'cash-provider-void',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('payments.purchase-payments.store'), [
            'purchase_id' => $purchase->id,
            'payment_method_id' => $method->id,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 40,
            'reference' => null,
            'notes' => 'Pago proveedor a anular',
        ])
        ->assertRedirect();

    $payment = PurchasePayment::query()->where('purchase_id', $purchase->id)->firstOrFail();

    $this->actingAs($user)
        ->patch(route('payments.purchase-payments.void', $payment->id), [
            'reason' => 'Registro duplicado',
        ])
        ->assertRedirect();

    $purchase->refresh();
    $voidedPayment = PurchasePayment::withTrashed()->findOrFail($payment->id);

    expect((float) $purchase->paid_amount)->toBe(0.0)
        ->and((float) $purchase->balance_due)->toBe(100.0)
        ->and($purchase->payment_status)->toBe('unpaid')
        ->and($voidedPayment->trashed())->toBeTrue()
        ->and($voidedPayment->notes)->toContain('Registro duplicado');
});

it('lista pagos de proveedor y cuentas por pagar con permiso', function () {
    $user = paymentsUser(['payments.view']);
    paymentPurchase($user, 80);

    $this->actingAs($user)
        ->get(route('payments.purchase-payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Payments/PurchasePayments/Index', false)
            ->has('payables', 1)
            ->has('payments.data', 0)
        );
});

it('gestiona metodos de pago con edicion y desactivacion', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);
    $method = PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Index', false)
            ->has('methodCatalog.data', 1)
            ->where('methodCatalog.data.0.code', 'cash')
        );

    $this->actingAs($user)
        ->put(route('payments.methods.update', $method->id), [
            'name' => 'Transferencia bancaria',
            'code' => 'transfer',
            'requires_reference' => true,
            'is_active' => true,
        ])
        ->assertRedirect(route('payments.index'));

    $method->refresh();

    expect($method->name)->toBe('Transferencia bancaria')
        ->and($method->code)->toBe('transfer')
        ->and($method->requires_reference)->toBeTrue();

    $this->actingAs($user)
        ->delete(route('payments.methods.destroy', $method->id))
        ->assertRedirect(route('payments.index'));

    $method->refresh();

    expect($method->is_active)->toBeFalse()
        ->and($method->trashed())->toBeTrue();
});

it('bloquea codigos duplicados en metodos de pago', function () {
    $user = paymentsUser(['payments.view', 'payments.manage']);

    PaymentMethod::query()->create([
        'name' => 'Efectivo',
        'code' => 'cash',
        'requires_reference' => false,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('payments.index'))
        ->post(route('payments.methods.store'), [
            'name' => 'Caja',
            'code' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ])
        ->assertRedirect(route('payments.index'))
        ->assertSessionHasErrors('code');
});

it('bloquea pagos sin permiso', function () {
    $user = paymentsUser([]);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertForbidden();
});
