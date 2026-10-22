<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Services;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Inventory\Services\InventoryQuantityService;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseItem;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\PurchaseOrderItem;
use App\Modules\Purchases\Models\PurchaseOrderReceipt;
use App\Modules\Purchases\Models\PurchaseOrderReceiptItem;
use App\Support\OperationalCacheOutbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private readonly InventoryQuantityService $quantities,
        private readonly BankReconciliationService $banks,
        private readonly FinancialLedgerService $ledger,
    ) {}

    public function voidPurchase(Purchase $purchase, int $userId, string $reason): Purchase
    {
        if (trim($reason) === '') {
            $this->reject('El motivo de anulación es obligatorio.');
        }

        return DB::transaction(function () use ($purchase, $userId, $reason): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            app(PurchaseVoidAuthorization::class)->authorize(User::query()->findOrFail($userId), $purchase);
            if (in_array($purchase->status, ['voided', 'void'], true)) {
                $this->reject('La compra ya se encuentra anulada.');
            }
            if (! in_array($purchase->status, ['received', 'draft'], true)) {
                $this->reject('El estado de la compra no permite su anulación.');
            }

            // Las recepciones bloquean primero la orden; se conserva ese orden antes del inventario.
            $orderIds = PurchaseOrderReceipt::query()->where('purchase_id', $purchase->id)->pluck('purchase_order_id');
            $orders = PurchaseOrder::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            $orderItems = PurchaseOrderItem::query()->whereIn('purchase_order_id', $orderIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $receipts = PurchaseOrderReceipt::query()->where('purchase_id', $purchase->id)
                ->orderBy('id')->lockForUpdate()->get();
            $receiptItems = PurchaseOrderReceiptItem::query()->whereIn('purchase_order_receipt_id', $receipts->modelKeys())
                ->orderBy('id')->lockForUpdate()->get();
            $purchase->setRelation('items', $purchase->items()->with('product')->orderBy('id')->lockForUpdate()->get());
            $purchase->setRelation('payments', $purchase->payments()->orderBy('id')->lockForUpdate()->get());

            if ($purchase->payments->contains(fn ($payment) => (int) $payment->branch_id !== (int) $purchase->branch_id)
                || round((float) $purchase->payments->sum('amount'), 2) !== round((float) $purchase->paid_amount, 2)) {
                $this->reject('Los pagos y el saldo pagado de la compra no son consistentes.');
            }

            $receiptQuantities = $receiptItems->groupBy('purchase_order_item_id')->map(fn ($items) => round((float) $items->sum('meters'), 3));
            foreach ($receiptQuantities as $itemId => $quantity) {
                $item = $orderItems->get($itemId);
                if (! $item || $quantity <= 0 || (float) $item->received_meters < $quantity) {
                    $this->reject('Las cantidades de las recepciones no son consistentes con la orden.');
                }
            }
            if ($receipts->contains(fn ($receipt) => $receipt->voided_at !== null) || $orders->count() !== $orderIds->unique()->count()) {
                $this->reject('Faltan órdenes o existen recepciones previamente anuladas.');
            }

            $inventory = $purchase->status === 'received' ? $this->inventoryPlan($purchase) : [];
            // Se bloquean también los movimientos bancarios antes del primer reverso.
            $bankTransactions = BankTransaction::query()->where('source_type', PurchasePayment::class)
                ->whereIn('source_id', $purchase->payments->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $accountIds = $bankTransactions->pluck('bank_account_id')->unique();
            if (BankAccount::query()->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get()->count() !== $accountIds->count()) {
                $this->reject('Falta una cuenta bancaria activa vinculada a los pagos de la compra.');
            }
            if ($bankTransactions->where('status', BankTransaction::STATUS_REGISTERED)->groupBy('source_id')->contains(fn ($rows) => $rows->count() > 1)) {
                $this->reject('Existen movimientos bancarios duplicados para un pago de la compra.');
            }

            foreach ($inventory as [$items, $resource]) {
                foreach ($items as $item) {
                    $before = (float) $resource->available_meters;
                    if ($resource instanceof ProductCoil) {
                        $after = round($before - (float) $item->meters, 3);
                        $resource->update(['available_meters' => $after]);
                    } else {
                        [$before, $after] = $this->quantities->decrease($resource, (float) $item->meters);
                    }
                    InventoryMovement::query()->create([
                        'branch_id' => $purchase->branch_id, 'product_id' => $item->product_id,
                        'product_coil_id' => $resource instanceof ProductCoil ? $resource->id : null,
                        'user_id' => $userId,
                        'source_type' => $resource instanceof ProductCoil ? PurchaseItem::class : Purchase::class,
                        'source_id' => $resource instanceof ProductCoil ? $item->id : $purchase->id,
                        'type' => $resource instanceof ProductCoil ? 'purchase_void_coil' : 'purchase_void_global',
                        'meters_delta' => -((float) $item->meters), 'meters_before' => $before, 'meters_after' => $after,
                        'reason' => mb_substr("Anulación de compra {$purchase->document_number}: {$reason}", 0, 255), 'created_at' => now(),
                    ]);
                }
                if ($resource instanceof ProductCoil) {
                    $resource->update(['status' => 'voided']);
                    $resource->delete();
                }
            }

            $paymentReason = 'Anulado por anulación de compra '.$purchase->document_number.': '.$reason;
            foreach ($purchase->payments as $payment) {
                $this->banks->voidForSource($payment, $paymentReason);
                $this->ledger->voidPurchasePayment($payment, $purchase, $paymentReason);
            }
            foreach ($receiptQuantities as $itemId => $quantity) {
                $orderItems[$itemId]->decrement('received_meters', $quantity);
            }
            foreach ($receipts as $receipt) {
                $receipt->update(['voided_at' => now()]);
            }
            foreach ($orders as $order) {
                $items = $orderItems->where('purchase_order_id', $order->id);
                $anyReceived = $items->contains(fn ($item) => (float) $item->received_meters > 0);
                $allReceived = $items->isNotEmpty() && $items->every(fn ($item) => (float) $item->received_meters >= (float) $item->meters);
                $order->update([
                    'status' => $allReceived ? PurchaseOrder::STATUS_CONVERTED : ($anyReceived ? PurchaseOrder::STATUS_PARTIAL_RECEIVED : PurchaseOrder::STATUS_APPROVED),
                    'converted_purchase_id' => $order->converted_purchase_id === $purchase->id ? null : $order->converted_purchase_id,
                    'converted_at' => $order->converted_purchase_id === $purchase->id ? null : $order->converted_at,
                ]);
            }
            $purchase->update([
                'status' => 'voided', 'payment_status' => 'voided', 'paid_amount' => 0, 'balance_due' => 0,
                'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason,
                'void_references' => ['payments' => $purchase->payments->modelKeys(), 'items' => $purchase->items->modelKeys(), 'receipts' => $receipts->modelKeys()],
            ]);
            app(OperationalCacheOutbox::class)->schedule();

            return $purchase;
        }, 3);
    }

    /** @return array<int, array{0: Collection, 1: ProductCoil|ProductBranchStock}> */
    private function inventoryPlan(Purchase $purchase): array
    {
        if ($purchase->items->isEmpty()) {
            $this->reject('La compra recibida no tiene ítems de inventario.');
        }
        $groups = [];
        foreach ($purchase->items as $item) {
            if (! $item->product || (float) $item->meters <= 0) {
                $this->reject('La compra contiene un producto faltante o una cantidad inválida.');
            }
            $isCoil = $item->product_coil_id !== null || $item->product->inventory_tracking_mode === Product::TRACKING_COIL;
            if ($isCoil && ! $item->product_coil_id) {
                $this->reject('Falta la bobina vinculada a un ítem de la compra.');
            }
            $key = ($isCoil ? 'coil:' : 'stock:').($isCoil ? $item->product_coil_id : $item->product_id);
            $groups[$key] ??= collect();
            $groups[$key]->push($item);
        }
        ksort($groups, SORT_NATURAL);
        $plan = [];
        foreach ($groups as $key => $items) {
            $first = $items->first();
            if ($items->pluck('product_id')->unique()->count() !== 1) {
                $this->reject('Los ítems vinculados a una bobina deben pertenecer al mismo producto.');
            }
            $quantity = round((float) $items->sum('meters'), 3);
            if (str_starts_with($key, 'coil:')) {
                $resource = ProductCoil::query()->where('branch_id', $purchase->branch_id)
                    ->where('product_id', $first->product_id)->lockForUpdate()->find($first->product_coil_id);
                if (! $resource || $resource->status !== 'available') {
                    $this->reject('Falta una bobina disponible en la sucursal de la compra.');
                }
                $reservations = InventoryReservation::query()->where('product_coil_id', $resource->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)->orderBy('id')->lockForUpdate()->get();
                if ($reservations->isNotEmpty()) {
                    $this->reject("La bobina {$resource->barcode} tiene reservas activas. Libéralas mediante el flujo de reservas.");
                }
                if (InventoryMovement::query()->where('product_coil_id', $resource->id)->where('meters_delta', '<', 0)->exists()) {
                    $this->reject("La bobina {$resource->barcode} tiene movimientos de consumo y no puede anularse.");
                }
                if (round((float) $resource->available_meters, 3) !== $quantity
                    || round((float) $resource->initial_meters, 3) !== $quantity) {
                    $this->reject("La bobina {$resource->barcode} fue consumida o su inventario no coincide con la compra.");
                }
            } else {
                $resource = ProductBranchStock::query()->where('branch_id', $purchase->branch_id)
                    ->where('product_id', $first->product_id)->lockForUpdate()->first();
                if (! $resource) {
                    $this->reject('Falta el registro de inventario global de un producto de la compra.');
                }
                if ($this->quantities->free($resource) < $quantity) {
                    $this->reject("El stock libre de {$first->product->name} no alcanza para revertir todos los ítems de la compra.");
                }
            }
            $plan[] = [$items, $resource];
        }

        return $plan;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['purchase' => $message]);
    }
}
