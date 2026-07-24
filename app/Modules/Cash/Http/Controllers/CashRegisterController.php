<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Http\Requests\CloseCashSessionRequest;
use App\Modules\Cash\Http\Requests\OpenCashSessionRequest;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function index(Request $request, FinancialLedgerService $ledger): Response
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
            ->map(function (CashRegisterSession $session) use ($ledger) {
                $summary = $ledger->cashSessionSummary($session);

                $session->setAttribute('current_cash_income_amount', $summary['cash_income']);
                $session->setAttribute('current_cash_expense_amount', $summary['cash_expense']);
                $session->setAttribute('current_bank_income_amount', $summary['bank_income']);
                $session->setAttribute('current_bank_expense_amount', $summary['bank_expense']);
                $session->setAttribute('current_bank_net_amount', $summary['bank_net']);
                $session->setAttribute('current_expected_cash_amount', $summary['expected_cash']);

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

    public function close(CloseCashSessionRequest $request, CashRegisterSession $cashSession, FinancialLedgerService $ledger): RedirectResponse
    {
        DB::transaction(function () use ($request, $cashSession, $ledger) {
            $cashSession = CashRegisterSession::query()
                ->whereKey($cashSession->id)
                ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query
                    ->where('opened_by', $request->user()->id)
                    ->whereIn('branch_id', $request->user()->accessibleBranchIds() ?: [-1]))
                ->lockForUpdate()
                ->firstOrFail();

            $closedAt = now();
            $summary = $ledger->cashSessionSummary($cashSession, $closedAt, attachBankTransactions: true);
            $cashCount = $ledger->normalizedCashCount($request->validated('cash_count'));
            $counted = $ledger->countedCashAmount($cashCount);

            $cashSession->update([
                'closed_by' => $request->user()->id,
                'closed_at' => $closedAt,
                'cash_income_amount' => $summary['cash_income'],
                'cash_expense_amount' => $summary['cash_expense'],
                'bank_income_amount' => $summary['bank_income'],
                'bank_expense_amount' => $summary['bank_expense'],
                'bank_net_amount' => $summary['bank_net'],
                'expected_cash_amount' => $summary['expected_cash'],
                'counted_cash_amount' => $counted,
                'cash_count_breakdown' => $cashCount,
                'difference_amount' => round($counted - $summary['expected_cash'], 2),
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closing_notes' => $request->string('closing_notes')->toString() ?: null,
            ]);

            $ledger->bumpFinancialCaches();
        });

        return redirect()->route('cash.index')->with('success', 'Caja cerrada correctamente.');
    }

}
