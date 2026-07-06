<?php

namespace App\Modules\Banks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banks\Http\Requests\StoreBankAccountRequest;
use App\Modules\Banks\Http\Requests\StoreBankTransactionRequest;
use App\Modules\Banks\Http\Requests\UpdateBankAccountRequest;
use App\Modules\Banks\Http\Requests\VoidBankTransactionRequest;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BankController extends Controller
{
    public function index(Request $request): Response
    {
        $accountsQuery = BankAccount::query()
            ->with('branch:id,name')
            ->withCount('transactions')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        $transactionsQuery = BankTransaction::query()
            ->with(['account:id,name,account_number,currency_code', 'branch:id,name', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('bank_account_id'), fn ($query) => $query->where('bank_account_id', $request->integer('bank_account_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('transacted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('transacted_at', '<=', $request->date('to')));

        $summary = Cache::remember($this->summaryKey($request), now()->addMinutes(5), fn () => [
            'accounts_count' => (clone $accountsQuery)->count(),
            'total_balance' => (float) (clone $accountsQuery)->sum('current_balance'),
            'pending_reconciliation' => (clone $transactionsQuery)
                ->where('status', BankTransaction::STATUS_REGISTERED)
                ->whereNull('reconciled_at')
                ->count(),
        ]);

        return Inertia::render('Banks/Index', [
            'accounts' => $accountsQuery
                ->latest()
                ->paginate($request->integer('accounts_per_page', 10), ['*'], 'accounts_page')
                ->withQueryString(),
            'transactions' => $transactionsQuery
                ->latest('transacted_at')
                ->paginate($request->integer('per_page', 15))
                ->withQueryString(),
            'summary' => $summary,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'activeAccounts' => BankAccount::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'account_number', 'currency_code', 'current_balance']),
            'filters' => $request->only(['branch_id', 'bank_account_id', 'status', 'type', 'from', 'to', 'per_page']),
        ]);
    }

    public function storeAccount(StoreBankAccountRequest $request): RedirectResponse
    {
        $openingBalance = round((float) $request->input('opening_balance'), 2);

        BankAccount::query()->create([
            ...$request->validated(),
            'current_balance' => $openingBalance,
        ]);

        $this->bumpSummaryCache();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('banks.index')->with('success', 'Cuenta bancaria creada correctamente.');
    }

    public function updateAccount(UpdateBankAccountRequest $request, BankAccount $account): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $account->branch_id), 403);

        $account->update($request->validated());
        $this->bumpSummaryCache();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('banks.index')->with('success', 'Cuenta bancaria actualizada correctamente.');
    }

    public function storeTransaction(StoreBankTransactionRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $account = BankAccount::query()
                ->whereKey($request->integer('bank_account_id'))
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages(['bank_account_id' => 'La cuenta bancaria esta inactiva.']);
            }

            $amount = round((float) $request->input('amount'), 2);
            $delta = $this->signedAmount($request->string('type')->toString(), $amount);

            $transaction = BankTransaction::query()->create([
                ...$request->validated(),
                'branch_id' => $account->branch_id,
                'user_id' => $request->user()->id,
                'transacted_at' => now(),
                'status' => BankTransaction::STATUS_REGISTERED,
            ]);

            $account->increment('current_balance', $delta);

            // El saldo queda materializado para reportes rapidos; la auditoria guarda cada cambio critico.
            $transaction->refresh();
        });

        $this->bumpSummaryCache();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('banks.index')->with('success', 'Movimiento bancario registrado correctamente.');
    }

    public function reconcile(Request $request, BankTransaction $transaction): RedirectResponse
    {
        abort_unless($request->user()?->can('banks.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $transaction->branch_id), 403);

        if ($transaction->status !== BankTransaction::STATUS_REGISTERED) {
            throw ValidationException::withMessages(['transaction' => 'Solo movimientos registrados pueden conciliarse.']);
        }

        $transaction->update(['reconciled_at' => now()]);
        $this->bumpSummaryCache();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('banks.index')->with('success', 'Movimiento conciliado correctamente.');
    }

    public function void(VoidBankTransactionRequest $request, BankTransaction $transaction): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $transaction->branch_id), 403);

        DB::transaction(function () use ($request, $transaction) {
            $transaction = BankTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status === BankTransaction::STATUS_VOID) {
                throw ValidationException::withMessages(['transaction' => 'El movimiento ya fue anulado.']);
            }

            $account = BankAccount::query()->whereKey($transaction->bank_account_id)->lockForUpdate()->firstOrFail();
            $account->decrement('current_balance', $this->signedAmount($transaction->type, (float) $transaction->amount));

            $transaction->update([
                'status' => BankTransaction::STATUS_VOID,
                'voided_at' => now(),
                'void_reason' => $request->string('reason')->toString(),
            ]);
        });

        $this->bumpSummaryCache();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('banks.index')->with('success', 'Movimiento bancario anulado correctamente.');
    }

    private function signedAmount(string $type, float $amount): float
    {
        return in_array($type, [BankTransaction::TYPE_WITHDRAWAL], true) ? -$amount : $amount;
    }

    private function summaryKey(Request $request): string
    {
        return sprintf(
            'banks:summary:%s:%s:%s:%s:%s:%s:%s',
            Cache::get('banks:summary_version', 1),
            $request->input('branch_id', 'all'),
            $request->input('bank_account_id', 'all'),
            $request->input('status', 'all'),
            $request->input('type', 'all'),
            $request->input('from', 'start'),
            $request->input('to', 'end'),
        );
    }

    private function bumpSummaryCache(): void
    {
        Cache::forever('banks:summary_version', ((int) Cache::get('banks:summary_version', 1)) + 1);
    }
}
