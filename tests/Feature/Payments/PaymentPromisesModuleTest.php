<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function promisesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'promesas-pago-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Sucursal cobranza',
        'code' => 'COB-'.$suffix,
        'barcode' => 'BR-COB-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function promiseSale(User $user, float $balance = 100): Sale
{
    return Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente cobranza',
        'customer_contact' => '70000000',
        'sold_at' => now(),
        'subtotal' => 100,
        'discount_total' => 0,
        'total' => 100,
        'balance_due' => $balance,
        'status' => 'issued',
    ]);
}

it('registra promesa de pago para una cuenta por cobrar', function () {
    $user = promisesUser(['payment-promises.view', 'payment-promises.manage']);
    $sale = promiseSale($user, 100);

    $this->actingAs($user)
        ->post(route('payments.promises.store'), [
            'sale_id' => $sale->id,
            'promise_number' => 'PROM-0001',
            'promised_date' => now()->addDay()->toDateString(),
            'promised_amount' => 80,
            'contact_name' => 'Ruben Camacho',
            'contact_phone' => '70775320',
            'channel' => 'whatsapp',
            'notes' => 'Confirma pago por QR',
        ])
        ->assertRedirect(route('payments.promises.index'));

    $promise = PaymentPromise::query()->where('promise_number', 'PROM-0001')->firstOrFail();

    expect((float) $promise->promised_amount)->toBe(80.0)
        ->and($promise->status)->toBe(PaymentPromise::STATUS_PENDING)
        ->and($promise->branch_id)->toBe($user->branch_id);
});

it('bloquea promesa mayor al saldo pendiente', function () {
    $user = promisesUser(['payment-promises.view', 'payment-promises.manage']);
    $sale = promiseSale($user, 50);

    $this->actingAs($user)
        ->from(route('payments.promises.index'))
        ->post(route('payments.promises.store'), [
            'sale_id' => $sale->id,
            'promise_number' => 'PROM-0002',
            'promised_date' => now()->addDay()->toDateString(),
            'promised_amount' => 80,
            'channel' => 'phone',
        ])
        ->assertRedirect(route('payments.promises.index'))
        ->assertSessionHasErrors('promised_amount');
});

it('resuelve promesa pendiente y bloquea doble cierre', function () {
    $user = promisesUser(['payment-promises.view', 'payment-promises.manage']);
    $sale = promiseSale($user, 100);
    $promise = PaymentPromise::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'promise_number' => 'PROM-0003',
        'promised_date' => now()->toDateString(),
        'promised_amount' => 40,
        'channel' => 'phone',
        'status' => PaymentPromise::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->patch(route('payments.promises.resolve', $promise->id), [
            'status' => PaymentPromise::STATUS_FULFILLED,
            'notes' => 'Pago recibido',
        ])
        ->assertRedirect(route('payments.promises.index'));

    $promise->refresh();

    expect($promise->status)->toBe(PaymentPromise::STATUS_FULFILLED)
        ->and($promise->resolved_at)->not->toBeNull();

    $this->actingAs($user)
        ->from(route('payments.promises.index'))
        ->patch(route('payments.promises.resolve', $promise->id), [
            'status' => PaymentPromise::STATUS_BROKEN,
            'notes' => 'Intento duplicado',
        ])
        ->assertRedirect(route('payments.promises.index'))
        ->assertSessionHasErrors('status');
});

it('lista promesas con resumen y bloquea sin permiso', function () {
    $user = promisesUser(['payment-promises.view']);
    $sale = promiseSale($user, 100);
    PaymentPromise::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'promise_number' => 'PROM-0004',
        'promised_date' => now()->subDay()->toDateString(),
        'promised_amount' => 30,
        'channel' => 'phone',
        'status' => PaymentPromise::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->get(route('payments.promises.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Promises/Index', false)
            ->has('promises.data', 1)
            ->where('summary.pending_count', 1)
            ->where('summary.overdue_count', 1)
        );

    $blocked = promisesUser([]);

    $this->actingAs($blocked)
        ->get(route('payments.promises.index'))
        ->assertForbidden();
});
