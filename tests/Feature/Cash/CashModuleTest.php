<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function cashUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'caja-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Caja pruebas',
        'code' => 'CAJA-TEST',
        'barcode' => 'BR-CAJA-TEST',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

function cashSale(User $user): Sale
{
    return Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'receipt_number' => 'CAJA-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente caja',
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
}

it('abre caja por sucursal y la lista paginada', function () {
    $user = cashUser(['cash.view', 'cash.manage']);

    $this->actingAs($user)
        ->post(route('cash.open'), [
            'branch_id' => $user->branch_id,
            'opened_at' => now()->format('Y-m-d H:i:s'),
            'opening_amount' => 100,
            'opening_notes' => 'Inicio de turno',
        ])
        ->assertRedirect(route('cash.index'));

    $this->actingAs($user)
        ->get(route('cash.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cash/Index', false)
            ->has('sessions.data', 1)
            ->has('openSessions', 1)
        );
});

it('bloquea doble apertura de caja en la misma sucursal', function () {
    $user = cashUser(['cash.view', 'cash.manage']);

    CashRegisterSession::query()->create([
        'branch_id' => $user->branch_id,
        'opened_by' => $user->id,
        'opened_at' => now(),
        'opening_amount' => 100,
        'expected_cash_amount' => 100,
        'status' => CashRegisterSession::STATUS_OPEN,
    ]);

    $this->actingAs($user)
        ->from(route('cash.index'))
        ->post(route('cash.open'), [
            'branch_id' => $user->branch_id,
            'opened_at' => now()->format('Y-m-d H:i:s'),
            'opening_amount' => 50,
        ])
        ->assertRedirect(route('cash.index'))
        ->assertSessionHasErrors('branch_id');
});

it('cierra caja calculando efectivo esperado y diferencia', function () {
    $user = cashUser(['cash.view', 'cash.manage']);
    $cash = PaymentMethod::query()->create(['name' => 'Efectivo', 'code' => 'cash', 'is_active' => true]);
    $category = ExpenseCategory::query()->create(['name' => 'Transporte', 'code' => 'transport', 'is_active' => true]);
    $openedAt = now()->subHours(2);
    $closedAt = now();
    $session = CashRegisterSession::query()->create([
        'branch_id' => $user->branch_id,
        'opened_by' => $user->id,
        'opened_at' => $openedAt,
        'opening_amount' => 100,
        'expected_cash_amount' => 100,
        'status' => CashRegisterSession::STATUS_OPEN,
    ]);
    $sale = cashSale($user);

    SalePayment::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'payment_method_id' => $cash->id,
        'paid_at' => $openedAt->copy()->addMinutes(30),
        'amount' => 50,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 50,
    ]);

    Expense::query()->create([
        'branch_id' => $user->branch_id,
        'expense_category_id' => $category->id,
        'payment_method_id' => $cash->id,
        'user_id' => $user->id,
        'spent_at' => $openedAt->copy()->addHour(),
        'description' => 'Flete',
        'amount' => 20,
        'status' => 'registered',
    ]);

    $this->actingAs($user)
        ->put(route('cash.close', $session), [
            'closed_at' => $closedAt->format('Y-m-d H:i:s'),
            'counted_cash_amount' => 125,
            'closing_notes' => 'Faltante revisado',
        ])
        ->assertRedirect(route('cash.index'));

    $session->refresh();

    expect($session->status)->toBe(CashRegisterSession::STATUS_CLOSED)
        ->and((float) $session->cash_income_amount)->toBe(50.0)
        ->and((float) $session->cash_expense_amount)->toBe(20.0)
        ->and((float) $session->expected_cash_amount)->toBe(130.0)
        ->and((float) $session->difference_amount)->toBe(-5.0);
});

it('bloquea caja sin permiso', function () {
    $user = cashUser([]);

    $this->actingAs($user)
        ->get(route('cash.index'))
        ->assertForbidden();
});
