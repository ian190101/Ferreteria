<?php

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Branches\Models\Branch;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function cashFlowUser(array $permissions, ?Branch $branch = null, string $roleName = 'flujo-efectivo-test'): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch ??= Branch::query()->create([
        'name' => 'Sucursal flujo',
        'code' => 'FLOW-'.uniqid(),
        'barcode' => 'BR-FLOW-'.uniqid(),
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function activeCashFlowProfile(bool $enabled = true): void
{
    BusinessProfile::query()->create([
        'name' => 'Perfil flujo efectivo',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized([
            'modules' => [
                'cash_flow' => $enabled,
                'sales_notes' => true,
                'purchases' => true,
                'expenses' => true,
                'banks' => true,
                'cash' => true,
            ],
        ]),
        'applied_at' => now(),
    ]);
}

function cashFlowSeedMovements(User $user, Branch $branch): void
{
    $cash = PaymentMethod::query()->firstOrCreate(['code' => 'cash'], ['name' => 'Efectivo', 'is_active' => true]);
    $qr = PaymentMethod::query()->firstOrCreate(['code' => 'qr'], ['name' => 'QR', 'is_active' => true]);
    $category = ExpenseCategory::query()->firstOrCreate(['code' => 'operativo'], ['name' => 'Operativo', 'is_active' => true]);
    $sale = Sale::query()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'receipt_number' => 'NV-FLOW-'.uniqid(),
        'document_type' => 'sale_note',
        'customer_name' => 'Cliente flujo',
        'sold_at' => now(),
        'exchange_rate_to_bob' => 1,
        'subtotal' => 500,
        'discount_total' => 0,
        'advance_percentage' => 0,
        'advance_amount' => 0,
        'balance_due' => 0,
        'total' => 500,
        'status' => 'paid',
    ]);
    $purchase = Purchase::query()->create([
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'document_number' => 'COM-FLOW-'.uniqid(),
        'purchase_date' => now(),
        'total_amount' => 150,
        'paid_amount' => 150,
        'balance_due' => 0,
        'payment_status' => 'paid',
        'status' => 'received',
    ]);
    $account = BankAccount::query()->create([
        'branch_id' => $branch->id,
        'name' => 'Banco flujo',
        'bank_name' => 'Banco prueba',
        'account_number' => 'ACC-'.uniqid(),
        'currency_code' => 'BOB',
        'opening_balance' => 0,
        'current_balance' => 0,
        'is_active' => true,
    ]);

    SalePayment::query()->create([
        'sale_id' => $sale->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'payment_method_id' => $cash->id,
        'paid_at' => now()->subHours(4),
        'amount' => 500,
        'exchange_rate_to_bob' => 1,
        'amount_bob' => 500,
        'reference' => 'COBRO-1',
    ]);
    PurchasePayment::query()->create([
        'purchase_id' => $purchase->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'payment_method_id' => $qr->id,
        'paid_at' => now()->subHours(2),
        'amount' => 150,
        'reference' => 'PAGO-1',
    ]);
    Expense::query()->create([
        'branch_id' => $branch->id,
        'expense_category_id' => $category->id,
        'payment_method_id' => $cash->id,
        'user_id' => $user->id,
        'spent_at' => now(),
        'description' => 'Flete flujo',
        'amount' => 50,
        'reference' => 'GASTO-1',
        'status' => Expense::STATUS_REGISTERED,
    ]);
    BankTransaction::query()->create([
        'bank_account_id' => $account->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'type' => BankTransaction::TYPE_DEPOSIT,
        'transacted_at' => now(),
        'amount' => 20,
        'reference' => 'BANCO-MANUAL',
        'description' => 'Deposito manual',
        'status' => BankTransaction::STATUS_REGISTERED,
    ]);
}

it('muestra ingresos egresos neto y detalle del flujo de efectivo', function () {
    activeCashFlowProfile();
    $user = cashFlowUser(['cash-flow.view']);

    cashFlowSeedMovements($user, $user->branch);

    $this->actingAs($user)
        ->get(route('cash-flow.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashFlow/Index', false)
            ->where('summary.income', 520)
            ->where('summary.expense', 200)
            ->where('summary.net', 320)
            ->has('movements.data', 4)
        );
});

it('bloquea el modulo sin permiso asignado', function () {
    activeCashFlowProfile();
    $user = cashFlowUser([]);

    $this->actingAs($user)
        ->get(route('cash-flow.index'))
        ->assertForbidden();
});

it('bloquea el modulo si sistemasuperadmin lo desactiva en el perfil', function () {
    activeCashFlowProfile(false);
    $user = cashFlowUser(['cash-flow.view']);

    $this->actingAs($user)
        ->get(route('cash-flow.index'))
        ->assertNotFound();
});

it('limita usuarios normales a sus sucursales y permite al superadmin ver todas', function () {
    activeCashFlowProfile();
    $branchA = Branch::query()->create(['name' => 'Sucursal A', 'code' => 'A-'.uniqid(), 'barcode' => 'BR-A-'.uniqid(), 'is_active' => true]);
    $branchB = Branch::query()->create(['name' => 'Sucursal B', 'code' => 'B-'.uniqid(), 'barcode' => 'BR-B-'.uniqid(), 'is_active' => true]);
    $userA = cashFlowUser(['cash-flow.view'], $branchA);
    $userB = cashFlowUser(['cash-flow.view'], $branchB, 'flujo-efectivo-test-b');

    cashFlowSeedMovements($userA, $branchA);
    cashFlowSeedMovements($userB, $branchB);

    $this->actingAs($userA)
        ->get(route('cash-flow.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.income', 520)
            ->where('summary.expense', 200)
        );

    $superadminRole = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    $superadminRole->givePermissionTo(Permission::firstOrCreate(['name' => 'cash-flow.view', 'guard_name' => 'web']));
    $superadmin = User::factory()->create(['branch_id' => $branchA->id, 'email_verified_at' => now()]);
    $superadmin->assignRole($superadminRole);

    $this->actingAs($superadmin)
        ->get(route('cash-flow.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.income', 1040)
            ->where('summary.expense', 400)
        );
});
