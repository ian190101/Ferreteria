<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function creditNotesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'notas-credito-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $suffix = uniqid();
    $branch = Branch::query()->create([
        'name' => 'Sucursal creditos',
        'code' => 'NC-'.$suffix,
        'barcode' => 'BR-NC-'.$suffix,
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function creditNotesSale(User $user, float $total = 200, float $balance = 200): Sale
{
    return Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente credito',
        'sold_at' => now(),
        'subtotal' => $total,
        'discount_total' => 0,
        'advance_amount' => 0,
        'total' => $total,
        'balance_due' => $balance,
        'status' => $balance < $total ? 'partial_paid' : 'issued',
    ]);
}

function creditNotesReturn(User $user, Sale $sale, float $amount = 80): SaleReturn
{
    return SaleReturn::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'return_number' => 'DEV-'.uniqid(),
        'returned_at' => now(),
        'total_amount' => $amount,
        'reason' => 'Devolucion vinculada',
    ]);
}

it('emite nota de credito y reduce el saldo pendiente de la venta', function () {
    $user = creditNotesUser(['credit-notes.view', 'credit-notes.manage']);
    $sale = creditNotesSale($user, 200, 200);

    $this->actingAs($user)
        ->post(route('payments.credit-notes.store'), [
            'sale_id' => $sale->id,
            'credit_number' => 'NC-0001',
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 60,
            'reason' => 'Bonificacion comercial',
            'notes' => null,
        ])
        ->assertRedirect(route('payments.credit-notes.index'));

    $sale->refresh();

    expect((float) $sale->balance_due)->toBe(140.0)
        ->and($sale->status)->toBe('partial_paid')
        ->and((float) CreditNote::query()->where('credit_number', 'NC-0001')->value('amount_bob'))->toBe(60.0);
});

it('bloquea nota de credito mayor al saldo pendiente', function () {
    $user = creditNotesUser(['credit-notes.view', 'credit-notes.manage']);
    $sale = creditNotesSale($user, 200, 50);

    $this->actingAs($user)
        ->from(route('payments.credit-notes.index'))
        ->post(route('payments.credit-notes.store'), [
            'sale_id' => $sale->id,
            'credit_number' => 'NC-0002',
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 80,
            'reason' => 'Exceso',
        ])
        ->assertRedirect(route('payments.credit-notes.index'))
        ->assertSessionHasErrors('amount');
});

it('limita la nota de credito al monto disponible de la devolucion vinculada', function () {
    $user = creditNotesUser(['credit-notes.view', 'credit-notes.manage']);
    $sale = creditNotesSale($user, 200, 200);
    $saleReturn = creditNotesReturn($user, $sale, 80);
    CreditNote::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'sale_return_id' => $saleReturn->id,
        'credit_number' => 'NC-PREVIA',
        'issued_at' => now(),
        'amount' => 50,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 50,
        'reason' => 'Parcial',
    ]);

    $this->actingAs($user)
        ->from(route('payments.credit-notes.index'))
        ->post(route('payments.credit-notes.store'), [
            'sale_id' => $sale->id,
            'sale_return_id' => $saleReturn->id,
            'credit_number' => 'NC-0003',
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 40,
            'reason' => 'Exceso devolucion',
        ])
        ->assertRedirect(route('payments.credit-notes.index'))
        ->assertSessionHasErrors('amount');
});

it('anula nota de credito y recalcula saldo considerando pagos activos', function () {
    $user = creditNotesUser(['credit-notes.view', 'credit-notes.manage']);
    $sale = creditNotesSale($user, 200, 110);
    $method = PaymentMethod::query()->create(['name' => 'Efectivo', 'code' => 'cash', 'is_active' => true]);
    SalePayment::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'payment_method_id' => $method->id,
        'paid_at' => now(),
        'amount' => 50,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 50,
    ]);
    $creditNote = CreditNote::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'credit_number' => 'NC-0004',
        'issued_at' => now(),
        'amount' => 40,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 40,
        'reason' => 'Credito anulado',
    ]);

    $this->actingAs($user)
        ->patch(route('payments.credit-notes.void', $creditNote->id), [
            'reason' => 'Error de emision',
        ])
        ->assertRedirect(route('payments.credit-notes.index'));

    $sale->refresh();

    expect((float) $sale->balance_due)->toBe(150.0)
        ->and($sale->status)->toBe('partial_paid')
        ->and(CreditNote::query()->find($creditNote->id))->toBeNull()
        ->and(CreditNote::withTrashed()->find($creditNote->id)?->trashed())->toBeTrue();
});

it('lista notas de credito con permiso y bloquea sin permiso', function () {
    $user = creditNotesUser(['credit-notes.view']);

    $this->actingAs($user)
        ->get(route('payments.credit-notes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Payments/CreditNotes/Index', false)
            ->has('creditNotes.data', 0)
        );

    $blocked = creditNotesUser([]);

    $this->actingAs($blocked)
        ->get(route('payments.credit-notes.index'))
        ->assertForbidden();
});
