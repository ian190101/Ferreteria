<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Services\BillingWorkflowPolicy;
use App\Modules\Billing\Services\SiatInvoiceService;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SiatInvoiceController extends Controller
{
    public function show(SiatInvoice $invoice): Response
    {
        abort_unless(BranchAccess::canAccess(request()->user(), (int) $invoice->branch_id), 403);

        $invoice->load(['branch:id,name,address', 'sale:id,receipt_number,total', 'items']);

        return Inertia::render('Billing/Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function issue(Request $request, Sale $sale, SiatInvoiceService $service, BillingWorkflowPolicy $policy): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $sale->branch_id), 403);
        abort_unless($policy->shouldShowManualButton($sale) || $request->user()->hasRole('sistemasuperadmin'), 403);

        $invoice = $service->issueFromSale($sale, (int) $request->user()->id, $request->boolean('temporary_when_offline'));

        return redirect()->route('billing.invoices.show', $invoice)->with('success', 'Factura SIAT generada correctamente.');
    }

    public function void(Request $request, SiatInvoice $invoice, SiatInvoiceService $service): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $invoice->branch_id), 403);

        $data = $request->validate([
            'reason_code' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $service->void($invoice, (int) $data['reason_code'], $data['reason']);

        return back()->with('success', 'Factura anulada correctamente.');
    }
}
