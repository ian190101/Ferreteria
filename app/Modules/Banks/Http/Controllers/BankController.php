<?php

namespace App\Modules\Banks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Banks\Http\Requests\StoreBankAccountRequest;
use App\Modules\Banks\Http\Requests\StoreBankTransactionRequest;
use App\Modules\Banks\Http\Requests\UpdateBankAccountRequest;
use App\Modules\Banks\Http\Requests\VoidBankTransactionRequest;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Finance\Services\FinancialLedgerService;
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
        $user = $request->user();
        $isSuperAdministrator = $user->isSuperAdministrator();
        $cashSessionRequested = $request->filled('cash_session_id');
        $selectedCashSession = $this->selectedCashSession($request);

        $accountsQuery = BankAccount::query()
            ->with('branch:id,name')
            ->withCount('transactions')
            ->when(true, fn ($query) => BranchAccess::apply($query, $user))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        $transactionsQuery = BankTransaction::query()
            ->with(['account:id,name,account_number,currency_code', 'branch:id,name', 'user:id,name', 'cashSession:id,opened_at,closed_at,status'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $user))
            ->when(! $isSuperAdministrator, fn ($query) => $query->where('user_id', $user->id))
            ->when($request->filled('bank_account_id'), fn ($query) => $query->where('bank_account_id', $request->integer('bank_account_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($isSuperAdministrator && $request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($cashSessionRequested && ! $selectedCashSession, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($selectedCashSession, fn ($query) => $query->where(function ($cashQuery) use ($selectedCashSession) {
                $endAt = $selectedCashSession->closed_at ?: now();

                $cashQuery->where('cash_register_session_id', $selectedCashSession->id)
                    ->orWhere(fn ($fallback) => $fallback
                        ->whereNull('cash_register_session_id')
                        ->where('branch_id', $selectedCashSession->branch_id)
                        ->where('user_id', $selectedCashSession->opened_by)
                        ->whereBetween('transacted_at', [$selectedCashSession->opened_at, $endAt]));
            }))
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
            'branches' => UiCatalogCache::activeBranchesForUser($user),
            'activeAccounts' => BankAccount::query()
                ->when(true, fn ($query) => BranchAccess::apply($query, $user))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'account_number', 'currency_code', 'current_balance']),
            'cashSessions' => CashRegisterSession::query()
                ->with(['branch:id,name', 'opener:id,name'])
                ->when(true, fn ($query) => BranchAccess::apply($query, $user))
                ->when(! $isSuperAdministrator, fn ($query) => $query->where('opened_by', $user->id))
                ->latest('opened_at')
                ->limit(80)
                ->get(['id', 'branch_id', 'opened_by', 'opened_at', 'closed_at', 'status']),
            'users' => User::query()
                ->withoutSystemSuperadmins()
                ->whereIn('id', BankTransaction::query()
                    ->when(true, fn ($query) => BranchAccess::apply($query, $user))
                    ->when(! $isSuperAdministrator, fn ($query) => $query->where('user_id', $user->id))
                    ->select('user_id')
                    ->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => $request->only(['branch_id', 'bank_account_id', 'cash_session_id', 'user_id', 'status', 'type', 'from', 'to', 'per_page']),
        ]);
    }

    public function storeAccount(StoreBankAccountRequest $request, FinancialLedgerService $ledger): RedirectResponse
    {
        $openingBalance = round((float) $request->input('opening_balance'), 2);

        BankAccount::query()->create([
            ...$request->validated(),
            'current_balance' => $openingBalance,
        ]);

        $ledger->bumpFinancialCaches();

        return redirect()->route('banks.index')->with('success', 'Cuenta bancaria creada correctamente.');
    }

    public function updateAccount(UpdateBankAccountRequest $request, BankAccount $account, FinancialLedgerService $ledger): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $account->branch_id), 403);

        $account->update($request->validated());
        $ledger->bumpFinancialCaches();

        return redirect()->route('banks.index')->with('success', 'Cuenta bancaria actualizada correctamente.');
    }

    public function storeTransaction(StoreBankTransactionRequest $request, FinancialLedgerService $ledger): RedirectResponse
    {
        DB::transaction(function () use ($request, $ledger) {
            $account = BankAccount::query()
                ->whereKey($request->integer('bank_account_id'))
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages(['bank_account_id' => 'La cuenta bancaria esta inactiva.']);
            }

            $amount = round((float) $request->input('amount'), 2);
            $delta = $ledger->signedBankAmount($request->string('type')->toString(), $amount);

            $transaction = BankTransaction::query()->create([
                ...$request->validated(),
                'branch_id' => $account->branch_id,
                'user_id' => $request->user()->id,
                'cash_register_session_id' => $this->openCashSessionId((int) $account->branch_id, (int) $request->user()->id),
                'transacted_at' => now(),
                'status' => BankTransaction::STATUS_REGISTERED,
            ]);

            $account->increment('current_balance', $delta);
        });

        $ledger->bumpFinancialCaches();

        return redirect()->route('banks.index')->with('success', 'Movimiento bancario registrado correctamente.');
    }

    public function reconcile(Request $request, BankTransaction $transaction, FinancialLedgerService $ledger): RedirectResponse
    {
        abort_unless($request->user()?->can('banks.manage'), 403);
        abort_unless(BranchAccess::canAccess($request->user(), (int) $transaction->branch_id), 403);

        if ($transaction->status !== BankTransaction::STATUS_REGISTERED) {
            throw ValidationException::withMessages(['transaction' => 'Solo movimientos registrados pueden conciliarse.']);
        }

        $transaction->update(['reconciled_at' => now()]);
        $ledger->bumpFinancialCaches();

        return redirect()->route('banks.index')->with('success', 'Movimiento conciliado correctamente.');
    }

    public function void(VoidBankTransactionRequest $request, BankTransaction $transaction, FinancialLedgerService $ledger): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $transaction->branch_id), 403);

        DB::transaction(function () use ($request, $transaction, $ledger) {
            $transaction = BankTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status === BankTransaction::STATUS_VOID) {
                throw ValidationException::withMessages(['transaction' => 'El movimiento ya fue anulado.']);
            }

            $ledger->voidBankTransaction($transaction, $request->string('reason')->toString());
        });

        return redirect()->route('banks.index')->with('success', 'Movimiento bancario anulado correctamente.');
    }

    private function summaryKey(Request $request): string
    {
        return sprintf(
            'banks:summary:%s:%s:%s:%s:%s:%s:%s:%s:%s',
            Cache::get('banks:summary_version', 1),
            $request->input('branch_id', 'all'),
            $request->input('bank_account_id', 'all'),
            $request->input('cash_session_id', 'all'),
            $request->input('user_id', 'all'),
            $request->input('status', 'all'),
            $request->input('type', 'all'),
            $request->input('from', 'start'),
            $request->input('to', 'end'),
        );
    }

    private function openCashSessionId(int $branchId, int $userId): ?int
    {
        return CashRegisterSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $userId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->latest('opened_at')
            ->value('id');
    }

    private function selectedCashSession(Request $request): ?CashRegisterSession
    {
        if (! $request->filled('cash_session_id')) {
            return null;
        }

        return CashRegisterSession::query()
            ->whereKey($request->integer('cash_session_id'))
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query->where('opened_by', $request->user()->id))
            ->first();
    }

}
