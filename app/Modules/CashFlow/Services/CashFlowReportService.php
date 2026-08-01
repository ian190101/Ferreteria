<?php

namespace App\Modules\CashFlow\Services;

use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Support\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CashFlowReportService
{
    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    private const SOURCES = ['sales', 'purchases', 'expenses', 'banks'];

    public function build(Request $request): array
    {
        $filters = $this->filters($request);
        $movements = $this->movements($request, $filters);
        $filtered = $this->applyCollectionFilters($movements, $filters);

        return [
            'movements' => $this->paginate($filtered, $filters['per_page'], $request),
            'summary' => $this->summary($filtered),
            'bySource' => $this->groupBy($filtered, 'source'),
            'byBranch' => $this->groupBy($filtered, 'branch_name'),
            'byMethod' => $this->groupBy($filtered, 'method_name'),
            'filters' => $filters,
        ];
    }

    public function filters(Request $request): array
    {
        $smartFilter = $request->string('smart_filter')->toString();
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        if ($smartFilter === 'today') {
            $from = now()->startOfDay();
            $to = now()->endOfDay();
        }

        if ($smartFilter === 'this_week') {
            $from = now()->startOfWeek();
            $to = now()->endOfWeek();
        }

        if ($smartFilter === 'this_month') {
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
        }

        if ($smartFilter === 'last_month') {
            $from = now()->subMonthNoOverflow()->startOfMonth();
            $to = now()->subMonthNoOverflow()->endOfMonth();
        }

        return [
            'branch_id' => $request->integer('branch_id') ?: null,
            'payment_method_id' => $request->integer('payment_method_id') ?: null,
            'type' => in_array($request->string('type')->toString(), ['all', self::TYPE_INCOME, self::TYPE_EXPENSE], true)
                ? $request->string('type')->toString()
                : 'all',
            'source' => in_array($request->string('source')->toString(), self::SOURCES, true)
                ? $request->string('source')->toString()
                : 'all',
            'smart_filter' => $smartFilter,
            'search' => trim($request->string('search')->toString()),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_at' => $from,
            'to_at' => $to,
            'per_page' => max(10, min($request->integer('per_page', 25), 100)),
        ];
    }

    private function movements(Request $request, array $filters): Collection
    {
        $sources = collect();

        if ($filters['source'] === 'all' || $filters['source'] === 'sales') {
            $sources = $sources->merge($this->salePayments($request, $filters));
        }

        if ($filters['source'] === 'all' || $filters['source'] === 'purchases') {
            $sources = $sources->merge($this->purchasePayments($request, $filters));
        }

        if ($filters['source'] === 'all' || $filters['source'] === 'expenses') {
            $sources = $sources->merge($this->expenses($request, $filters));
        }

        if (($filters['source'] === 'all' || $filters['source'] === 'banks') && ! $filters['payment_method_id']) {
            $sources = $sources->merge($this->manualBankTransactions($request, $filters));
        }

        return $sources
            ->sortByDesc('date')
            ->values();
    }

    private function salePayments(Request $request, array $filters): Collection
    {
        return $this->basePaymentQuery(SalePayment::query(), $request, $filters, 'paid_at')
            ->with(['branch:id,name', 'user:id,name', 'method:id,name,code', 'sale:id,receipt_number,customer_name,total,balance_due,status'])
            ->get()
            ->map(fn (SalePayment $payment) => [
                'key' => 'sale-'.$payment->id,
                'date' => $payment->paid_at?->toIso8601String(),
                'type' => self::TYPE_INCOME,
                'source' => 'sales',
                'source_label' => 'Cobro cliente',
                'document' => $payment->sale?->receipt_number ?? 'Pago '.$payment->id,
                'description' => $payment->sale?->customer_name ?? 'Cliente ocasional',
                'branch_id' => $payment->branch_id,
                'branch_name' => $payment->branch?->name ?? 'Sin sucursal',
                'method_id' => $payment->payment_method_id,
                'method_name' => $payment->method?->name ?? 'Sin metodo',
                'user_name' => $payment->user?->name ?? '-',
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'amount' => (float) ($payment->amount_bob ?: $payment->amount),
                'signed_amount' => (float) ($payment->amount_bob ?: $payment->amount),
                'related_url' => $payment->sale_id ? route('sales.show', $payment->sale_id) : null,
            ]);
    }

    private function purchasePayments(Request $request, array $filters): Collection
    {
        return $this->basePaymentQuery(PurchasePayment::query(), $request, $filters, 'paid_at')
            ->with(['branch:id,name', 'user:id,name', 'method:id,name,code', 'purchase:id,document_number,supplier_id,total_amount,balance_due'])
            ->get()
            ->map(fn (PurchasePayment $payment) => [
                'key' => 'purchase-'.$payment->id,
                'date' => $payment->paid_at?->toIso8601String(),
                'type' => self::TYPE_EXPENSE,
                'source' => 'purchases',
                'source_label' => 'Pago proveedor',
                'document' => $payment->purchase?->document_number ?? 'Compra '.$payment->purchase_id,
                'description' => 'Pago de compra',
                'branch_id' => $payment->branch_id,
                'branch_name' => $payment->branch?->name ?? 'Sin sucursal',
                'method_id' => $payment->payment_method_id,
                'method_name' => $payment->method?->name ?? 'Sin metodo',
                'user_name' => $payment->user?->name ?? '-',
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'amount' => (float) $payment->amount,
                'signed_amount' => -1 * (float) $payment->amount,
                'related_url' => $payment->purchase_id ? route('purchases.show', $payment->purchase_id) : null,
            ]);
    }

    private function expenses(Request $request, array $filters): Collection
    {
        return $this->basePaymentQuery(Expense::query(), $request, $filters, 'spent_at')
            ->where('status', Expense::STATUS_REGISTERED)
            ->with(['branch:id,name', 'user:id,name', 'paymentMethod:id,name,code', 'category:id,name,code'])
            ->get()
            ->map(fn (Expense $expense) => [
                'key' => 'expense-'.$expense->id,
                'date' => $expense->spent_at?->toIso8601String(),
                'type' => self::TYPE_EXPENSE,
                'source' => 'expenses',
                'source_label' => $expense->category?->name ?? 'Gasto',
                'document' => $expense->reference ?: 'Gasto '.$expense->id,
                'description' => $expense->description,
                'branch_id' => $expense->branch_id,
                'branch_name' => $expense->branch?->name ?? 'Sin sucursal',
                'method_id' => $expense->payment_method_id,
                'method_name' => $expense->paymentMethod?->name ?? 'Sin metodo',
                'user_name' => $expense->user?->name ?? '-',
                'reference' => $expense->reference,
                'notes' => $expense->notes,
                'amount' => (float) $expense->amount,
                'signed_amount' => -1 * (float) $expense->amount,
                'related_url' => route('expenses.index', ['expense_category_id' => $expense->expense_category_id]),
            ]);
    }

    private function manualBankTransactions(Request $request, array $filters): Collection
    {
        return $this->basePaymentQuery(BankTransaction::query(), $request, $filters, 'transacted_at')
            ->where('status', BankTransaction::STATUS_REGISTERED)
            ->whereNull('source_type')
            ->whereIn('type', [BankTransaction::TYPE_DEPOSIT, BankTransaction::TYPE_WITHDRAWAL])
            ->with(['branch:id,name', 'user:id,name', 'account:id,name,account_number,currency_code'])
            ->get()
            ->map(function (BankTransaction $transaction) {
                $isIncome = $transaction->type === BankTransaction::TYPE_DEPOSIT;

                return [
                    'key' => 'bank-'.$transaction->id,
                    'date' => $transaction->transacted_at?->toIso8601String(),
                    'type' => $isIncome ? self::TYPE_INCOME : self::TYPE_EXPENSE,
                    'source' => 'banks',
                    'source_label' => $isIncome ? 'Deposito bancario manual' : 'Retiro bancario manual',
                    'document' => $transaction->reference ?: 'Movimiento banco '.$transaction->id,
                    'description' => $transaction->description ?: ($transaction->account?->name ?? 'Banco'),
                    'branch_id' => $transaction->branch_id,
                    'branch_name' => $transaction->branch?->name ?? 'Sin sucursal',
                    'method_id' => null,
                    'method_name' => $transaction->account?->name ?? 'Banco',
                    'user_name' => $transaction->user?->name ?? '-',
                    'reference' => $transaction->reference,
                    'notes' => $transaction->void_reason,
                    'amount' => (float) $transaction->amount,
                    'signed_amount' => $isIncome ? (float) $transaction->amount : -1 * (float) $transaction->amount,
                    'related_url' => route('banks.index', ['bank_account_id' => $transaction->bank_account_id]),
                ];
            });
    }

    private function basePaymentQuery(Builder $query, Request $request, array $filters, string $dateColumn): Builder
    {
        return $query
            ->when(true, fn (Builder $builder) => BranchAccess::apply($builder, $request->user()))
            ->when($filters['branch_id'], fn (Builder $builder) => $builder->where('branch_id', $filters['branch_id']))
            ->when($filters['payment_method_id'] && $dateColumn !== 'transacted_at', fn (Builder $builder) => $builder->where('payment_method_id', $filters['payment_method_id']))
            ->whereBetween($dateColumn, [
                Carbon::parse($filters['from_at'])->startOfDay(),
                Carbon::parse($filters['to_at'])->endOfDay(),
            ]);
    }

    private function applyCollectionFilters(Collection $movements, array $filters): Collection
    {
        return $movements
            ->when($filters['type'] !== 'all', fn (Collection $items) => $items->where('type', $filters['type']))
            ->when($filters['search'] !== '', fn (Collection $items) => $items->filter(function (array $movement) use ($filters) {
                $needle = mb_strtolower($filters['search']);

                return str_contains(mb_strtolower((string) $movement['document']), $needle)
                    || str_contains(mb_strtolower((string) $movement['description']), $needle)
                    || str_contains(mb_strtolower((string) $movement['reference']), $needle);
            }))
            ->when($filters['smart_filter'] === 'high_value', fn (Collection $items) => $items->filter(fn (array $movement) => $movement['amount'] >= 1000))
            ->when($filters['smart_filter'] === 'without_reference', fn (Collection $items) => $items->filter(fn (array $movement) => blank($movement['reference'])))
            ->values();
    }

    private function summary(Collection $movements): array
    {
        $income = (float) $movements->where('type', self::TYPE_INCOME)->sum('amount');
        $expense = (float) $movements->where('type', self::TYPE_EXPENSE)->sum('amount');

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($income - $expense, 2),
            'count' => $movements->count(),
            'income_count' => $movements->where('type', self::TYPE_INCOME)->count(),
            'expense_count' => $movements->where('type', self::TYPE_EXPENSE)->count(),
            'average_income' => round($movements->where('type', self::TYPE_INCOME)->avg('amount') ?? 0, 2),
            'average_expense' => round($movements->where('type', self::TYPE_EXPENSE)->avg('amount') ?? 0, 2),
        ];
    }

    private function groupBy(Collection $movements, string $key): array
    {
        return $movements
            ->groupBy(fn (array $movement) => $movement[$key] ?: 'Sin dato')
            ->map(function (Collection $items, string $label) {
                $income = (float) $items->where('type', self::TYPE_INCOME)->sum('amount');
                $expense = (float) $items->where('type', self::TYPE_EXPENSE)->sum('amount');

                return [
                    'label' => $this->sourceLabel($label),
                    'income' => round($income, 2),
                    'expense' => round($expense, 2),
                    'net' => round($income - $expense, 2),
                    'count' => $items->count(),
                ];
            })
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    private function paginate(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max((int) $request->integer('page', 1), 1);

        return new Paginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    private function sourceLabel(string $source): string
    {
        return [
            'sales' => 'Cobros de clientes',
            'purchases' => 'Pagos a proveedores',
            'expenses' => 'Gastos',
            'banks' => 'Bancos manuales',
        ][$source] ?? $source;
    }
}
