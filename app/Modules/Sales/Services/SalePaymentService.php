<?php

namespace App\Modules\Sales\Services;

use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use Illuminate\Validation\ValidationException;

class SalePaymentService
{
    public function __construct(
        private readonly BankReconciliationService $banks,
        private readonly SaleInventoryService $inventory,
        private readonly SalesWorkflowPolicy $workflow,
    ) {
    }

    public function registerPosPayment(Sale $sale, int $userId, int $paymentMethodId, float $amount, ?string $reference = null): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        if ($amount > (float) $sale->balance_due) {
            throw ValidationException::withMessages([
                'pos_payment_amount' => 'El cobro POS no puede ser mayor al total pendiente de la nota de venta.',
            ]);
        }

        $payment = SalePayment::query()->create([
            'sale_id' => $sale->id,
            'branch_id' => $sale->branch_id,
            'user_id' => $userId,
            'payment_method_id' => $paymentMethodId,
            'paid_at' => now(),
            'amount' => $amount,
            'exchange_rate_to_bob' => $sale->exchange_rate_to_bob,
            'amount_bob' => round($amount * (float) $sale->exchange_rate_to_bob, 2),
            'reference' => $reference ?: null,
            'notes' => 'Cobro generado desde POS rapido.',
        ]);

        $newBalance = max(round((float) $sale->balance_due - $amount, 2), 0);
        $sale->update([
            'balance_due' => $newBalance,
            'status' => $newBalance <= 0 ? 'paid' : 'partial_paid',
        ]);

        $this->banks->recordSalePayment($payment);

        if ($this->workflow->shouldDiscountInventoryOnPayment()) {
            $sale->loadMissing('items.product:id,inventory_tracking_mode');
            $this->inventory->decrementForSale($sale, $userId);
        }
    }
}
