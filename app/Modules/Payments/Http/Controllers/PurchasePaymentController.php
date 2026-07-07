<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Payments\Http\Requests\StorePurchasePaymentRequest;
use App\Modules\Payments\Http\Requests\VoidPurchasePaymentRequest;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchasePaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = PurchasePayment::query()
            ->with(['purchase:id,branch_id,supplier_id,document_number,total_amount,paid_amount,balance_due,payment_status', 'purchase.supplier:id,name', 'branch:id,name', 'user:id,name', 'method:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('payment_method_id'), fn ($query) => $query->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('paid_at', '<=', $request->date('to')))
            ->latest('paid_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $payables = Purchase::query()
            ->with(['branch:id,name', 'supplier:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('balance_due', '>', 0)
            ->latest('purchase_date')
            ->limit(12)
            ->get(['id', 'branch_id', 'supplier_id', 'document_number', 'purchase_date', 'total_amount', 'paid_amount', 'balance_due', 'payment_status', 'status']);

        return Inertia::render('Payments/PurchasePayments/Index', [
            'payments' => $payments,
            'payables' => $payables,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'methods' => UiCatalogCache::activePaymentMethods(),
            'filters' => $request->only(['branch_id', 'payment_method_id', 'from', 'to', 'per_page']),
        ]);
    }

    public function store(StorePurchasePaymentRequest $request, BankReconciliationService $banks): RedirectResponse
    {
        $payment = DB::transaction(function () use ($request, $banks) {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($request->integer('purchase_id'));
            $amount = round((float) $request->input('amount'), 2);

            $payment = PurchasePayment::query()->create([
                'purchase_id' => $purchase->id,
                'branch_id' => $purchase->branch_id,
                'user_id' => $request->user()->id,
                'payment_method_id' => $request->integer('payment_method_id'),
                'paid_at' => now(),
                'amount' => $amount,
                'reference' => $request->string('reference')->toString() ?: null,
                'notes' => $request->string('notes')->toString() ?: null,
            ]);

            $paidAmount = round((float) $purchase->paid_amount + $amount, 2);
            $newBalance = max(round((float) $purchase->total_amount - $paidAmount, 2), 0);
            $purchase->update([
                'paid_amount' => $paidAmount,
                'balance_due' => $newBalance,
                'payment_status' => $this->statusForBalance($newBalance, (float) $purchase->total_amount),
            ]);

            $banks->recordPurchasePayment($payment);

            return $payment;
        });

        return redirect()
            ->back()
            ->with('success', "Pago de compra {$payment->id} registrado correctamente.");
    }

    public function void(VoidPurchasePaymentRequest $request, PurchasePayment $payment, BankReconciliationService $banks): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $payment->branch_id), 403);

        DB::transaction(function () use ($request, $payment, $banks) {
            $payment = PurchasePayment::query()->lockForUpdate()->findOrFail($payment->id);
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($payment->purchase_id);
            $voidReason = 'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString();
            $notes = trim(implode("\n", array_filter([
                $payment->notes,
                $voidReason,
            ])));

            $banks->voidForSource($payment, $voidReason);
            $payment->update(['notes' => $notes]);
            $payment->delete();

            $paidAmount = (float) PurchasePayment::query()
                ->where('purchase_id', $purchase->id)
                ->sum('amount');
            $newBalance = max(round((float) $purchase->total_amount - $paidAmount, 2), 0);

            $purchase->update([
                'paid_amount' => round($paidAmount, 2),
                'balance_due' => $newBalance,
                'payment_status' => $this->statusForBalance($newBalance, (float) $purchase->total_amount),
            ]);
        });

        return redirect()->back()->with('success', 'Pago de compra anulado correctamente.');
    }

    private function statusForBalance(float $balance, float $total): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        return $balance < $total ? 'partial_paid' : 'unpaid';
    }
}
