<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Expenses\Http\Requests\UpdateExpenseCategoryRequest;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;

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
        $category->update($request->validated());
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('expenses.index')->with('success', 'Categoria de gasto actualizada correctamente.');
    }

    public function destroy(ExpenseCategory $category): RedirectResponse
    {
        $category->update(['is_active' => false]);
        $category->delete();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('expenses.index')->with('success', 'Categoria de gasto desactivada correctamente.');
    }
}
