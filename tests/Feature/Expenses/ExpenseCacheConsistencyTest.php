<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\CommittedDatabaseTestCase;

final class ExpenseCacheConsistencyTest extends CommittedDatabaseTestCase
{
    public function test_aisla_sucursales_no_publica_datos_sin_confirmar_e_invalida_despues_del_commit(): void
    {
        foreach (['expenses.view', 'expenses.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::findOrCreate('qa-expenses-cache', 'web');
        $role->syncPermissions(['expenses.view', 'expenses.manage']);
        $category = ExpenseCategory::query()->create(['name' => 'Servicios QA', 'code' => 'qa-services', 'is_active' => true]);
        $users = [];
        $expenses = [];
        foreach ([100, 200] as $index => $amount) {
            $branch = Branch::query()->create(['name' => 'Caché '.$index, 'code' => 'QA-CACHE-'.$index, 'barcode' => 'QA-CACHE-'.$index, 'is_active' => true]);
            $user = User::factory()->create(['branch_id' => $branch->id, 'email_verified_at' => now()]);
            $user->assignRole($role);
            $users[] = $user;
            $expenses[] = Expense::query()->create(['branch_id' => $branch->id, 'expense_category_id' => $category->id,
                'user_id' => $user->id, 'spent_at' => now(), 'description' => 'Gasto QA', 'amount' => $amount, 'status' => Expense::STATUS_REGISTERED]);
        }
        foreach ($users as $index => $user) {
            $this->actingAs($user)->get(route('expenses.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('summary.total_amount', ($index + 1) * 100));
        }
        DB::beginTransaction();
        try {
            DB::table('expenses')->where('id', $expenses[1]->id)->update(['amount' => 999]);
            $this->actingAs($users[1])->get(route('expenses.index'))
                ->assertInertia(fn (Assert $page) => $page->where('summary.total_amount', 999));
        } finally {
            DB::rollBack();
        }
        $this->actingAs($users[1])->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total_amount', 200));
        $version = (int) Cache::get('expenses:summary_version', 1);
        $this->actingAs($users[0])->patch(route('expenses.void', $expenses[0]), ['reason' => 'Corrección QA'])
            ->assertRedirect(route('expenses.index'))->assertSessionHasNoErrors();
        $this->assertGreaterThan($version, (int) Cache::get('expenses:summary_version', 1));
        $this->actingAs($users[0])->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total_amount', 0)->where('summary.count', 0));
        $this->actingAs($users[1])->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.total_amount', 200));
    }
}
