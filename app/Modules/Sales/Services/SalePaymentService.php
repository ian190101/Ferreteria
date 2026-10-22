<?php

namespace App\Modules\Sales\Services;

use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\Finance\Support\Decimal;
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
        $this->registerPosPaymentExact($sale, $userId, $paymentMethodId, number_format($amount, 2, '.', ''), $reference);
    }

    public function registerPosPaymentExact(Sale $sale, int $userId, int $paymentMethodId, string $amount, ?string $reference = null): void
    {
        $amount = Decimal::round($amount, 2);

        if (bccomp($amount, '0', 2) <= 0) {
            return;
        }

        if (bccomp($amount, (string) $sale->balance_due, 2) > 0) {
            throw ValidationException::withMessages([
                'pos_payment_amount' => 'El cobro POS no puede ser mayor al total pendiente de la nota de venta.',
            ]);
        }

        $payment = $this->ledger->registerSalePaymentExact(
            sale: $sale,
            userId: $userId,
            paymentMethodId: $paymentMethodId,
            amount: $amount,
            reference: $reference,
            notes: 'Cobro generado desde POS rapido.',
        );

        $this->banks->recordSalePayment($payment);

        if ($this->workflow->shouldDiscountInventoryOnPayment()) {
            $sale->load('items.product:id,inventory_tracking_mode,item_type,is_inventory_item,product_category_id');
            $this->inventory->decrementForSale($sale, $userId);
        }
    }
}
