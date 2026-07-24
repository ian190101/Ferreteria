<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\FinancialLedgerService;
use App\Modules\Payments\Http\Requests\StorePaymentMethodRequest;
use App\Modules\Payments\Http\Requests\UpdatePaymentMethodRequest;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;

class PaymentMethodController extends Controller
{
    public function store(StorePaymentMethodRequest $request, FinancialLedgerService $ledger): RedirectResponse
    {
        PaymentMethod::query()->create($request->validated());
        $ledger->bumpFinancialCaches();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago creado correctamente.');
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $method, FinancialLedgerService $ledger): RedirectResponse
    {
        $method->update($request->validated());
        $ledger->bumpFinancialCaches();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago actualizado correctamente.');
    }

    public function destroy(PaymentMethod $method, FinancialLedgerService $ledger): RedirectResponse
    {
        $method->update(['is_active' => false]);
        $method->delete();
        $ledger->bumpFinancialCaches();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago desactivado correctamente.');
    }
}
