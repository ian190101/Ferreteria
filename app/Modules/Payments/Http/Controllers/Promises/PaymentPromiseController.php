<?php

namespace App\Modules\Payments\Http\Controllers\Promises;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\Promises\ResolvePaymentPromiseRequest;
use App\Modules\Payments\Http\Requests\Promises\StorePaymentPromiseRequest;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentPromiseController extends Controller
{
    public function index(Request $request): Response
    {
        $promises = PaymentPromise::query()
            ->with(['sale:id,receipt_number,customer_name,total,balance_due,status', 'branch:id,name', 'user:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('promised_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('promised_date', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($nested) use ($search) {
                    $nested->where('promise_number', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%")
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                            ->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw("status = 'pending' desc")
            ->orderBy('promised_date')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Payments/Promises/Index', [
            'promises' => $promises,
            'summary' => $this->summary($request),
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'receivables' => $this->receivables($request),
            'statuses' => [
                PaymentPromise::STATUS_PENDING,
                PaymentPromise::STATUS_FULFILLED,
                PaymentPromise::STATUS_BROKEN,
                PaymentPromise::STATUS_CANCELLED,
            ],
            'channels' => ['phone', 'whatsapp', 'visit', 'email', 'other'],
            'filters' => $request->only(['branch_id', 'status', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StorePaymentPromiseRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));

            abort_unless(BranchAccess::canAccess($request->user(), $sale->branch_id), 403);

            $amount = round((float) $request->input('promised_amount'), 2);

            if ($amount > (float) $sale->balance_due) {
                throw ValidationException::withMessages([
                    'promised_amount' => 'La promesa no puede superar el saldo pendiente.',
                ]);
            }

            PaymentPromise::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'promise_number' => $request->validated('promise_number'),
                'promised_date' => $request->validated('promised_date'),
                'promised_amount' => $amount,
                'contact_name' => $request->validated('contact_name'),
                'contact_phone' => $request->validated('contact_phone'),
                'channel' => $request->validated('channel'),
                'status' => PaymentPromise::STATUS_PENDING,
                'notes' => $request->validated('notes'),
            ]);
        });

        return redirect()->route('payments.promises.index')->with('success', 'Promesa de pago registrada correctamente.');
    }

    public function resolve(ResolvePaymentPromiseRequest $request, PaymentPromise $promise): RedirectResponse
    {
        DB::transaction(function () use ($request, $promise) {
            $promise = PaymentPromise::query()->lockForUpdate()->findOrFail($promise->id);

            abort_unless(BranchAccess::canAccess($request->user(), $promise->branch_id), 403);

            if ($promise->status !== PaymentPromise::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se pueden cerrar promesas pendientes.',
                ]);
            }

            $promise->update([
                'status' => $request->validated('status'),
                'resolved_at' => now(),
                'notes' => trim(implode("\n", array_filter([
                    $promise->notes,
                    $request->validated('notes'),
                ]))),
            ]);
        });

        return redirect()->route('payments.promises.index')->with('success', 'Promesa actualizada correctamente.');
    }

    private function receivables(Request $request)
    {
        return Sale::query()
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->where('document_type', 'sale_note')
            ->where('balance_due', '>', 0)
            ->latest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'customer_name', 'customer_contact', 'sold_at', 'total', 'balance_due', 'status']);
    }

    private function summary(Request $request): array
    {
        $base = PaymentPromise::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')));

        return [
            'pending_count' => (clone $base)->where('status', PaymentPromise::STATUS_PENDING)->count(),
            'overdue_count' => (clone $base)
                ->where('status', PaymentPromise::STATUS_PENDING)
                ->whereDate('promised_date', '<', today())
                ->count(),
            'due_today_count' => (clone $base)
                ->where('status', PaymentPromise::STATUS_PENDING)
                ->whereDate('promised_date', today())
                ->count(),
            'pending_amount' => (float) (clone $base)
                ->where('status', PaymentPromise::STATUS_PENDING)
                ->sum('promised_amount'),
        ];
    }
}
