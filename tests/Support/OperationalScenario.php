<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\InventoryReservation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

final class OperationalScenario
{
    /** @return array<string, mixed> */
    public static function create(): array
    {
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'fabrica_calmina_aroma_testing') {
            throw new \LogicException('Los escenarios destructivos solo pueden prepararse en la base de pruebas.');
        }

        $branch = Branch::query()->create([
            'name' => 'Sucursal de validación', 'code' => 'QA-OPS', 'barcode' => 'QA-OPS', 'is_active' => true,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'email_verified_at' => now()]);
        $user->accessibleBranches()->sync([$branch->id]);
        $supplier = Supplier::query()->create(['name' => 'Proveedor de validación', 'is_active' => true]);
        $products = [];
        foreach (['global' => Product::TRACKING_GLOBAL, 'coil' => Product::TRACKING_COIL] as $key => $mode) {
            $products[$key] = Product::query()->create([
                'name' => 'Producto QA '.$key, 'sku' => 'QA-'.$key, 'barcode' => 'QA-'.$key,
                'inventory_tracking_mode' => $mode, 'minimum_stock_meters' => 0, 'is_active' => true,
            ]);
        }

        $purchases = [];
        foreach ([1400, 600] as $index => $total) {
            $purchases[] = Purchase::query()->create([
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'user_id' => $user->id,
                'document_number' => 'QA-COMP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'purchase_date' => now()->toDateString(),
                'total_amount' => $total, 'paid_amount' => $index === 0 ? 300 : 0,
                'balance_due' => $index === 0 ? 1100 : 600,
                'payment_status' => $index === 0 ? 'partial_paid' : 'unpaid', 'status' => 'received',
            ]);
            $purchases[$index]->items()->create([
                'product_id' => $products['global']->id, 'meters' => $index === 0 ? 40 : 60, 'unit_cost' => 10,
            ]);
        }

        $stock = ProductBranchStock::query()->create([
            'branch_id' => $branch->id, 'product_id' => $products['global']->id,
            'available_meters' => 100, 'reserved_meters' => 0,
        ]);
        $coil = ProductCoil::query()->create([
            'branch_id' => $branch->id, 'product_id' => $products['coil']->id,
            'barcode' => 'QA-BOB-001', 'initial_meters' => 100, 'available_meters' => 100, 'status' => 'available',
        ]);
        $purchases[0]->items()->create([
            'product_id' => $products['coil']->id, 'product_coil_id' => $coil->id,
            'coil_barcode' => $coil->barcode, 'meters' => 100, 'unit_cost' => 10,
        ]);
        $reservation = InventoryReservation::query()->create([
            'branch_id' => $branch->id, 'product_id' => $products['coil']->id,
            'product_coil_id' => $coil->id, 'user_id' => $user->id,
            'meters' => 10, 'status' => InventoryReservation::STATUS_ACTIVE, 'reason' => 'Reserva para validación',
        ]);
        $method = PaymentMethod::query()->create(['name' => 'Efectivo QA', 'code' => 'qa-cash', 'is_active' => true]);
        foreach ([100, 200] as $amount) {
            PurchasePayment::query()->create([
                'purchase_id' => $purchases[0]->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
                'payment_method_id' => $method->id, 'paid_at' => now(), 'amount' => $amount,
            ]);
        }

        $order = PurchaseOrder::query()->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'order_number' => 'QA-OC-001', 'ordered_at' => now()->toDateString(),
            'total_amount' => 1000, 'status' => PurchaseOrder::STATUS_CONVERTED,
            'converted_purchase_id' => $purchases[1]->id, 'converted_at' => now(),
        ]);
        $orderItem = $order->items()->create([
            'product_id' => $products['global']->id, 'meters' => 100, 'received_meters' => 100,
            'unit_cost' => 10, 'line_total' => 1000,
        ]);
        foreach ([40, 60] as $index => $meters) {
            $receipt = $order->receipts()->create([
                'purchase_id' => $purchases[$index]->id, 'branch_id' => $branch->id,
                'supplier_id' => $supplier->id, 'user_id' => $user->id,
                'receipt_number' => 'QA-REC-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'received_at' => now()->toDateString(),
                'total_amount' => $meters * 10,
            ]);
            $receipt->items()->create([
                'purchase_order_item_id' => $orderItem->id, 'product_id' => $products['global']->id,
                'meters' => $meters, 'unit_cost' => 10, 'line_total' => $meters * 10,
            ]);
        }
        $sequence = DocumentSequence::query()->create([
            'branch_id' => $branch->id, 'document_type' => 'sale_note', 'name' => 'Notas QA',
            'prefix' => 'QA-NV-', 'next_number' => 2, 'padding' => 6, 'is_active' => true,
        ]);

        return compact('branch', 'user', 'supplier', 'products', 'purchases', 'stock', 'coil', 'reservation', 'order', 'orderItem', 'sequence');
    }

    /** @param array<string, mixed> $scenario */
    public static function addBankPayments(array $scenario): BankAccount
    {
        $account = BankAccount::query()->create([
            'branch_id' => $scenario['branch']->id, 'name' => 'Cuenta QA', 'bank_name' => 'Banco QA',
            'account_number' => 'QA-001', 'currency_code' => 'BOB', 'opening_balance' => 1000,
            'current_balance' => 700, 'is_active' => true,
        ]);
        foreach ($scenario['purchases'][0]->payments as $payment) {
            BankTransaction::query()->create([
                'bank_account_id' => $account->id, 'branch_id' => $scenario['branch']->id,
                'user_id' => $scenario['user']->id, 'type' => BankTransaction::TYPE_WITHDRAWAL,
                'transacted_at' => now(), 'amount' => $payment->amount, 'status' => BankTransaction::STATUS_REGISTERED,
                'source_type' => PurchasePayment::class, 'source_id' => $payment->id, 'description' => 'Pago QA',
            ]);
        }

        return $account;
    }

    /** @return array<string, string> */
    public static function snapshot(): array
    {
        $snapshot = [];
        foreach (['purchases', 'purchase_items', 'purchase_payments', 'product_coils', 'product_branch_stocks', 'cache_invalidation_outbox',
            'inventory_movements', 'inventory_reservations', 'bank_accounts', 'bank_transactions',
            'purchase_orders', 'purchase_order_items', 'purchase_order_receipts', 'purchase_order_receipt_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
