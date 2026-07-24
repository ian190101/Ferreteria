<?php

namespace App\Modules\Finance\Services;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Support\UiCatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class FinancialLedgerService
{
    public function cashSessionSummary(CashRegisterSession $session, mixed $endAt = null, bool $attachBankTransactions = false): array
    {
        $endAt = $this->date($endAt);

        if ($attachBankTransactions) {
            $this->attachBankTransactionsToCashSession($session, $endAt);
        }

        $cashIncome = $this->cashIncome($session, $endAt);
        $cashExpense = $this->cashExpense($session, $endAt);
        $bankIncome = $this->bankIncome($session, $endAt);
        $bankExpense = $this->bankExpense($session, $endAt);
        $expectedCash = round((float) $session->opening_amount + $cashIncome - $cashExpense, 2);

        return [
            'cash_income' => round($cashIncome, 2),
            'cash_expense' => round($cashExpense, 2),
            'bank_income' => round($bankIncome, 2),
            'bank_expense' => round($bankExpense, 2),
            'bank_net' => round($bankIncome - $bankExpense, 2),
            'expected_cash' => $expectedCash,
        ];
    }

    public function normalizedCashCount(array $cashCount): array
    {
        return collect(CashRegisterSession::CASH_DENOMINATIONS)
            ->mapWithKeys(fn (int $cents, string $key) => [$key => (int) ($cashCount[$key] ?? 0)])
            ->all();
    }

    public function countedCashAmount(array $cashCount): float
    {
        $totalCents = collect(CashRegisterSession::CASH_DENOMINATIONS)
            ->reduce(fn (int $total, int $cents, string $key) => $total + ($cents * (int) ($cashCount[$key] ?? 0)), 0);

        return round($totalCents / 100, 2);
    }

    public function voidBankTransaction(BankTransaction $transaction, string $reason): void
    {
        if ($transaction->status === BankTransaction::STATUS_VOID) {
            return;
        }

        $account = BankAccount::query()
            ->whereKey($transaction->bank_account_id)
            ->lockForUpdate()
            ->firstOrFail();

        $account->decrement('current_balance', $this->signedBankAmount($transaction->type, (float) $transaction->amount));

        $transaction->update([
            'status' => BankTransaction::STATUS_VOID,
            'voided_at' => now(),
            'void_reason' => mb_substr($reason, 0, 255),
        ]);

        $this->bumpFinancialCaches();
    }

    public function bumpFinancialCaches(): void
    {
        Cache::forever('banks:summary_version', ((int) Cache::get('banks:summary_version', 1)) + 1);
        Cache::forever('expenses:summary_version', ((int) Cache::get('expenses:summary_version', 1)) + 1);
        Cache::forever('cash:summary_version', ((int) Cache::get('cash:summary_version', 1)) + 1);
        UiCatalogCache::forgetFinancialCatalogs();
    }

    public function signedBankAmount(string $type, float $amount): float
    {
        return $type === BankTransaction::TYPE_WITHDRAWAL ? -$amount : $amount;
    }

    private function cashIncome(CashRegisterSession $session, Carbon $endAt): float
    {
        return (float) SalePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $endAt])
            ->sum('amount_bob');
    }

    private function cashExpense(CashRegisterSession $session, Carbon $endAt): float
    {
        $expenses = (float) Expense::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->where('status', Expense::STATUS_REGISTERED)
            ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('spent_at', [$session->opened_at, $endAt])
            ->sum('amount');

        $purchasePayments = (float) PurchasePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', 'cash'))
            ->whereBetween('paid_at', [$session->opened_at, $endAt])
            ->sum('amount');

        return $expenses + $purchasePayments;
    }

    private function bankIncome(CashRegisterSession $session, Carbon $endAt): float
    {
        return (float) $this->bankTransactionsForCashSession($session, $endAt)
            ->where('type', BankTransaction::TYPE_DEPOSIT)
            ->sum('amount');
    }

    private function bankExpense(CashRegisterSession $session, Carbon $endAt): float
    {
        return (float) $this->bankTransactionsForCashSession($session, $endAt)
            ->where('type', BankTransaction::TYPE_WITHDRAWAL)
            ->sum('amount');
    }

    private function attachBankTransactionsToCashSession(CashRegisterSession $session, Carbon $endAt): void
    {
        $this->bankTransactionsInCashPeriod($session, $endAt)
            ->whereNull('cash_register_session_id')
            ->get()
            ->each(fn (BankTransaction $transaction) => $transaction->update([
                'cash_register_session_id' => $session->id,
                'reconciled_at' => $transaction->reconciled_at ?: now(),
            ]));

        $this->bankTransactionsInCashPeriod($session, $endAt)
            ->where('cash_register_session_id', $session->id)
            ->whereNull('reconciled_at')
            ->get()
            ->each(fn (BankTransaction $transaction) => $transaction->update(['reconciled_at' => now()]));
    }

    private function bankTransactionsForCashSession(CashRegisterSession $session, Carbon $endAt): Builder
    {
        return BankTransaction::query()
            ->where('status', BankTransaction::STATUS_REGISTERED)
            ->where(function ($query) use ($session, $endAt) {
                $query->where('cash_register_session_id', $session->id)
                    ->orWhere(fn ($fallback) => $fallback
                        ->whereNull('cash_register_session_id')
                        ->where('branch_id', $session->branch_id)
                        ->where('user_id', $session->opened_by)
                        ->whereBetween('transacted_at', [$session->opened_at, $endAt]));
            });
    }

    private function bankTransactionsInCashPeriod(CashRegisterSession $session, Carbon $endAt): Builder
    {
        return BankTransaction::query()
            ->where('status', BankTransaction::STATUS_REGISTERED)
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereBetween('transacted_at', [$session->opened_at, $endAt])
            ->whereIn('type', [BankTransaction::TYPE_DEPOSIT, BankTransaction::TYPE_WITHDRAWAL]);
    }

    private function date(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse($value ?: now());
    }
}
