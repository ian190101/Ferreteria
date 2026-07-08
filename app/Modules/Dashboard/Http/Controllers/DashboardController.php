<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const CACHE_SECONDS = 30;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = now();
        $from = $this->dateFromRequest($request, 'from', $today->copy()->subDays(6))->startOfDay();
        $to = $this->dateFromRequest($request, 'to', $today)->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $branches = UiCatalogCache::activeBranchesForUser($user);
        $allowedBranchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        abort_if($branchId && ! in_array($branchId, $allowedBranchIds, true), 403);

        $baseCacheKey = sprintf(
            'dashboard:base:v6:%s:%s:%s:%s:%s',
            SystemCacheInvalidator::operationalVersion(),
            $user->id,
            $branchId ?? 'all',
            $from->toDateString(),
            $to->toDateString(),
        );

        $payload = Cache::remember($baseCacheKey, now()->addSeconds(self::CACHE_SECONDS), function () use ($user, $branchId, $allowedBranchIds, $from, $to, $today, $branches) {
            return [
                'scope' => [
                    'branch_id' => $branchId,
                    'label' => $branchId
                        ? ($branches->firstWhere('id', $branchId)?->name ?? 'Sucursal seleccionada')
                        : 'Todas las sucursales permitidas',
                    'date' => $today->toDateString(),
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'metrics' => $this->metrics($user, $branchId, $allowedBranchIds, $from, $to, $today),
            ];
        });

        $payload['recentSales'] = Inertia::defer(
            fn () => Cache::remember($this->sectionCacheKey('recent-sales', $user->id, $branchId, $from, $to), now()->addSeconds(self::CACHE_SECONDS), fn () => $this->recentSales($user, $branchId, $allowedBranchIds, $from, $to)),
            'dashboard-lists',
        );
        $payload['pendingReceivables'] = Inertia::defer(
            fn () => Cache::remember($this->sectionCacheKey('pending-receivables', $user->id, $branchId, $from, $to), now()->addSeconds(self::CACHE_SECONDS), fn () => $this->pendingReceivables($user, $branchId, $allowedBranchIds, $from, $to)),
            'dashboard-lists',
        );
        $payload['lowStocks'] = Inertia::defer(
            fn () => Cache::remember($this->sectionCacheKey('low-stocks', $user->id, $branchId, $from, $to), now()->addSeconds(self::CACHE_SECONDS), fn () => $this->lowStocks($user, $branchId, $allowedBranchIds)),
            'dashboard-lists',
        );
        $payload['openCashSessions'] = Inertia::defer(
            fn () => Cache::remember($this->sectionCacheKey('open-cash', $user->id, $branchId, $from, $to), now()->addSeconds(self::CACHE_SECONDS), fn () => $this->openCashSessions($user, $branchId, $allowedBranchIds)),
            'dashboard-lists',
        );
        $payload['charts'] = Inertia::defer(
            fn () => Cache::remember($this->sectionCacheKey('charts', $user->id, $branchId, $from, $to), now()->addSeconds(self::CACHE_SECONDS), fn () => $this->charts($user, $branchId, $allowedBranchIds, $from, $to, $today)),
            'dashboard-charts',
        );
        $payload['branches'] = $branches;
        $payload['filters'] = [
            'branch_id' => $branchId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        return Inertia::render('Dashboard/Index', $payload);
    }

    private function metrics($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to, Carbon $today): array
    {
        return [
            'sales_range_total' => $user->can('sales.view') ? (float) $this->salesQuery($branchId, $branchIds)->whereBetween('sold_at', [$from, $to])->sum('total') : null,
            'sales_range_count' => $user->can('sales.view') ? $this->salesQuery($branchId, $branchIds)->whereBetween('sold_at', [$from, $to])->count() : null,
            'receivables_total' => $user->can('payments.view') ? (float) $this->receivablesQuery($branchId, $branchIds)->sum('balance_due') : null,
            'receivables_count' => $user->can('payments.view') ? $this->receivablesQuery($branchId, $branchIds)->count() : null,
            'open_cash_count' => $user->can('cash.view') ? $this->cashQuery($branchId, $branchIds)->count() : null,
            'payment_promises_overdue_count' => $user->can('payment-promises.view') ? $this->paymentPromisesQuery($branchId, $branchIds)->whereDate('promised_date', '<', $today)->count() : null,
            'payment_promises_today_count' => $user->can('payment-promises.view') ? $this->paymentPromisesQuery($branchId, $branchIds)->whereDate('promised_date', $today)->count() : null,
            'low_stock_count' => $user->can('inventory.products.view') ? $this->lowStockQuery($branchId, $branchIds)->count() : null,
            'active_coils' => $user->can('inventory.coils.manage') ? $this->coilQuery($branchId, $branchIds)->where('status', 'available')->count() : null,
            'production_range_count' => $user->can('production.view') ? $this->productionQuery($branchId, $branchIds)->whereBetween('produced_at', [$from, $to])->count() : null,
            'purchases_range_total' => $user->can('purchases.view') ? (float) $this->purchaseQuery($branchId, $branchIds)->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])->sum('total_amount') : null,
            'purchase_payments_range_total' => $user->can('purchases.view') ? (float) $this->purchasePaymentsQuery($branchId, $branchIds)->whereBetween('paid_at', [$from, $to])->sum('amount') : null,
            'expenses_range_total' => $user->can('expenses.view') ? (float) $this->expenseQuery($branchId, $branchIds)->whereBetween('spent_at', [$from, $to])->sum('amount') : null,
            'profit_range_total' => $user->can('payments.view') || $user->can('purchases.view') || $user->can('expenses.view') ? $this->profitForRange($user, $branchId, $branchIds, $from, $to) : null,
        ];
    }

    private function recentSales($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to)
    {
        if (! $user->can('sales.view')) {
            return [];
        }

        return $this->salesQuery($branchId, $branchIds)
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->whereBetween('sold_at', [$from, $to])
            ->latest('sold_at')
            ->limit(6)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'document_type', 'customer_name', 'sold_at', 'total', 'status']);
    }

    private function pendingReceivables($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to)
    {
        if (! $user->can('payments.view')) {
            return [];
        }

        return $this->receivablesQuery($branchId, $branchIds)
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->whereBetween('sold_at', [$from, $to])
            ->orderByDesc('balance_due')
            ->limit(6)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'customer_name', 'sold_at', 'balance_due', 'total', 'status']);
    }

    private function lowStocks($user, ?int $branchId, array $branchIds)
    {
        if (! $user->can('inventory.products.view')) {
            return [];
        }

        return $this->lowStockQuery($branchId, $branchIds)
            ->with(['branch:id,name', 'product:id,name,sku,minimum_stock_meters,base_unit'])
            ->orderBy('available_meters')
            ->limit(6)
            ->get(['product_branch_stocks.id', 'product_branch_stocks.branch_id', 'product_branch_stocks.product_id', 'product_branch_stocks.available_meters', 'product_branch_stocks.reserved_meters']);
    }

    private function openCashSessions($user, ?int $branchId, array $branchIds)
    {
        if (! $user->can('cash.view')) {
            return [];
        }

        return $this->cashQuery($branchId, $branchIds)
            ->with(['branch:id,name', 'opener:id,name'])
            ->latest('opened_at')
            ->limit(4)
            ->get(['id', 'branch_id', 'opened_by', 'opened_at', 'opening_amount', 'expected_cash_amount', 'status']);
    }

    private function charts($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to, Carbon $today): array
    {
        return [
            'salesTrend' => $user->can('sales.view') ? $this->salesTrend($branchId, $branchIds, $from, $to) : [],
            'stockByProduct' => $user->can('inventory.products.view') ? $this->stockByProduct($branchId, $branchIds) : [],
            'topProducts' => $user->can('sales.view') ? $this->topProducts($branchId, $branchIds, $from, $to) : [],
            'cashFlowTrend' => $user->can('purchases.view') || $user->can('expenses.view') ? $this->cashFlowTrend($user, $branchId, $branchIds, $from, $to) : [],
            'incomeExpenseProfitTrend' => $user->can('payments.view') || $user->can('purchases.view') || $user->can('expenses.view') ? $this->incomeExpenseProfitTrend($user, $branchId, $branchIds, $from, $to) : [],
            'receivablesAging' => $user->can('payments.view') ? $this->receivablesAging($branchId, $branchIds, $today) : [],
            'cashProfitByBranchDay' => $user->can('payments.view') || $user->can('purchases.view') || $user->can('expenses.view') ? $this->cashProfitByBranchDay($user, $branchId, $branchIds, $from, $to) : [],
            'profitByBranch' => $user->can('payments.view') || $user->can('purchases.view') || $user->can('expenses.view') ? $this->profitByBranch($user, $branchId, $branchIds, $from, $to) : [],
        ];
    }

    private function salesTrend(?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $rows = $this->salesQuery($branchId, $branchIds)
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw('DATE(sold_at) as date, SUM(total * exchange_rate_to_bob) as total, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('total', 'date');

        return $this->dateBuckets($from, $to)->map(function (Carbon $date) use ($rows) {

            return [
                'label' => $date->format('d/m'),
                'date' => $date->toDateString(),
                'value' => round((float) ($rows[$date->toDateString()] ?? 0), 2),
            ];
        })->all();
    }

    private function stockByProduct(?int $branchId, array $branchIds): array
    {
        $stockRows = ProductBranchStock::query()
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id')
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'product_branch_stocks.branch_id'))
            ->leftJoin('product_units', 'products.product_unit_id', '=', 'product_units.id')
            ->groupBy('products.id', 'products.name', 'products.base_unit', 'product_units.symbol')
            ->get([
                DB::raw('products.id as product_id'),
                DB::raw('products.name as label'),
                DB::raw('products.base_unit as base_unit'),
                DB::raw('product_units.symbol as unit_symbol'),
                DB::raw('SUM(product_branch_stocks.available_meters) as value'),
            ]);

        $coilRows = ProductCoil::query()
            ->join('products', 'product_coils.product_id', '=', 'products.id')
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'product_coils.branch_id'))
            ->where('status', 'available')
            ->leftJoin('product_units', 'products.product_unit_id', '=', 'product_units.id')
            ->groupBy('products.id', 'products.name', 'products.base_unit', 'product_units.symbol')
            ->get([
                DB::raw('products.id as product_id'),
                DB::raw('products.name as label'),
                DB::raw('products.base_unit as base_unit'),
                DB::raw('product_units.symbol as unit_symbol'),
                DB::raw('SUM(product_coils.available_meters) as value'),
            ]);

        return $stockRows
            ->concat($coilRows)
            ->groupBy('product_id')
            ->map(function ($rows) {
                return [
                    'label' => $rows->first()->label ?? 'Producto',
                    'unit' => $rows->first()->unit_symbol ?: $this->unitLabel($rows->first()->base_unit),
                    'value' => round((float) $rows->sum('value'), 3),
                ];
            })
            ->sortByDesc('value')
            ->take(8)
            ->values()
            ->all();
    }

    private function topProducts(?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'void')
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'sales.branch_id'))
            ->whereBetween('sales.sold_at', [$from, $to])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc(DB::raw('SUM(sale_items.meters)'))
            ->limit(6)
            ->get([
                DB::raw('products.name as label'),
                DB::raw('ROUND(SUM(sale_items.meters), 3) as value'),
            ])
            ->map(fn ($row) => ['label' => $row->label, 'value' => (float) $row->value])
            ->all();
    }

    private function cashFlowTrend($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $purchases = $user->can('purchases.view')
            ? $this->purchasePaymentsQuery($branchId, $branchIds)
                ->whereBetween('paid_at', [$from, $to])
                ->selectRaw('DATE(paid_at) as date, SUM(amount) as total')
                ->groupBy('date')
                ->pluck('total', 'date')
            : collect();
        $expenses = $user->can('expenses.view')
            ? $this->expenseQuery($branchId, $branchIds)
                ->whereBetween('spent_at', [$from, $to])
                ->selectRaw('DATE(spent_at) as date, SUM(amount) as total')
                ->groupBy('date')
                ->pluck('total', 'date')
            : collect();

        return $this->dateBuckets($from, $to)->map(function (Carbon $date) use ($purchases, $expenses) {
            $key = $date->toDateString();

            return [
                'label' => $date->format('d/m'),
                'purchases' => round((float) ($purchases[$key] ?? 0), 2),
                'expenses' => round((float) ($expenses[$key] ?? 0), 2),
            ];
        })->all();
    }

    private function receivablesAging(?int $branchId, array $branchIds, Carbon $today): array
    {
        $buckets = [
            '0-7 dias' => [0, 7],
            '8-30 dias' => [8, 30],
            '31+ dias' => [31, null],
        ];

        return collect($buckets)->map(function (array $range, string $label) use ($branchId, $branchIds, $today) {
            [$from, $to] = $range;
            $query = $this->receivablesQuery($branchId, $branchIds);

            if ($to === null) {
                $query->whereDate('sold_at', '<=', $today->copy()->subDays($from)->toDateString());
            } else {
                $query->whereBetween('sold_at', [
                    $today->copy()->subDays($to)->startOfDay(),
                    $today->copy()->subDays($from)->endOfDay(),
                ]);
            }

            return [
                'label' => $label,
                'value' => round((float) $query->sum('balance_due'), 2),
            ];
        })->values()->all();
    }

    private function profitForRange($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to): float
    {
        $income = $user->can('payments.view')
            ? DB::table('sale_payments')
                ->whereNull('sale_payments.deleted_at')
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'sale_payments.branch_id'))
                ->whereBetween('sale_payments.paid_at', [$from, $to])
                ->sum('sale_payments.amount_bob')
            : 0;

        $expenses = $user->can('expenses.view')
            ? DB::table('expenses')
                ->whereNull('expenses.deleted_at')
                ->where('expenses.status', Expense::STATUS_REGISTERED)
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'expenses.branch_id'))
                ->whereBetween('expenses.spent_at', [$from, $to])
                ->sum('expenses.amount')
            : 0;

        $purchasePayments = $user->can('purchases.view')
            ? DB::table('purchase_payments')
                ->whereNull('purchase_payments.deleted_at')
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'purchase_payments.branch_id'))
                ->whereBetween('purchase_payments.paid_at', [$from, $to])
                ->sum('purchase_payments.amount')
            : 0;

        return round((float) $income - (float) $purchasePayments - (float) $expenses, 2);
    }

    private function incomeExpenseProfitTrend($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $income = $user->can('payments.view')
            ? DB::table('sale_payments')
                ->whereNull('sale_payments.deleted_at')
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'sale_payments.branch_id'))
                ->whereBetween('sale_payments.paid_at', [$from, $to])
                ->selectRaw('DATE(paid_at) as date, SUM(amount_bob) as total')
                ->groupBy('date')
                ->pluck('total', 'date')
            : collect();

        $expenses = $user->can('expenses.view')
            ? DB::table('expenses')
                ->whereNull('expenses.deleted_at')
                ->where('expenses.status', Expense::STATUS_REGISTERED)
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'expenses.branch_id'))
                ->whereBetween('expenses.spent_at', [$from, $to])
                ->selectRaw('DATE(spent_at) as date, SUM(amount) as total')
                ->groupBy('date')
                ->pluck('total', 'date')
            : collect();

        $purchases = $user->can('purchases.view')
            ? DB::table('purchase_payments')
                ->whereNull('purchase_payments.deleted_at')
                ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'purchase_payments.branch_id'))
                ->whereBetween('purchase_payments.paid_at', [$from, $to])
                ->selectRaw('DATE(paid_at) as date, SUM(amount) as total')
                ->groupBy('date')
                ->pluck('total', 'date')
            : collect();

        return $this->dateBuckets($from, $to)->map(function (Carbon $date) use ($income, $expenses, $purchases) {
            $key = $date->toDateString();
            $incomeAmount = round((float) ($income[$key] ?? 0), 2);
            $expenseAmount = round((float) ($expenses[$key] ?? 0), 2);
            $purchaseAmount = round((float) ($purchases[$key] ?? 0), 2);
            $outflowAmount = round($purchaseAmount + $expenseAmount, 2);

            return [
                'label' => $date->format('d/m'),
                'income' => $incomeAmount,
                'purchases' => $purchaseAmount,
                'expenses' => $expenseAmount,
                'outflows' => $outflowAmount,
                'profit' => round($incomeAmount - $outflowAmount, 2),
            ];
        })->all();
    }

    private function cashProfitByBranchDay($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $incomeRows = DB::table('sale_payments')
            ->join('branches', 'sale_payments.branch_id', '=', 'branches.id')
            ->whereNull('sale_payments.deleted_at')
            ->when(! $user->can('payments.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'sale_payments.branch_id'))
            ->whereBetween('sale_payments.paid_at', [$from, $to])
            ->groupBy('sale_payments.branch_id', 'branches.name', DB::raw('DATE(sale_payments.paid_at)'))
            ->get([
                DB::raw('DATE(sale_payments.paid_at) as date'),
                DB::raw('sale_payments.branch_id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('SUM(sale_payments.amount_bob) as income'),
            ]);

        $expenseRows = DB::table('expenses')
            ->join('branches', 'expenses.branch_id', '=', 'branches.id')
            ->whereNull('expenses.deleted_at')
            ->where('expenses.status', Expense::STATUS_REGISTERED)
            ->when(! $user->can('expenses.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'expenses.branch_id'))
            ->whereBetween('expenses.spent_at', [$from, $to])
            ->groupBy('expenses.branch_id', 'branches.name', DB::raw('DATE(expenses.spent_at)'))
            ->get([
                DB::raw('DATE(expenses.spent_at) as date'),
                DB::raw('expenses.branch_id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('SUM(expenses.amount) as expenses'),
            ]);

        $purchaseRows = DB::table('purchase_payments')
            ->join('branches', 'purchase_payments.branch_id', '=', 'branches.id')
            ->whereNull('purchase_payments.deleted_at')
            ->when(! $user->can('purchases.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'purchase_payments.branch_id'))
            ->whereBetween('purchase_payments.paid_at', [$from, $to])
            ->groupBy('purchase_payments.branch_id', 'branches.name', DB::raw('DATE(purchase_payments.paid_at)'))
            ->get([
                DB::raw('DATE(purchase_payments.paid_at) as date'),
                DB::raw('purchase_payments.branch_id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('SUM(purchase_payments.amount) as purchases'),
            ]);

        $rows = [];

        foreach ($incomeRows as $row) {
            $key = "{$row->date}:{$row->branch_id}";
            $rows[$key] = [
                'date' => $row->date,
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'income' => round((float) $row->income, 2),
                'purchases' => 0.0,
                'expenses' => 0.0,
            ];
        }

        foreach ($expenseRows as $row) {
            $key = "{$row->date}:{$row->branch_id}";
            $rows[$key] ??= [
                'date' => $row->date,
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'income' => 0.0,
                'purchases' => 0.0,
                'expenses' => 0.0,
            ];
            $rows[$key]['expenses'] = round((float) $row->expenses, 2);
        }

        foreach ($purchaseRows as $row) {
            $key = "{$row->date}:{$row->branch_id}";
            $rows[$key] ??= [
                'date' => $row->date,
                'branch_id' => (int) $row->branch_id,
                'branch_name' => $row->branch_name,
                'income' => 0.0,
                'purchases' => 0.0,
                'expenses' => 0.0,
            ];
            $rows[$key]['purchases'] = round((float) $row->purchases, 2);
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['outflows'] = round($row['purchases'] + $row['expenses'], 2);
                $row['profit'] = round($row['income'] - $row['outflows'], 2);

                return $row;
            })
            ->sortByDesc(fn (array $row) => $row['date'].'-'.$row['branch_name'])
            ->values()
            ->take(50)
            ->all();
    }

    private function profitByBranch($user, ?int $branchId, array $branchIds, Carbon $from, Carbon $to): array
    {
        $incomeRows = DB::table('branches')
            ->leftJoin('sale_payments', function ($join) use ($from, $to) {
                $join->on('branches.id', '=', 'sale_payments.branch_id')
                    ->whereNull('sale_payments.deleted_at')
                    ->whereBetween('sale_payments.paid_at', [$from, $to]);
            })
            ->when(! $user->can('payments.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'branches.id'))
            ->groupBy('branches.id', 'branches.name')
            ->get([
                DB::raw('branches.id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('COALESCE(SUM(sale_payments.amount_bob), 0) as income'),
            ])
            ->keyBy('branch_id');

        $expenseRows = DB::table('branches')
            ->leftJoin('expenses', function ($join) use ($from, $to) {
                $join->on('branches.id', '=', 'expenses.branch_id')
                    ->whereNull('expenses.deleted_at')
                    ->where('expenses.status', Expense::STATUS_REGISTERED)
                    ->whereBetween('expenses.spent_at', [$from, $to]);
            })
            ->when(! $user->can('expenses.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'branches.id'))
            ->groupBy('branches.id', 'branches.name')
            ->get([
                DB::raw('branches.id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('COALESCE(SUM(expenses.amount), 0) as expenses'),
            ])
            ->keyBy('branch_id');

        $purchaseRows = DB::table('branches')
            ->leftJoin('purchase_payments', function ($join) use ($from, $to) {
                $join->on('branches.id', '=', 'purchase_payments.branch_id')
                    ->whereNull('purchase_payments.deleted_at')
                    ->whereBetween('purchase_payments.paid_at', [$from, $to]);
            })
            ->when(! $user->can('purchases.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'branches.id'))
            ->groupBy('branches.id', 'branches.name')
            ->get([
                DB::raw('branches.id as branch_id'),
                DB::raw('branches.name as branch_name'),
                DB::raw('COALESCE(SUM(purchase_payments.amount), 0) as purchases'),
            ])
            ->keyBy('branch_id');

        return collect($branchIds)
            ->when($branchId, fn ($ids) => $ids->filter(fn ($id) => (int) $id === $branchId))
            ->map(function (int $id) use ($incomeRows, $expenseRows, $purchaseRows) {
                $incomeRow = $incomeRows->get($id);
                $expenseRow = $expenseRows->get($id);
                $purchaseRow = $purchaseRows->get($id);
                $income = round((float) ($incomeRow?->income ?? 0), 2);
                $purchases = round((float) ($purchaseRow?->purchases ?? 0), 2);
                $expenses = round((float) ($expenseRow?->expenses ?? 0), 2);
                $outflows = round($purchases + $expenses, 2);

                return [
                    'branch_id' => $id,
                    'label' => $incomeRow?->branch_name ?? $expenseRow?->branch_name ?? $purchaseRow?->branch_name ?? 'Sucursal',
                    'income' => $income,
                    'purchases' => $purchases,
                    'expenses' => $expenses,
                    'outflows' => $outflows,
                    'profit' => round($income - $outflows, 2),
                ];
            })
            ->filter(fn (array $row) => $row['income'] > 0 || $row['outflows'] > 0 || $row['profit'] !== 0.0)
            ->sortByDesc('profit')
            ->values()
            ->all();
    }

    private function salesQuery(?int $branchId, array $branchIds): Builder
    {
        return Sale::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds))
            ->where('status', '!=', 'void');
    }

    private function receivablesQuery(?int $branchId, array $branchIds): Builder
    {
        return Sale::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds))
            ->where('document_type', 'sale_note')
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('balance_due', '>', 0);
    }

    private function cashQuery(?int $branchId, array $branchIds): Builder
    {
        return CashRegisterSession::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds))
            ->where('status', CashRegisterSession::STATUS_OPEN);
    }

    private function paymentPromisesQuery(?int $branchId, array $branchIds): Builder
    {
        return PaymentPromise::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds))
            ->where('status', PaymentPromise::STATUS_PENDING);
    }

    private function lowStockQuery(?int $branchId, array $branchIds): Builder
    {
        return ProductBranchStock::query()
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id')
            ->whereColumn('product_branch_stocks.available_meters', '<=', 'products.minimum_stock_meters')
            ->select('product_branch_stocks.*')
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds, 'product_branch_stocks.branch_id'));
    }

    private function coilQuery(?int $branchId, array $branchIds): Builder
    {
        return ProductCoil::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds));
    }

    private function productionQuery(?int $branchId, array $branchIds): Builder
    {
        return ProductionOrder::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds));
    }

    private function purchaseQuery(?int $branchId, array $branchIds): Builder
    {
        return Purchase::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds));
    }

    private function purchasePaymentsQuery(?int $branchId, array $branchIds): Builder
    {
        return PurchasePayment::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds));
    }

    private function expenseQuery(?int $branchId, array $branchIds): Builder
    {
        return Expense::query()
            ->when(true, fn ($query) => $this->applyBranchScope($query, $branchId, $branchIds))
            ->where('status', Expense::STATUS_REGISTERED);
    }

    private function applyBranchScope($query, ?int $branchId, array $branchIds, string $column = 'branch_id')
    {
        if ($branchId) {
            return $query->where($column, $branchId);
        }

        return $query->whereIn($column, $branchIds ?: [-1]);
    }

    private function unitLabel(?string $baseUnit): string
    {
        return match ($baseUnit) {
            'unit' => 'unid.',
            'kg' => 'kg',
            'lb' => 'lb',
            default => 'm',
        };
    }

    private function dateBuckets(Carbon $from, Carbon $to)
    {
        $days = (int) max(1, min($from->diffInDays($to) + 1, 62));

        return collect(range(0, $days - 1))->map(fn (int $offset) => $from->copy()->addDays($offset));
    }

    private function dateFromRequest(Request $request, string $key, Carbon $fallback): Carbon
    {
        try {
            return Carbon::parse($request->input($key, $fallback->toDateString()));
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function sectionCacheKey(string $section, int $userId, ?int $branchId, Carbon $from, Carbon $to): string
    {
        return sprintf('dashboard:%s:v6:%s:%s:%s:%s:%s', $section, SystemCacheInvalidator::operationalVersion(), $userId, $branchId ?? 'all', $from->toDateString(), $to->toDateString());
    }
}
