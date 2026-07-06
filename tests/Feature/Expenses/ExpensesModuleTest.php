<?php

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function expensesUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'gastos-test', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $branch = Branch::query()->create([
        'name' => 'Gastos central',
        'code' => 'GASTOS',
        'barcode' => 'BR-GASTOS',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'branch_id' => $branch->id,
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

it('registra gastos operativos y muestra resumen paginado', function () {
    $user = expensesUser(['expenses.view', 'expenses.manage']);
    $category = ExpenseCategory::query()->create(['name' => 'Transporte', 'code' => 'transport', 'is_active' => true]);
    $method = PaymentMethod::query()->create(['name' => 'Efectivo', 'code' => 'cash', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('expenses.store'), [
            'branch_id' => $user->branch_id,
            'expense_category_id' => $category->id,
            'payment_method_id' => $method->id,
            'spent_at' => now()->format('Y-m-d H:i:s'),
            'description' => 'Flete local',
            'amount' => 75.50,
            'reference' => 'REC-001',
            'status' => 'registered',
            'notes' => null,
        ])
        ->assertRedirect(route('expenses.index'));

    $this->actingAs($user)
        ->get(route('expenses.index', ['expense_category_id' => $category->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Expenses/Index', false)
            ->has('expenses.data', 1)
            ->where('expenses.data.0.description', 'Flete local')
            ->where('summary.total_amount', 75.50)
            ->where('summary.count', 1)
        );
});

it('crea categorias de gasto gestionables', function () {
    $user = expensesUser(['expenses.view', 'expenses.manage']);

    $this->actingAs($user)
        ->post(route('expenses.categories.store'), [
            'name' => 'Mantenimiento',
            'code' => 'maintenance',
            'is_active' => true,
        ])
        ->assertRedirect(route('expenses.index'));

    expect(ExpenseCategory::query()->where('code', 'maintenance')->exists())->toBeTrue();
});

it('edita y desactiva categorias de gasto', function () {
    $user = expensesUser(['expenses.view', 'expenses.manage']);
    $category = ExpenseCategory::query()->create(['name' => 'Transporte', 'code' => 'transport', 'is_active' => true]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Expenses/Index', false)
            ->has('categoryCatalog.data', 1)
            ->where('categoryCatalog.data.0.code', 'transport')
        );

    $this->actingAs($user)
        ->put(route('expenses.categories.update', $category->id), [
            'name' => 'Logistica',
            'code' => 'logistics',
            'is_active' => true,
        ])
        ->assertRedirect(route('expenses.index'));

    $category->refresh();

    expect($category->name)->toBe('Logistica')
        ->and($category->code)->toBe('logistics');

    $this->actingAs($user)
        ->delete(route('expenses.categories.destroy', $category->id))
        ->assertRedirect(route('expenses.index'));

    $category->refresh();

    expect($category->is_active)->toBeFalse()
        ->and($category->trashed())->toBeTrue();
});

it('bloquea codigos duplicados en categorias de gasto', function () {
    $user = expensesUser(['expenses.view', 'expenses.manage']);

    ExpenseCategory::query()->create(['name' => 'Transporte', 'code' => 'transport', 'is_active' => true]);

    $this->actingAs($user)
        ->from(route('expenses.index'))
        ->post(route('expenses.categories.store'), [
            'name' => 'Otro transporte',
            'code' => 'transport',
            'is_active' => true,
        ])
        ->assertRedirect(route('expenses.index'))
        ->assertSessionHasErrors('code');
});

it('excluye gastos anulados del resumen por defecto', function () {
    $user = expensesUser(['expenses.view']);
    $category = ExpenseCategory::query()->create(['name' => 'Administracion', 'code' => 'admin', 'is_active' => true]);

    Expense::query()->create([
        'branch_id' => $user->branch_id,
        'expense_category_id' => $category->id,
        'payment_method_id' => null,
        'user_id' => $user->id,
        'spent_at' => now(),
        'description' => 'Gasto anulado',
        'amount' => 30,
        'status' => 'void',
    ]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_amount', 0)
            ->where('summary.count', 0)
        );
});

it('anula gastos registrados con motivo e invalida resumen cacheado', function () {
    Cache::flush();

    $user = expensesUser(['expenses.view', 'expenses.manage']);
    $category = ExpenseCategory::query()->create(['name' => 'Servicios', 'code' => 'services', 'is_active' => true]);
    $expense = Expense::query()->create([
        'branch_id' => $user->branch_id,
        'expense_category_id' => $category->id,
        'payment_method_id' => null,
        'user_id' => $user->id,
        'spent_at' => now(),
        'description' => 'Pago de luz',
        'amount' => 100,
        'status' => Expense::STATUS_REGISTERED,
    ]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_amount', 100)
            ->where('summary.count', 1)
        );

    $this->actingAs($user)
        ->patch(route('expenses.void', $expense->id), [
            'reason' => 'Registro duplicado',
        ])
        ->assertRedirect(route('expenses.index'));

    $expense->refresh();

    expect($expense->status)->toBe(Expense::STATUS_VOID)
        ->and($expense->notes)->toContain('Registro duplicado');

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_amount', 0)
            ->where('summary.count', 0)
        );
});

it('bloquea anular dos veces el mismo gasto', function () {
    $user = expensesUser(['expenses.view', 'expenses.manage']);
    $category = ExpenseCategory::query()->create(['name' => 'Servicios', 'code' => 'services', 'is_active' => true]);
    $expense = Expense::query()->create([
        'branch_id' => $user->branch_id,
        'expense_category_id' => $category->id,
        'payment_method_id' => null,
        'user_id' => $user->id,
        'spent_at' => now(),
        'description' => 'Gasto anulado',
        'amount' => 40,
        'status' => Expense::STATUS_VOID,
    ]);

    $this->actingAs($user)
        ->from(route('expenses.index'))
        ->patch(route('expenses.void', $expense->id), [
            'reason' => 'Intento repetido',
        ])
        ->assertRedirect(route('expenses.index'))
        ->assertSessionHasErrors('expense');
});

it('bloquea gastos sin permiso', function () {
    $user = expensesUser([]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertForbidden();
});
