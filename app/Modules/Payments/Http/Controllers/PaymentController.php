<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Payments\Http\Requests\StoreSalePaymentRequest;
use App\Modules\Payments\Http\Requests\VoidSalePaymentRequest;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = SalePayment::query()
            ->with(['sale:id,receipt_number,customer_name,total,balance_due,status', 'branch:id,name', 'user:id,name', 'method:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('payment_method_id'), fn ($query) => $query->where('payment_method_id', $request->integer('payment_method_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('paid_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('paid_at', '<=', $request->date('to')))
            ->latest('paid_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $receivables = Sale::query()
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'sale_note')
            ->where('balance_due', '>', 0)
            ->latest('sold_at')
            ->limit(12)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'customer_name', 'sold_at', 'total', 'advance_amount', 'balance_due', 'status']);

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
            'receivables' => $receivables,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'methods' => UiCatalogCache::activePaymentMethods(),
            'methodCatalog' => PaymentMethod::query()
                ->withCount('payments')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate($request->integer('methods_per_page', 10), ['*'], 'methods_page')
                ->withQueryString(),
            'filters' => $request->only(['branch_id', 'payment_method_id', 'from', 'to', 'per_page']),
        ]);
    }

    public function store(StoreSalePaymentRequest $request, BankReconciliationService $banks): RedirectResponse
    {
        $payment = DB::transaction(function () use ($request, $banks) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($request->integer('sale_id'));
            $amount = round((float) $request->input('amount'), 2);

            $payment = SalePayment::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'payment_method_id' => $request->integer('payment_method_id'),
                'paid_at' => now(),
                'amount' => $amount,
                'exchange_rate_to_bob' => $sale->exchange_rate_to_bob,
                'amount_bob' => round($amount * (float) $sale->exchange_rate_to_bob, 2),
                'reference' => $request->string('reference')->toString() ?: null,
                'notes' => $request->string('notes')->toString() ?: null,
            ]);

            $newBalance = max(round((float) $sale->balance_due - $amount, 2), 0);
            $sale->update([
                'balance_due' => $newBalance,
                'status' => $newBalance <= 0 ? 'paid' : 'partial_paid',
            ]);

            $banks->recordSalePayment($payment);

            return $payment;
        });

        return redirect()
            ->back()
            ->with('success', "Pago {$payment->id} registrado correctamente.");
    }

    public function void(VoidSalePaymentRequest $request, SalePayment $payment, BankReconciliationService $banks): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $payment->branch_id), 403);

        DB::transaction(function () use ($request, $payment, $banks) {
            $payment = SalePayment::query()->lockForUpdate()->findOrFail($payment->id);
            $sale = Sale::query()->lockForUpdate()->findOrFail($payment->sale_id);
            $voidReason = 'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString();
            $notes = trim(implode("\n", array_filter([
                $payment->notes,
                $voidReason,
            ])));

            $banks->voidForSource($payment, $voidReason);
            $payment->update(['notes' => $notes]);
            $payment->delete();

            $activeCredits = (float) CreditNote::query()
                ->where('sale_id', $sale->id)
                ->sum('amount');
            $totalToCollect = max(round((float) $sale->total, 2), 0);
            $newBalance = max(round((float) $sale->balance_due + (float) $payment->amount, 2), 0);
            $newBalance = min($newBalance, max(round($totalToCollect - $activeCredits, 2), 0));

            $sale->update([
                'balance_due' => $newBalance,
                'status' => $this->statusForBalance($newBalance, $totalToCollect),
            ]);
        });

        return redirect()->back()->with('success', 'Pago anulado correctamente.');
    }

    private function statusForBalance(float $balance, float $totalToCollect): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        return $balance < $totalToCollect ? 'partial_paid' : 'issued';
    }
}
