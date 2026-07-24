<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Expenses\Http\Requests\UpdateExpenseCategoryRequest;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends Controller
{
    public function store(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        ExpenseCategory::query()->create($request->validated());
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('expenses.index')->with('success', 'Categoria de gasto creada correctamente.');
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        if ($category->code === ExpenseCategory::SALARY_PAYROLL_CODE && $request->string('code')->toString() !== ExpenseCategory::SALARY_PAYROLL_CODE) {
            throw ValidationException::withMessages([
                'code' => 'La categoria Pago de sueldos es interna y no puede cambiar de codigo.',
            ]);
        }

        if ($category->code === ExpenseCategory::SALARY_PAYROLL_CODE && ! $request->boolean('is_active')) {
            throw ValidationException::withMessages([
                'is_active' => 'La categoria Pago de sueldos debe permanecer activa para registrar planilla desde egresos.',
            ]);
        }

        $category->update($request->validated());
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('expenses.index')->with('success', 'Categoria de gasto actualizada correctamente.');
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        if ($category->code === ExpenseCategory::SALARY_PAYROLL_CODE) {
            throw ValidationException::withMessages([
                'category' => 'La categoria Pago de sueldos no se puede desactivar porque conecta egresos con planilla.',
            ]);
        }

        $category->update(['is_active' => false]);
        $category->delete();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('expenses.index')->with('success', 'Categoria de gasto desactivada correctamente.');
    }
}
