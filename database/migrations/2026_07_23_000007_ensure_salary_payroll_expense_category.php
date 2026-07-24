<?php

use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('expense_categories')->updateOrInsert(
            ['code' => ExpenseCategory::SALARY_PAYROLL_CODE],
            [
                'name' => ExpenseCategory::SALARY_PAYROLL_NAME,
                'is_active' => true,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('expense_categories')
            ->where('code', ExpenseCategory::SALARY_PAYROLL_CODE)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('expenses')
                    ->whereColumn('expenses.expense_category_id', 'expense_categories.id');
            })
            ->delete();
    }
};
