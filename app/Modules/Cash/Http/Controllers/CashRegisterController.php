<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Http\Requests\CloseCashSessionRequest;
use App\Modules\Cash\Http\Requests\OpenCashSessionRequest;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isSuperAdministrator = $user->isSuperAdministrator();
        $allowedBranchIds = $user->accessibleBranchIds() ?: [-1];
        $requestedBranchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        $sessions = CashRegisterSession::query()
            ->with(['branch:id,name', 'opener:id,name', 'closer:id,name'])
            ->when(! $isSuperAdministrator, fn ($query) => $query
                ->where('opened_by', $user->id)
                ->whereIn('branch_id', $allowedBranchIds))
            ->when(
                $requestedBranchId && ($isSuperAdministrator || in_array($requestedBranchId, $allowedBranchIds, true)),
                fn ($query) => $query->where('branch_id', $requestedBranchId)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('opened_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $openSessions = CashRegisterSession::query()
            ->with(['branch:id,name', 'opener:id,name'])
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->when(! $isSuperAdministrator, fn ($query) => $query
                ->where('opened_by', $user->id)
                ->whereIn('branch_id', $allowedBranchIds))
            ->latest('opened_at')
            ->get()
            ->map(function (CashRegisterSession $session) {
                $currentAt = now();
                $cashIncome = $this->cashIncome($session, $currentAt);
                $cashExpense = $this->cashExpense($session, $currentAt);
                $bankIncome = $this->bankIncome($session, $currentAt);
                $bankExpense = $this->bankExpense($session, $currentAt);

                $session->setAttribute('current_cash_income_amount', round($cashIncome, 2));
                $session->setAttribute('current_cash_expense_amount', round($cashExpense, 2));
                $session->setAttribute('current_bank_income_amount', round($bankIncome, 2));
                $session->setAttribute('current_bank_expense_amount', round($bankExpense, 2));
                $session->setAttribute('current_expected_cash_amount', round((float) $session->opening_amount + $cashIncome - $cashExpense, 2));

                return $session;
            });

        return Inertia::render('Cash/Index', [
            'sessions' => $sessions,
            'openSessions' => $openSessions,
            'branches' => UiCatalogCache::activeBranchesForUser($user),
            'filters' => $request->only(['branch_id', 'status', 'per_page']),
        ]);
    }

    public function open(OpenCashSessionRequest $request): RedirectResponse
    {
        abort_if(
            ! $request->user()->isSuperAdministrator()
            && ! in_array($request->integer('branch_id'), $request->user()->accessibleBranchIds(), true),
            403
        );

        CashRegisterSession::query()->create([
            ...$request->validated(),
            'opened_by' => $request->user()->id,
            'opened_at' => now(),
            'expected_cash_amount' => $request->float('opening_amount'),
            'status' => CashRegisterSession::STATUS_OPEN,
        ]);

        return redirect()->route('cash.index')->with('success', 'Caja abierta correctamente.');
    }

    public function close(CloseCashSessionRequest $request, CashRegisterSession $cashSession): RedirectResponse
    {
        DB::transaction(function () use ($request, $cashSession) {
            $cashSession = CashRegisterSession::query()
                ->whereKey($cashSession->id)
                ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query
                    ->where('opened_by', $request->user()->id)
                    ->whereIn('branch_id', $request->user()->accessibleBranchIds() ?: [-1]))
                ->lockForUpdate()
                ->firstOrFail();

            $closedAt = now();
            $cashIncome = $this->cashIncome($cashSession, $closedAt);
            $cashExpense = $this->cashExpense($cashSession, $closedAt);
            $expected = round((float) $cashSession->opening_amount + $cashIncome - $cashExpense, 2);
            $cashCount = $this->normalizedCashCount($request->validated('cash_count'));
            $counted = $this->countedCashAmount($cashCount);

            $cashSession->update([
                'closed_by' => $request->user()->id,
                'closed_at' => $closedAt,
                'cash_income_amount' => $cashIncome,
                'cash_expense_amount' => $cashExpense,
                'expected_cash_amount' => $expected,
                'counted_cash_amount' => $counted,
                'cash_count_breakdown' => $cashCount,
                'difference_amount' => round($counted - $expected, 2),
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closing_notes' => $request->string('closing_notes')->toString() ?: null,
            ]);
        });

        return redirect()->route('cash.index')->with('success', 'Caja cerrada correctamente.');
    }

    private function cashIncome(CashRegisterSession $session, $closedAt): float
    {
        return (float) SalePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $closedAt])
            ->sum('amount_bob');
    }

    private function cashExpense(CashRegisterSession $session, $closedAt): float
    {
        $expenses = (float) Expense::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->where('status', Expense::STATUS_REGISTERED)
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('spent_at', [$session->opened_at, $closedAt])
            ->sum('amount');

        $purchasePayments = (float) PurchasePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $closedAt])
            ->sum('amount');

        return $expenses + $purchasePayments;
    }

    private function bankIncome(CashRegisterSession $session, $closedAt): float
    {
        return (float) SalePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', '!=', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $closedAt])
            ->sum('amount_bob');
    }

    private function bankExpense(CashRegisterSession $session, $closedAt): float
    {
        $purchasePayments = (float) PurchasePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', '!=', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $closedAt])
            ->sum('amount');

        $expenses = (float) Expense::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->where('status', Expense::STATUS_REGISTERED)
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', '!=', 'cash'))
            ->whereBetween('spent_at', [$session->opened_at, $closedAt])
            ->sum('amount');

        return $purchasePayments + $expenses;
    }

    private function normalizedCashCount(array $cashCount): array
    {
        return collect(CashRegisterSession::CASH_DENOMINATIONS)
            ->mapWithKeys(fn (int $cents, string $key) => [$key => (int) ($cashCount[$key] ?? 0)])
            ->all();
    }

    private function countedCashAmount(array $cashCount): float
    {
        $totalCents = collect(CashRegisterSession::CASH_DENOMINATIONS)
            ->reduce(fn (int $total, int $cents, string $key) => $total + ($cents * (int) ($cashCount[$key] ?? 0)), 0);

        return round($totalCents / 100, 2);
    }
}
