<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banks\Services\BankReconciliationService;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\Payments\Http\Requests\StoreSalePaymentRequest;
use App\Modules\Payments\Http\Requests\VoidSalePaymentRequest;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleInventoryService;
use App\Modules\Sales\Services\SalesDocumentPolicy;
use App\Modules\Sales\Services\SalesWorkflowPolicy;
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
        $documentPolicy = app(SalesDocumentPolicy::class);
        $allowedMethods = UiCatalogCache::activePaymentMethods(['id', 'name', 'code', 'requires_reference'])
            ->filter(fn ($method) => $documentPolicy->isPaymentMethodAllowed($method->code, 'collections'))
            ->values();

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
            'methods' => $allowedMethods,
            'methodCatalog' => PaymentMethod::query()
                ->withCount('payments')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate($request->integer('methods_per_page', 10), ['*'], 'methods_page')
                ->withQueryString(),
            'filters' => $request->only(['branch_id', 'payment_method_id', 'from', 'to', 'per_page']),
        ]);
    }

    public function store(StoreSalePaymentRequest $request, BankReconciliationService $banks, SaleInventoryService $inventory, SalesWorkflowPolicy $workflow, FinancialLedgerService $ledger): RedirectResponse
    {
        $payment = DB::transaction(function () use ($request, $banks, $inventory, $workflow, $ledger) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($request->integer('sale_id'));
            $amount = round((float) $request->input('amount'), 2);

            $payment = $ledger->registerSalePayment(
                sale: $sale,
                userId: (int) $request->user()->id,
                paymentMethodId: $request->integer('payment_method_id'),
                amount: $amount,
                reference: $request->string('reference')->toString() ?: null,
                notes: $request->string('notes')->toString() ?: null,
            );

            $banks->recordSalePayment($payment);
            $ledger->bumpFinancialCaches();

            if ($workflow->shouldDiscountInventoryOnPayment()) {
                $sale->loadMissing('items.product:id,inventory_tracking_mode');
                $inventory->decrementForSale($sale, (int) $request->user()->id);
            }

            return $payment;
        });

        return redirect()
            ->back()
            ->with('success', "Pago {$payment->id} registrado correctamente.");
    }

    public function void(VoidSalePaymentRequest $request, SalePayment $payment, BankReconciliationService $banks, FinancialLedgerService $ledger): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $payment->branch_id), 403);

        DB::transaction(function () use ($request, $payment, $banks, $ledger) {
            $payment = SalePayment::query()->lockForUpdate()->findOrFail($payment->id);
            $sale = Sale::query()->lockForUpdate()->findOrFail($payment->sale_id);
            $voidReason = 'Anulado por '.$request->user()->name.': '.$request->string('reason')->toString();

            $banks->voidForSource($payment, $voidReason);
            $ledger->voidSalePayment($payment, $sale, $voidReason);

            $ledger->bumpFinancialCaches();
        });

        return redirect()->back()->with('success', 'Pago anulado correctamente.');
    }

}
