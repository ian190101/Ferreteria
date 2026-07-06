<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Requests\StoreCustomerInteractionRequest;
use App\Modules\Customers\Http\Requests\StoreCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInteraction;
use App\Modules\Customers\Models\CustomerType;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Payments\Models\SalePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $customers = Customer::query()
            ->with('type:id,name')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('customer_type_id'), fn ($query) => $query->where('customer_type_id', $request->integer('customer_type_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->latest('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'types' => $this->activeTypes(),
            'typeCatalog' => CustomerType::query()
                ->withCount('customers')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate($request->integer('types_per_page', 10), ['*'], 'types_page')
                ->withQueryString(),
            'filters' => $request->only(['search', 'customer_type_id', 'is_active', 'per_page']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Form', [
            'customer' => null,
            'types' => $this->activeTypes(),
        ]);
    }

    public function statement(Request $request, Customer $customer): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $from = filled($validated['from'] ?? null) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = filled($validated['to'] ?? null) ? Carbon::parse($validated['to'])->endOfDay() : null;
        $perPage = (int) ($validated['per_page'] ?? 10);

        $salesBase = $customer->sales()
            ->where('document_type', 'sale_note')
            ->when($from, fn (Builder $query) => $query->where('sold_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('sold_at', '<=', $to));

        $paymentsBase = SalePayment::query()
            ->whereHas('sale', fn (Builder $query) => $query->where('customer_id', $customer->id))
            ->when($from, fn (Builder $query) => $query->where('paid_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('paid_at', '<=', $to));

        $creditNotesBase = CreditNote::query()
            ->whereHas('sale', fn (Builder $query) => $query->where('customer_id', $customer->id))
            ->when($from, fn (Builder $query) => $query->where('issued_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->where('issued_at', '<=', $to));

        $promisesBase = PaymentPromise::query()
            ->whereHas('sale', fn (Builder $query) => $query->where('customer_id', $customer->id))
            ->when($from, fn (Builder $query) => $query->where('promised_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $query) => $query->where('promised_date', '<=', $to->toDateString()));

        return Inertia::render('Customers/Statement', [
            'customer' => $customer->load('type:id,name'),
            'metrics' => [
                'sales_total' => (float) (clone $salesBase)->sum('total'),
                'balance_due' => (float) (clone $salesBase)->sum('balance_due'),
                'payments_total' => (float) (clone $paymentsBase)->sum('amount_bob'),
                'credit_notes_total' => (float) (clone $creditNotesBase)->sum('amount_bob'),
                'pending_promises_amount' => (float) (clone $promisesBase)->where('status', PaymentPromise::STATUS_PENDING)->sum('promised_amount'),
                'pending_promises_count' => (clone $promisesBase)->where('status', PaymentPromise::STATUS_PENDING)->count(),
            ],
            'sales' => (clone $salesBase)
                ->with(['branch:id,name', 'currency:id,code,symbol'])
                ->latest('sold_at')
                ->paginate($perPage, ['*'], 'sales_page')
                ->withQueryString(),
            'payments' => (clone $paymentsBase)
                ->with(['sale:id,receipt_number,customer_id', 'method:id,name'])
                ->latest('paid_at')
                ->paginate($perPage, ['*'], 'payments_page')
                ->withQueryString(),
            'creditNotes' => (clone $creditNotesBase)
                ->with(['sale:id,receipt_number,customer_id'])
                ->latest('issued_at')
                ->paginate($perPage, ['*'], 'credits_page')
                ->withQueryString(),
            'promises' => (clone $promisesBase)
                ->with(['sale:id,receipt_number,customer_id'])
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->orderBy('promised_date')
                ->paginate($perPage, ['*'], 'promises_page')
                ->withQueryString(),
            'interactions' => $customer->interactions()
                ->with('user:id,name')
                ->when($from, fn (Builder $query) => $query->where('contact_at', '>=', $from))
                ->when($to, fn (Builder $query) => $query->where('contact_at', '<=', $to))
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->orderByRaw('case when follow_up_at is null then 1 else 0 end')
                ->orderBy('follow_up_at')
                ->latest('contact_at')
                ->paginate($perPage, ['*'], 'interactions_page')
                ->withQueryString(),
            'filters' => [
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Customer::query()->create($request->validated());

        return redirect()->route('customers.index')->with('success', 'Cliente creado correctamente.');
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customers/Form', [
            'customer' => $customer->load('type:id,name'),
            'types' => $this->activeTypes(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()->route('customers.index')->with('success', 'Cliente actualizado correctamente.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->update(['is_active' => false]);
        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Cliente desactivado correctamente.');
    }

    public function storeInteraction(StoreCustomerInteractionRequest $request, Customer $customer): RedirectResponse
    {
        $status = $request->string('status')->toString();

        $customer->interactions()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'completed_at' => $status === CustomerInteraction::STATUS_COMPLETED ? now() : null,
        ]);

        return redirect()->route('customers.statement', $customer)->with('success', 'Seguimiento registrado correctamente.');
    }

    public function completeInteraction(Request $request, Customer $customer, CustomerInteraction $interaction): RedirectResponse
    {
        abort_unless($request->user()?->can('customers.manage'), 403);

        if ($interaction->customer_id !== $customer->id) {
            abort(404);
        }

        if ($interaction->status !== CustomerInteraction::STATUS_COMPLETED) {
            $interaction->update([
                'status' => CustomerInteraction::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        return redirect()->route('customers.statement', $customer)->with('success', 'Seguimiento marcado como completado.');
    }

    private function activeTypes()
    {
        return CustomerType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
