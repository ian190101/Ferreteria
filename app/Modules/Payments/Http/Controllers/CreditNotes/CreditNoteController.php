<?php

namespace App\Modules\Payments\Http\Controllers\CreditNotes;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Requests\CreditNotes\StoreCreditNoteRequest;
use App\Modules\Payments\Http\Requests\CreditNotes\VoidCreditNoteRequest;
use App\Modules\Payments\Models\CreditNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreditNoteController extends Controller
{
    public function index(Request $request): Response
    {
        $creditNotes = CreditNote::query()
            ->with([
                'sale:id,receipt_number,customer_name,total,balance_due,status',
                'saleReturn:id,return_number,total_amount',
                'branch:id,name',
                'user:id,name',
            ])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('sale_id'), fn ($query) => $query->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('issued_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('issued_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($nested) use ($search) {
                    $nested->where('credit_number', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery
                            ->where('receipt_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%"));
                });
            })
            ->latest('issued_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return Inertia::render('Payments/CreditNotes/Index', [
            'creditNotes' => $creditNotes,
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'receivables' => $this->receivables($request),
            'returns' => $this->availableReturns($request),
            'filters' => $request->only(['branch_id', 'sale_id', 'from', 'to', 'search', 'per_page']),
        ]);
    }

    public function store(StoreCreditNoteRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $sale = Sale::query()
                ->where('document_type', 'sale_note')
                ->where('status', '!=', 'void')
                ->lockForUpdate()
                ->findOrFail($request->integer('sale_id'));
            $amount = round((float) $request->input('amount'), 2);

            if ($amount > (float) $sale->balance_due) {
                throw ValidationException::withMessages([
                    'amount' => 'La nota de credito no puede ser mayor al saldo pendiente.',
                ]);
            }

            if ($request->filled('sale_return_id')) {
                $this->validateReturnAmount($request->integer('sale_return_id'), $sale, $amount);
            }

            $creditNote = CreditNote::query()->create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'user_id' => $request->user()->id,
                'sale_return_id' => $request->filled('sale_return_id') ? $request->integer('sale_return_id') : null,
                'credit_number' => $request->validated('credit_number'),
                'issued_at' => now(),
                'amount' => $amount,
                'exchange_rate_to_bob' => $sale->exchange_rate_to_bob,
                'amount_bob' => round($amount * (float) $sale->exchange_rate_to_bob, 2),
                'reason' => $request->validated('reason'),
                'notes' => $request->validated('notes'),
            ]);

            $newBalance = max(round((float) $sale->balance_due - $amount, 2), 0);
            $sale->update([
                'balance_due' => $newBalance,
                'status' => $newBalance <= 0 ? 'paid' : 'partial_paid',
                'internal_notes' => trim(implode("\n", array_filter([
                    $sale->internal_notes,
                    "Nota de credito {$creditNote->credit_number}: {$creditNote->reason}",
                ]))),
            ]);
        });

        return redirect()->route('payments.credit-notes.index')->with('success', 'Nota de credito registrada correctamente.');
    }

    public function void(VoidCreditNoteRequest $request, CreditNote $creditNote): RedirectResponse
    {
        abort_unless(BranchAccess::canAccess($request->user(), (int) $creditNote->branch_id), 403);

        DB::transaction(function () use ($request, $creditNote) {
            $creditNote = CreditNote::query()->lockForUpdate()->findOrFail($creditNote->id);
            $sale = Sale::query()->lockForUpdate()->findOrFail($creditNote->sale_id);
            $notes = trim(implode("\n", array_filter([
                $creditNote->notes,
                'Anulada por '.$request->user()->name.': '.$request->string('reason')->toString(),
            ])));

            $creditNote->update(['notes' => $notes]);
            $creditNote->delete();

            $totalToCollect = max(round((float) $sale->total, 2), 0);
            $newBalance = min(max(round((float) $sale->balance_due + (float) $creditNote->amount, 2), 0), $totalToCollect);

            $sale->update([
                'balance_due' => $newBalance,
                'status' => $this->statusForBalance($newBalance, $totalToCollect),
            ]);
        });

        return redirect()->route('payments.credit-notes.index')->with('success', 'Nota de credito anulada correctamente.');
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
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'customer_name', 'sold_at', 'total', 'advance_amount', 'balance_due', 'status']);
    }

    private function availableReturns(Request $request)
    {
        return SaleReturn::query()
            ->with(['sale:id,receipt_number,customer_name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->withSum('creditNotes as credited_amount_sum', 'amount')
            ->latest('returned_at')
            ->limit(150)
            ->get(['id', 'sale_id', 'return_number', 'returned_at', 'total_amount'])
            ->map(function (SaleReturn $saleReturn) {
                $creditedAmount = (float) $saleReturn->credited_amount_sum;

                return [
                    'id' => $saleReturn->id,
                    'sale_id' => $saleReturn->sale_id,
                    'return_number' => $saleReturn->return_number,
                    'returned_at' => $saleReturn->returned_at,
                    'total_amount' => $saleReturn->total_amount,
                    'credited_amount' => $creditedAmount,
                    'available_amount' => max(round((float) $saleReturn->total_amount - $creditedAmount, 2), 0),
                    'sale' => $saleReturn->sale,
                ];
            })
            ->filter(fn (array $saleReturn) => $saleReturn['available_amount'] > 0)
            ->values();
    }

    private function validateReturnAmount(int $saleReturnId, Sale $sale, float $amount): void
    {
        $saleReturn = SaleReturn::query()
            ->where('sale_id', $sale->id)
            ->lockForUpdate()
            ->findOrFail($saleReturnId);
        $creditedAmount = (float) CreditNote::query()
            ->where('sale_return_id', $saleReturn->id)
            ->sum('amount');
        $availableReturnAmount = max(round((float) $saleReturn->total_amount - $creditedAmount, 2), 0);

        if ($amount > $availableReturnAmount) {
            throw ValidationException::withMessages([
                'amount' => 'La nota de credito supera el monto disponible de la devolucion.',
            ]);
        }
    }

    private function statusForBalance(float $balance, float $totalToCollect): string
    {
        if ($balance <= 0) {
            return 'paid';
        }

        return $balance < $totalToCollect ? 'partial_paid' : 'issued';
    }
}
