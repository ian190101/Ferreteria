<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreExpenseRequest;
use App\Modules\Expenses\Http\Requests\VoidExpenseRequest;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request);
        $summaryVersion = Cache::get('expenses:summary_version', 1);
        $summaryKey = sprintf(
            'expenses:summary:%s:%s:%s:%s:%s:%s',
            $summaryVersion,
            $request->input('branch_id', 'all'),
            $request->input('expense_category_id', 'all'),
            $request->input('status', 'registered'),
            $request->input('from', 'start'),
            $request->input('to', 'end'),
        );

        $summary = Cache::remember($summaryKey, now()->addMinutes(5), fn () => [
            'total_amount' => (float) (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
        ]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $query
                ->with(['branch:id,name', 'category:id,name', 'paymentMethod:id,name', 'user:id,name'])
                ->latest('spent_at')
                ->paginate($request->integer('per_page', 15))
                ->withQueryString(),
            'summary' => $summary,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'categories' => UiCatalogCache::activeExpenseCategories(),
            'categoryCatalog' => ExpenseCategory::query()
                ->withCount('expenses')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate($request->integer('categories_per_page', 10), ['*'], 'categories_page')
                ->withQueryString(),
            'paymentMethods' => UiCatalogCache::activePaymentMethods(['id', 'name']),
            'filters' => $request->only(['branch_id', 'expense_category_id', 'status', 'from', 'to', 'per_page']),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        Expense::query()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'spent_at' => now(),
        ]);

        $this->bumpSummaryCache();

        return redirect()->route('expenses.index')->with('success', 'Gasto registrado correctamente.');
    }

    public function void(VoidExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $notes = trim(implode("\n", array_filter([
            $expense->notes,
            'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString(),
        ])));

        $expense->update([
            'status' => Expense::STATUS_VOID,
            'notes' => $notes,
        ]);

        $this->bumpSummaryCache();

        return redirect()->route('expenses.index')->with('success', 'Gasto anulado correctamente.');
    }

    private function filteredQuery(Request $request)
    {
        return Expense::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('expense_category_id'), fn ($query) => $query->where('expense_category_id', $request->integer('expense_category_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()), fn ($query) => $query->where('status', Expense::STATUS_REGISTERED))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('spent_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('spent_at', '<=', $request->date('to')));
    }

    private function bumpSummaryCache(): void
    {
        Cache::forever('expenses:summary_version', ((int) Cache::get('expenses:summary_version', 1)) + 1);
    }
}
