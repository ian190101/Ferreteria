<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Modules\Sales\Services\SalePaymentService;
use App\Modules\Sales\Services\SalesWorkflowPolicy;

class ProcessSaleNoteIssued
{
    public function __construct(
        private readonly SaleInventoryService $inventory,
        private readonly SalePaymentService $payments,
        private readonly SalesWorkflowPolicy $workflow,
    ) {
    }

    public function handle(SaleNoteIssued $event): void
    {
        $sale = Sale::query()
            ->with('items.product:id,inventory_tracking_mode')
            ->lockForUpdate()
            ->findOrFail($event->saleId);

        if ($sale->document_type !== 'sale_note') {
            return;
        }

        if ($this->workflow->shouldDiscountInventoryOnSaleNote()) {
            $this->inventory->decrementForSale($sale, $event->userId);
        }

        if ($event->sourceQuotationId) {
            $quotation = Sale::query()
                ->lockForUpdate()
                ->findOrFail($event->sourceQuotationId);

            $this->inventory->consumeReservationsForQuotation($quotation, $sale);
            $quotation->update([
                'status' => 'converted',
                'internal_notes' => trim(implode("\n", array_filter([
                    $quotation->internal_notes,
                    'Convertida a nota de venta '.$sale->receipt_number,
                ]))),
            ]);
        }

        if ($event->posPayment) {
            $this->payments->registerPosPayment(
                sale: $sale,
                userId: $event->userId,
                paymentMethodId: (int) $event->posPayment['payment_method_id'],
                amount: (float) $event->posPayment['amount'],
                reference: $event->posPayment['reference'] ?? null,
            );
        }
    }
}
