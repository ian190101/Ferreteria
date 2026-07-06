<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Requests\StoreCustomerTypeRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerTypeRequest;
use App\Modules\Customers\Models\CustomerType;
use Illuminate\Http\RedirectResponse;

class CustomerTypeController extends Controller
{
    public function store(StoreCustomerTypeRequest $request): RedirectResponse
    {
        CustomerType::query()->create($request->validated());

        return redirect()->route('customers.index')->with('success', 'Tipo de cliente creado correctamente.');
    }

    public function update(UpdateCustomerTypeRequest $request, CustomerType $type): RedirectResponse
    {
        $type->update($request->validated());

        return redirect()->route('customers.index')->with('success', 'Tipo de cliente actualizado correctamente.');
    }

    public function destroy(CustomerType $type): RedirectResponse
    {
        $type->update(['is_active' => false]);
        $type->delete();

        return redirect()->route('customers.index')->with('success', 'Tipo de cliente desactivado correctamente.');
    }
}
