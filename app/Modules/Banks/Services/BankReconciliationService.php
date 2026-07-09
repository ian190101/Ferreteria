<?php

namespace App\Modules\Banks\Services;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Support\UiCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    private const BANK_METHOD_CODES = [
        'qr',
        'transfer',
        'transferencia',
        'bank_transfer',
        'bank',
        'deposit',
        'deposito',
        'cheque',
        'check',
    ];

    public function recordSalePayment(SalePayment $payment): void
    {
        $payment->loadMissing(['method:id,name,code', 'sale:id,receipt_number']);

        if (! $this->isBankMethod($payment->method)) {
            return;
        }

        $this->record(
            source: $payment,
            type: BankTransaction::TYPE_DEPOSIT,
            branchId: (int) $payment->branch_id,
            userId: (int) $payment->user_id,
            amount: (float) $payment->amount_bob,
            transactedAt: $payment->paid_at,
            reference: $payment->reference,
            description: 'Ingreso por '.$payment->method->name.' - Nota '.($payment->sale?->receipt_number ?? $payment->sale_id),
        );
    }

    public function recordPurchasePayment(PurchasePayment $payment): void
    {
        $payment->loadMissing(['method:id,name,code', 'purchase:id,document_number']);

        if (! $this->isBankMethod($payment->method)) {
            return;
        }

        $this->record(
            source: $payment,
            type: BankTransaction::TYPE_WITHDRAWAL,
            branchId: (int) $payment->branch_id,
            userId: (int) $payment->user_id,
            amount: (float) $payment->amount,
            transactedAt: $payment->paid_at,
            reference: $payment->reference,
            description: 'Egreso por '.$payment->method->name.' - Compra '.($payment->purchase?->document_number ?? $payment->purchase_id),
        );
    }

    public function recordExpense(Expense $expense): void
    {
        $expense->loadMissing(['paymentMethod:id,name,code']);

        if (! $this->isBankMethod($expense->paymentMethod)) {
            return;
        }

        $this->record(
            source: $expense,
            type: BankTransaction::TYPE_WITHDRAWAL,
            branchId: (int) $expense->branch_id,
            userId: (int) $expense->user_id,
            amount: (float) $expense->amount,
            transactedAt: $expense->spent_at,
            reference: $expense->reference,
            description: 'Egreso por '.$expense->paymentMethod->name.' - '.$expense->description,
        );
    }

    public function voidForSource(Model $source, string $reason): void
    {
        $transaction = BankTransaction::query()
            ->where('source_type', get_class($source))
            ->where('source_id', $source->getKey())
            ->where('status', BankTransaction::STATUS_REGISTERED)
            ->lockForUpdate()
            ->first();

        if (! $transaction) {
            return;
        }

        $account = BankAccount::query()
            ->whereKey($transaction->bank_account_id)
            ->lockForUpdate()
            ->firstOrFail();

        $account->decrement('current_balance', $this->signedAmount($transaction->type, (float) $transaction->amount));

        $transaction->update([
            'status' => BankTransaction::STATUS_VOID,
            'voided_at' => now(),
            'void_reason' => $this->limit($reason, 255),
        ]);

        $this->bumpCaches();
    }

    private function record(
        Model $source,
        string $type,
        int $branchId,
        int $userId,
        float $amount,
        mixed $transactedAt,
        ?string $reference,
        string $description,
    ): void {
        if ($amount <= 0 || $this->alreadyRecorded($source)) {
            return;
        }

        $account = $this->activeAccountForBranch($branchId);
        $amount = round($amount, 2);

        BankTransaction::query()->create([
            'bank_account_id' => $account->id,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'cash_register_session_id' => $this->openCashSessionId($branchId, $userId, $transactedAt),
            'type' => $type,
            'transacted_at' => $transactedAt ?: now(),
            'amount' => $amount,
            'reference' => $this->limit($reference, 120),
            'description' => $this->limit($description, 255),
            'status' => BankTransaction::STATUS_REGISTERED,
            'reconciled_at' => now(),
            'source_type' => get_class($source),
            'source_id' => $source->getKey(),
        ]);

        $account->increment('current_balance', $this->signedAmount($type, $amount));
        $this->bumpCaches();
    }

    private function isBankMethod(?PaymentMethod $method): bool
    {
        if (! $method) {
            return false;
        }

        return in_array(strtolower((string) $method->code), self::BANK_METHOD_CODES, true);
    }

    private function activeAccountForBranch(int $branchId): BankAccount
    {
        $account = BankAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('currency_code', 'BOB')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        // Permite operar con una cuenta bancaria unica/demo cuando varias sucursales comparten el mismo QR.
        $account ??= BankAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $account ??= BankAccount::query()
            ->where('is_active', true)
            ->where('currency_code', 'BOB')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $account ??= BankAccount::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'payment_method_id' => 'No existe una cuenta bancaria activa para conciliar este pago QR/Banco.',
            ]);
        }

        return $account;
    }

    private function openCashSessionId(int $branchId, int $userId, mixed $transactedAt): ?int
    {
        return CashRegisterSession::query()
            ->where('branch_id', $branchId)
            ->where('opened_by', $userId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->where('opened_at', '<=', $transactedAt ?: now())
            ->latest('opened_at')
            ->value('id');
    }

    private function alreadyRecorded(Model $source): bool
    {
        return BankTransaction::query()
            ->where('source_type', get_class($source))
            ->where('source_id', $source->getKey())
            ->exists();
    }

    private function signedAmount(string $type, float $amount): float
    {
        return $type === BankTransaction::TYPE_WITHDRAWAL ? -$amount : $amount;
    }

    private function limit(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function bumpCaches(): void
    {
        Cache::forever('banks:summary_version', ((int) Cache::get('banks:summary_version', 1)) + 1);
        UiCatalogCache::forgetFinancialCatalogs();
    }
}
