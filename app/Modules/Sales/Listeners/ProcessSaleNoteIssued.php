<?php

namespace App\Modules\Sales\Listeners;

use App\Modules\Sales\Events\SaleNoteIssued;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Modules\Sales\Services\SalePaymentService;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
use App\Modules\Inventory\Services\LotTrackingPolicy;
use App\Modules\Inventory\Services\InventoryReservationService;

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
        if (\Illuminate\Support\Facades\DB::transactionLevel() < 1) {
            throw new \LogicException('La emisión requiere la transacción de venta.');
        }

        $sale = Sale::query()
            ->with('items.product:id,inventory_tracking_mode,item_type,is_inventory_item,requires_lot,product_category_id')
            ->lockForUpdate()
            ->findOrFail($event->saleId);

        if ($sale->document_type !== 'sale_note') {
            return;
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

        if (! $this->workflow->shouldDiscountInventoryOnSaleNote()) {
            $lotPolicy = app(LotTrackingPolicy::class);
            $configuration = $lotPolicy->persistedConfiguration();
            $plan = $sale->items->filter(fn ($item) => $lotPolicy->required($item->product, $configuration))
                ->map(fn ($item) => [
                    'product_id' => (int) $item->product_id,
                    'branch_id' => (int) $sale->branch_id,
                    'lot_id' => (int) $item->product_coil_id,
                ])->all();
            if ($plan !== []) {
                app(\App\Modules\Inventory\Services\LotInventoryService::class)->lockBatch($plan);
            }
            foreach ($sale->items as $item) {
                if (! $lotPolicy->required($item->product, $configuration)) {
                    continue;
                }
                $reservations = app(InventoryReservationService::class);
                if (! $reservations->commitmentFor($item)) {
                    $reservations->reserve((int) $item->product_coil_id, (int) $sale->id,
                        (int) $item->id, (string) $item->meters, $event->userId, (int) $item->id);
                }
            }
        }

        if ($this->workflow->shouldDiscountInventoryOnSaleNote()) {
            $this->inventory->decrementForSale($sale, $event->userId);
        }

        if ($event->posPayment) {
            $this->payments->registerPosPaymentExact(
                sale: $sale,
                userId: $event->userId,
                paymentMethodId: (int) $event->posPayment['payment_method_id'],
                amount: (string) $event->posPayment['amount'],
                reference: $event->posPayment['reference'] ?? null,
            );
        }
    }
}
