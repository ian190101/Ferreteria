<?php

namespace App\Modules\Sales\Services;

use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Validation\ValidationException;

class SalePaymentService
{
    public function __construct(
        private readonly BankReconciliationService $banks,
        private readonly FinancialLedgerService $ledger,
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

        $payment = $this->ledger->registerSalePayment(
            sale: $sale,
            userId: $userId,
            paymentMethodId: $paymentMethodId,
            amount: $amount,
            reference: $reference,
            notes: 'Cobro generado desde POS rapido.',
        );

        $this->banks->recordSalePayment($payment);

        if ($this->workflow->shouldDiscountInventoryOnPayment()) {
            $sale->loadMissing('items.product:id,inventory_tracking_mode');
            $this->inventory->decrementForSale($sale, $userId);
        }
    }
}
