<?php

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Branches\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function banksUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'bancos-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Bancos central',
        'code' => 'BANCOS',
        'barcode' => 'BR-BANCOS',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('crea cuenta bancaria y lista resumen paginado', function () {
    $user = banksUser(['banks.view', 'banks.manage']);

    $this->actingAs($user)
        ->post(route('banks.accounts.store'), [
            'branch_id' => $user->branch_id,
            'name' => 'Cuenta BNB',
            'bank_name' => 'BNB',
            'account_number' => '123-456',
            'currency_code' => 'BOB',
            'opening_balance' => 500,
            'is_active' => true,
        ])
        ->assertRedirect(route('banks.index'));

    $this->actingAs($user)
        ->get(route('banks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Banks/Index', false)
            ->has('accounts.data', 1)
            ->where('accounts.data.0.name', 'Cuenta BNB')
            ->where('summary.accounts_count', 1)
            ->where('summary.total_balance', 500)
        );
});

it('registra movimiento bancario y actualiza saldo de cuenta', function () {
    $user = banksUser(['banks.view', 'banks.manage']);
    $account = BankAccount::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Cuenta Union',
        'bank_name' => 'Union',
        'account_number' => '789',
        'currency_code' => 'BOB',
        'opening_balance' => 100,
        'current_balance' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('banks.transactions.store'), [
            'bank_account_id' => $account->id,
            'type' => BankTransaction::TYPE_DEPOSIT,
            'transacted_at' => now()->format('Y-m-d H:i:s'),
            'amount' => 250,
            'reference' => 'DEP-001',
            'description' => 'Deposito de prueba',
        ])
        ->assertRedirect(route('banks.index'));

    $account->refresh();

    expect((float) $account->current_balance)->toBe(350.0)
        ->and(BankTransaction::query()->where('reference', 'DEP-001')->exists())->toBeTrue();
});

it('concilia y anula movimiento bancario revirtiendo saldo', function () {
    $user = banksUser(['banks.view', 'banks.manage']);
    $account = BankAccount::query()->create([
        'branch_id' => $user->branch_id,
        'name' => 'Cuenta Mercantil',
        'bank_name' => 'Mercantil',
        'account_number' => '999',
        'currency_code' => 'BOB',
        'opening_balance' => 300,
        'current_balance' => 300,
        'is_active' => true,
    ]);
    $transaction = BankTransaction::query()->create([
        'bank_account_id' => $account->id,
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'type' => BankTransaction::TYPE_WITHDRAWAL,
        'transacted_at' => now(),
        'amount' => 75,
        'description' => 'Retiro',
        'status' => BankTransaction::STATUS_REGISTERED,
    ]);
    $account->decrement('current_balance', 75);

    $this->actingAs($user)
        ->patch(route('banks.transactions.reconcile', $transaction->id))
        ->assertRedirect(route('banks.index'));

    expect($transaction->refresh()->reconciled_at)->not->toBeNull();

    $this->actingAs($user)
        ->patch(route('banks.transactions.void', $transaction->id), [
            'reason' => 'Registro duplicado',
        ])
        ->assertRedirect(route('banks.index'));

    $account->refresh();
    $transaction->refresh();

    expect($transaction->status)->toBe(BankTransaction::STATUS_VOID)
        ->and((float) $account->current_balance)->toBe(300.0);
});

it('bloquea bancos sin permiso', function () {
    $user = banksUser([]);

    $this->actingAs($user)
        ->get(route('banks.index'))
        ->assertForbidden();
});
