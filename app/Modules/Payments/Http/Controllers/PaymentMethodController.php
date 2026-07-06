<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\StorePaymentMethodRequest;
use App\Modules\Payments\Http\Requests\UpdatePaymentMethodRequest;
use App\Modules\Payments\Models\PaymentMethod;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;

class PaymentMethodController extends Controller
{
    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        PaymentMethod::query()->create($request->validated());
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago creado correctamente.');
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $method): RedirectResponse
    {
        $method->update($request->validated());
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago actualizado correctamente.');
    }

    public function destroy(PaymentMethod $method): RedirectResponse
    {
        $method->update(['is_active' => false]);
        $method->delete();
        UiCatalogCache::forgetFinancialCatalogs();

        return redirect()->route('payments.index')->with('success', 'Metodo de pago desactivado correctamente.');
    }
}
