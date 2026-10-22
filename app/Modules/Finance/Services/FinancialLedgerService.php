<?php

namespace App\Modules\Finance\Services;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Finance\Support\Decimal;
use App\Modules\HumanResources\Models\SalaryPayment;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Support\UiCatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class FinancialLedgerService
{
    /**
     * Centraliza la creacion del cobro para que POS, pagos manuales y futuros flujos usen la misma aritmetica.
     */
    public function registerSalePayment(Sale $sale, int $userId, int $paymentMethodId, float $amount, ?string $reference = null, ?string $notes = null): SalePayment
    {
        return $this->registerSalePaymentExact($sale, $userId, $paymentMethodId, number_format($amount, 2, '.', ''), $reference, $notes);
    }

    public function registerSalePaymentExact(Sale $sale, int $userId, int $paymentMethodId, string $amount, ?string $reference = null, ?string $notes = null): SalePayment
    {
        if ($sale->getConnection()->transactionLevel() < 1) {
            throw new \LogicException('El cobro requiere una transacción del flujo completo');
        }

        $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        $sale->setRawAttributes($locked->getAttributes(), true);
        if ($sale->document_type !== 'sale_note' || ! in_array($sale->status, ['issued', 'partial_paid'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sale_id' => 'La venta ya no admite cobros.',
            ]);
        }
        $amount = Decimal::round($amount, 2);
        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, (string) $sale->balance_due, 2) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'El cobro debe ser positivo y no superar el saldo pendiente.',
            ]);
        }

        $cashSession = CashRegisterSession::query()
            ->where('branch_id', $sale->branch_id)
            ->where('opened_by', $userId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->orderBy('id')->lockForUpdate()->first();

        $payment = SalePayment::query()->create([
            'sale_id' => $sale->id,
            'branch_id' => $sale->branch_id,
            'user_id' => $userId,
            'payment_method_id' => $paymentMethodId,
            'paid_at' => now(),
            'amount' => $amount,
            'exchange_rate_to_bob' => $sale->exchange_rate_to_bob,
            'amount_bob' => Decimal::round(bcmul($amount, (string) $sale->exchange_rate_to_bob, 8), 2),
            'reference' => $reference ?: null,
            'notes' => $notes ?: null,
            'cash_register_session_id' => $cashSession?->id,
            'cash_context_recorded' => true,
        ]);

        $this->applySalePaymentBalanceExact($sale, $amount);

        return $payment;
    }

    public function applySalePaymentBalance(Sale $sale, float $amount): void
    {
        $this->applySalePaymentBalanceExact($sale, number_format($amount, 2, '.', ''));
    }

    public function applySalePaymentBalanceExact(Sale $sale, string $amount): void
    {
        $newBalance = bcsub((string) $sale->balance_due, Decimal::round($amount, 2), 2);
        $total = (string) $sale->total;

        $sale->update([
            'balance_due' => $newBalance,
            'status' => bccomp($newBalance, '0', 2) <= 0 ? 'paid' : (bccomp($newBalance, $total, 2) < 0 ? 'partial_paid' : 'issued'),
        ]);
    }

    public function voidSalePayment(SalePayment $payment, Sale $sale, string $voidReason): void
    {
        $notes = trim(implode("\n", array_filter([
            $payment->notes,
            $voidReason,
        ])));

        $payment->update(['notes' => $notes]);
        $payment->delete();

        $activeCredits = (string) CreditNote::query()
            ->where('sale_id', $sale->id)
            ->sum('amount');
        $totalToCollect = bccomp((string) $sale->total, '0', 2) > 0 ? (string) $sale->total : '0.00';
        $newBalance = bcadd((string) $sale->balance_due, (string) $payment->amount, 2);
        $newBalance = bccomp($newBalance, '0', 2) > 0 ? $newBalance : '0.00';
        $maximum = bcsub($totalToCollect, $activeCredits, 2);
        $maximum = bccomp($maximum, '0', 2) > 0 ? $maximum : '0.00';
        $newBalance = bccomp($newBalance, $maximum, 2) < 0 ? $newBalance : $maximum;

        $sale->update([
            'balance_due' => $newBalance,
            'status' => bccomp($newBalance, '0', 2) <= 0 ? 'paid' : (bccomp($newBalance, $totalToCollect, 2) < 0 ? 'partial_paid' : 'issued'),
        ]);
    }

    public function registerPurchasePayment(Purchase $purchase, int $userId, int $paymentMethodId, float $amount, ?string $reference = null, ?string $notes = null): PurchasePayment
    {
        return $this->registerPurchasePaymentExact($purchase, $userId, $paymentMethodId, number_format($amount, 2, '.', ''), $reference, $notes);
    }

    public function registerPurchasePaymentExact(Purchase $purchase, int $userId, int $paymentMethodId, string $amount, ?string $reference = null, ?string $notes = null): PurchasePayment
    {
        if ($purchase->getConnection()->transactionLevel() < 1) {
            throw new \LogicException('El pago requiere una transacción del flujo completo');
        }
        $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
        $purchase->setRawAttributes($locked->getAttributes(), true);
        $amount = Decimal::round($amount, 2);
        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, (string) $purchase->balance_due, 2) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => 'El pago debe ser positivo y no superar el saldo pendiente.',
            ]);
        }

        $payment = PurchasePayment::query()->create([
            'purchase_id' => $purchase->id,
            'branch_id' => $purchase->branch_id,
            'user_id' => $userId,
            'payment_method_id' => $paymentMethodId,
            'paid_at' => now(),
            'amount' => $amount,
            'reference' => $reference ?: null,
            'notes' => $notes ?: null,
        ]);

        $this->applyPurchasePaymentBalanceExact($purchase, $amount);

        return $payment;
    }

    public function registerExpense(array $data, int $userId): Expense
    {
        return Expense::query()->create([
            ...collect($data)->only([
                'branch_id',
                'expense_category_id',
                'payment_method_id',
                'description',
                'amount',
                'reference',
                'notes',
            ])->all(),
            'user_id' => $userId,
            'spent_at' => now(),
            'status' => Expense::STATUS_REGISTERED,
        ]);
    }

    public function voidExpense(Expense $expense, string $voidReason): void
    {
        $expense->update([
            'status' => Expense::STATUS_VOID,
            'notes' => trim(implode("\n", array_filter([$expense->notes, $voidReason]))),
        ]);

        if ($expense->salaryPayment && $expense->salaryPayment->status !== SalaryPayment::STATUS_VOID) {
            $this->voidSalaryPaymentRecord($expense->salaryPayment, $voidReason, voidLinkedExpense: false);
        }
    }

    public function registerSalaryPayment(Worker $worker, array $data, int $userId, ?Expense $expense = null): SalaryPayment
    {
        return SalaryPayment::query()->create([
            'worker_id' => $worker->id,
            'branch_id' => $data['branch_id'] ?? $worker->branch_id,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'expense_id' => $expense?->id,
            'user_id' => $userId,
            'period_from' => $data['period_from'] ?? null,
            'period_to' => $data['period_to'] ?? null,
            'paid_at' => $expense?->spent_at ?? now(),
            'amount' => $data['amount'],
            'reference' => $data['reference'] ?? null,
            'status' => SalaryPayment::STATUS_PAID,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function voidSalaryPaymentRecord(SalaryPayment $payment, string $voidReason, bool $voidLinkedExpense = true): void
    {
        if ($payment->status === SalaryPayment::STATUS_VOID) {
            return;
        }

        if ($voidLinkedExpense && $payment->expense && $payment->expense->status !== Expense::STATUS_VOID) {
            $payment->expense->update([
                'status' => Expense::STATUS_VOID,
                'notes' => trim(implode("\n", array_filter([$payment->expense->notes, $voidReason]))),
            ]);
        }

        $payment->update([
            'status' => SalaryPayment::STATUS_VOID,
            'notes' => trim(implode("\n", array_filter([$payment->notes, $voidReason]))),
        ]);
    }

    public function applyPurchasePaymentBalance(Purchase $purchase, float $amount): void
    {
        $this->applyPurchasePaymentBalanceExact($purchase, number_format($amount, 2, '.', ''));
    }

    public function applyPurchasePaymentBalanceExact(Purchase $purchase, string $amount): void
    {
        $paidAmount = bcadd((string) $purchase->paid_amount, Decimal::round($amount, 2), 2);
        $newBalance = bcsub((string) $purchase->total_amount, $paidAmount, 2);

        $purchase->update([
            'paid_amount' => $paidAmount,
            'balance_due' => $newBalance,
            'payment_status' => bccomp($newBalance, '0', 2) <= 0 ? 'paid' : (bccomp($newBalance, (string) $purchase->total_amount, 2) < 0 ? 'partial_paid' : 'unpaid'),
        ]);
    }

    public function voidPurchasePayment(PurchasePayment $payment, Purchase $purchase, string $voidReason): void
    {
        $notes = trim(implode("\n", array_filter([
            $payment->notes,
            $voidReason,
        ])));

        $payment->update(['notes' => $notes]);
        $payment->delete();

        $paidAmount = bcsub((string) $purchase->paid_amount, (string) $payment->amount, 2);
        $paidAmount = bccomp($paidAmount, '0', 2) > 0 ? $paidAmount : '0.00';
        $newBalance = bcsub((string) $purchase->total_amount, $paidAmount, 2);
        $newBalance = bccomp($newBalance, '0', 2) > 0 ? $newBalance : '0.00';

        $purchase->update([
            'paid_amount' => $paidAmount,
            'balance_due' => $newBalance,
            'payment_status' => bccomp($newBalance, '0', 2) <= 0 ? 'paid' : (bccomp($newBalance, (string) $purchase->total_amount, 2) < 0 ? 'partial_paid' : 'unpaid'),
        ]);
    }

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
        $transaction->getConnection()->transaction(function () use ($transaction, $reason): void {
            $locked = BankTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $transaction->setRawAttributes($locked->getAttributes(), true);
            if ($transaction->status === BankTransaction::STATUS_VOID) {
                return;
            }

            $account = BankAccount::query()
                ->whereKey($transaction->bank_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $account->decrement('current_balance', $transaction->type === BankTransaction::TYPE_WITHDRAWAL
                ? bcsub('0', (string) $transaction->amount, 2)
                : (string) $transaction->amount);

            $transaction->update([
                'status' => BankTransaction::STATUS_VOID,
                'voided_at' => now(),
                'void_reason' => mb_substr($reason, 0, 255),
            ]);

            $this->bumpFinancialCaches();
        });
    }

    public function bumpFinancialCaches(): void
    {
        app(\App\Support\OperationalCacheOutbox::class)->schedule('financial');
    }

    public function signedBankAmount(string $type, float $amount): float
    {
        return $type === BankTransaction::TYPE_WITHDRAWAL ? -$amount : $amount;
    }

    public function saleStatusForBalance(float $balance, float $totalToCollect): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        return $balance < $totalToCollect ? 'partial_paid' : 'issued';
    }

    public function purchaseStatusForBalance(float $balance, float $total): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        return $balance < $total ? 'partial_paid' : 'unpaid';
    }

    private function cashIncome(CashRegisterSession $session, Carbon $endAt): float
    {
        return (float) SalePayment::query()
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereHas('method', fn ($query) => $query->where('code', 'cash'))
            ->where(function (Builder $query) use ($session, $endAt) {
                $query->where('cash_register_session_id', $session->id)
                    ->orWhere(fn (Builder $historical) => $historical
                        ->where('cash_context_recorded', false)
                        ->whereBetween('paid_at', [$session->opened_at, $endAt]));
            })
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
                        ->whereIn('id', $this->bankTransactionsInCashPeriod($session, $endAt)->select('bank_transactions.id')));
            });
    }

    private function bankTransactionsInCashPeriod(CashRegisterSession $session, Carbon $endAt): Builder
    {
        return BankTransaction::query()
            ->where('status', BankTransaction::STATUS_REGISTERED)
            ->where('branch_id', $session->branch_id)
            ->where('user_id', $session->opened_by)
            ->whereBetween('transacted_at', [$session->opened_at, $endAt])
            // Un cobro explícitamente registrado fuera de caja tampoco se concilia con una apertura posterior.
            ->whereNotExists(fn ($payment) => $payment->selectRaw('1')
                ->from('sale_payments')
                ->whereColumn('sale_payments.id', 'bank_transactions.source_id')
                ->where('bank_transactions.source_type', SalePayment::class)
                ->where('sale_payments.cash_context_recorded', true)
                ->where(fn ($context) => $context
                    ->whereNull('sale_payments.cash_register_session_id')
                    ->orWhere('sale_payments.cash_register_session_id', '!=', $session->id)))
            ->whereIn('type', [BankTransaction::TYPE_DEPOSIT, BankTransaction::TYPE_WITHDRAWAL]);
    }

    private function date(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse($value ?: now());
    }
}
