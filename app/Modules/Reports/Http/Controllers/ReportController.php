<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\BranchAccess;
use App\Support\SystemCacheInvalidator;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId && ! BranchAccess::canAccess($user, $branchId), 403);

        $cacheKey = sprintf(
            'reports:dashboard:v4:%s:%s:%s:%s:%s:%s',
            SystemCacheInvalidator::operationalVersion(),
            $this->financialVersion(),
            $user->id,
            $branchId ?? 'all',
            $from->toDateString(),
            $to->toDateString(),
        );

        $profileFeatures = [
            'sales' => ActiveBusinessProfile::enabled('quotes') || ActiveBusinessProfile::enabled('sales_notes') || ActiveBusinessProfile::enabled('pos'),
            'quotes' => ActiveBusinessProfile::enabled('quotes'),
            'purchases' => ActiveBusinessProfile::enabled('purchases'),
            'expenses' => ActiveBusinessProfile::enabled('expenses'),
            'inventory' => ActiveBusinessProfile::enabled('inventory'),
            'inventory_lots' => ActiveBusinessProfile::enabled('inventory')
                && (bool) (ActiveBusinessProfile::payload()['inventory']['lot_tracking_optional'] ?? false),
            'payments' => ActiveBusinessProfile::enabled('sales_notes'),
        ];

        $metrics = Cache::remember($cacheKey.':'.md5(json_encode($profileFeatures)), now()->addSeconds($this->cacheSeconds()), function () use ($from, $to, $branchId, $profileFeatures) {
            return [
                'sales_total' => $profileFeatures['sales'] ? (float) $this->salesQuery($from, $to, $branchId)->sum('total') : 0.0,
                'sales_count' => $profileFeatures['sales'] ? $this->salesQuery($from, $to, $branchId)->count() : 0,
                'quotations_count' => $profileFeatures['quotes'] ? $this->salesQuery($from, $to, $branchId)->where('document_type', 'quotation')->count() : 0,
                'purchase_total' => $profileFeatures['purchases'] ? (float) $this->purchaseQuery($from, $to, $branchId)->sum('total_amount') : 0.0,
                'purchase_count' => $profileFeatures['purchases'] ? $this->purchaseQuery($from, $to, $branchId)->count() : 0,
                'expense_total' => $profileFeatures['expenses'] ? (float) $this->expenseQuery($from, $to, $branchId)->sum('amount') : 0.0,
                'expense_count' => $profileFeatures['expenses'] ? $this->expenseQuery($from, $to, $branchId)->count() : 0,
                'active_coils' => $profileFeatures['inventory_lots'] ? $this->coilQuery($branchId)->where('status', 'available')->count() : 0,
                'low_stock_count' => $profileFeatures['inventory'] ? $this->lowStockQuery($branchId)->count() : 0,
                'gross_profit' => $profileFeatures['sales'] ? $this->grossProfit($from, $to, $branchId) : 0.0,
                'gross_margin_percent' => $profileFeatures['sales'] ? $this->grossMarginPercent($from, $to, $branchId) : 0.0,
            ];
        });

        return Inertia::render('Reports/Index', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch_id' => $branchId,
            ],
            'branches' => UiCatalogCache::activeBranchesForUser($request->user()),
            'metrics' => $metrics,
            'profileFeatures' => $profileFeatures,
            'recentSales' => Inertia::defer(fn () => $profileFeatures['sales'] ? Cache::remember($this->sectionCacheKey('recent-sales', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->recentSales($from, $to, $branchId)) : collect(), 'reports-lists'),
            'lowStocks' => Inertia::defer(fn () => $profileFeatures['inventory'] ? Cache::remember($this->sectionCacheKey('low-stocks', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->lowStocks($branchId)) : collect(), 'reports-lists'),
            'agingBuckets' => Inertia::defer(fn () => $profileFeatures['payments'] ? Cache::remember($this->sectionCacheKey('aging-buckets', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->agingBuckets($branchId)) : $this->emptyAgingBuckets(), 'reports-lists'),
            'agingReceivables' => Inertia::defer(fn () => $profileFeatures['payments'] ? $this->agingReceivables($branchId, $request) : Sale::query()->whereRaw('1 = 0')->paginate($request->integer('aging_per_page', 10), ['id'], 'aging_page'), 'reports-lists'),
            'latestMovements' => Inertia::defer(fn () => $profileFeatures['inventory_lots'] ? Cache::remember($this->sectionCacheKey('latest-movements', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->latestMovements($request, $branchId)) : collect(), 'reports-lists'),
            'profitByProduct' => Inertia::defer(fn () => $profileFeatures['sales'] ? Cache::remember($this->sectionCacheKey('profit-products', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->profitByProduct($from, $to, $branchId)) : collect(), 'reports-lists'),
            'profitBySeller' => Inertia::defer(fn () => $profileFeatures['sales'] ? Cache::remember($this->sectionCacheKey('profit-sellers', $user->id, $branchId, $from, $to), now()->addSeconds($this->cacheSeconds()), fn () => $this->profitBySeller($from, $to, $branchId)) : collect(), 'reports-lists'),
        ]);
    }

    private function recentSales(Carbon $from, Carbon $to, ?int $branchId)
    {
        return $this->salesQuery($from, $to, $branchId)
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->latest('sold_at')
            ->limit(8)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'document_type', 'customer_name', 'sold_at', 'total', 'status']);
    }

    private function lowStocks(?int $branchId)
    {
        return $this->lowStockQuery($branchId)
            ->with(['branch:id,name', 'product:id,name,sku,minimum_stock_meters,base_unit,product_unit_id', 'product.unit:id,name,symbol'])
            ->orderBy('available_meters')
            ->limit(10)
            ->get(['id', 'branch_id', 'product_id', 'available_meters', 'reserved_meters']);
    }

    private function latestMovements(Request $request, ?int $branchId)
    {
        return ProductCoil::query()
            ->with(['branch:id,name', 'product:id,name,sku,base_unit,product_unit_id', 'product.unit:id,name,symbol'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('id')
            ->limit(8)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters', 'status', 'created_at']);
    }

    private function sectionCacheKey(string $section, int $userId, ?int $branchId, Carbon $from, Carbon $to): string
    {
        return sprintf('reports:%s:v4:%s:%s:%s:%s:%s:%s', $section, SystemCacheInvalidator::operationalVersion(), $this->financialVersion(), $userId, $branchId ?? 'all', $from->toDateString(), $to->toDateString());
    }

    private function financialVersion(): string
    {
        return implode('-', [
            Cache::get('banks:summary_version', 1),
            Cache::get('expenses:summary_version', 1),
            Cache::get('cash:summary_version', 1),
        ]);
    }

    private function cacheSeconds(): int
    {
        return max(60, (int) config('performance.reports_cache_seconds', 180));
    }

    private function salesQuery(Carbon $from, Carbon $to, ?int $branchId)
    {
        return Sale::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('sold_at', [$from, $to]);
    }

    private function receivablesQuery(?int $branchId)
    {
        return Sale::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('document_type', 'sale_note')
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('balance_due', '>', 0);
    }

    private function agingBuckets(?int $branchId): array
    {
        $buckets = $this->emptyAgingBuckets();

        $this->receivablesQuery($branchId)
            ->get(['id', 'sold_at', 'balance_due'])
            ->each(function (Sale $sale) use (&$buckets) {
                $bucket = $this->agingBucketKey((int) $sale->sold_at->diffInDays(now()));
                $buckets[$bucket]['count']++;
                $buckets[$bucket]['total'] = round($buckets[$bucket]['total'] + (float) $sale->balance_due, 2);
            });

        return $buckets;
    }

    private function emptyAgingBuckets(): array
    {
        $buckets = [
            '0_7' => ['label' => '0 a 7 dias', 'count' => 0, 'total' => 0.0],
            '8_15' => ['label' => '8 a 15 dias', 'count' => 0, 'total' => 0.0],
            '16_30' => ['label' => '16 a 30 dias', 'count' => 0, 'total' => 0.0],
            '31_plus' => ['label' => '31 dias o mas', 'count' => 0, 'total' => 0.0],
        ];

        return $buckets;
    }

    private function agingReceivables(?int $branchId, Request $request)
    {
        return $this->receivablesQuery($branchId)
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->withMin(['paymentPromises as next_promise_date' => fn ($query) => $query->where('status', PaymentPromise::STATUS_PENDING)], 'promised_date')
            ->oldest('sold_at')
            ->paginate($request->integer('aging_per_page', 10), [
                'id',
                'branch_id',
                'currency_id',
                'receipt_number',
                'customer_name',
                'customer_contact',
                'sold_at',
                'total',
                'balance_due',
                'status',
            ], 'aging_page')
            ->withQueryString()
            ->through(function (Sale $sale) {
                $days = (int) $sale->sold_at->diffInDays(now());
                $sale->setAttribute('aging_days', $days);
                $sale->setAttribute('aging_bucket', $this->agingBucketKey($days));
                $sale->setAttribute('next_promise_date', $sale->next_promise_date ? Carbon::parse($sale->next_promise_date)->toDateString() : null);

                return $sale;
            });
    }

    private function agingBucketKey(int $days): string
    {
        if ($days <= 7) {
            return '0_7';
        }

        if ($days <= 15) {
            return '8_15';
        }

        if ($days <= 30) {
            return '16_30';
        }

        return '31_plus';
    }

    private function purchaseQuery(Carbon $from, Carbon $to, ?int $branchId)
    {
        return Purchase::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('purchase_date', '>=', $from->toDateString())
            ->whereDate('purchase_date', '<=', $to->toDateString());
    }

    private function coilQuery(?int $branchId)
    {
        return ProductCoil::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function expenseQuery(Carbon $from, Carbon $to, ?int $branchId)
    {
        return Expense::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', Expense::STATUS_REGISTERED)
            ->whereBetween('spent_at', [$from, $to]);
    }

    private function lowStockQuery(?int $branchId)
    {
        return ProductBranchStock::query()
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id')
            ->whereColumn('product_branch_stocks.available_meters', '<=', 'products.minimum_stock_meters')
            ->select('product_branch_stocks.*')
            ->when(true, fn ($query) => BranchAccess::apply($query, request()->user(), 'product_branch_stocks.branch_id'))
            ->when($branchId, fn ($query) => $query->where('product_branch_stocks.branch_id', $branchId));
    }

    private function profitBaseQuery(Carbon $from, Carbon $to, ?int $branchId)
    {
        $allowedBranchIds = request()->user()->isSuperAdministrator()
            ? null
            : request()->user()->accessibleBranchIds();

        return DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->where('sales.document_type', 'sale_note')
            ->whereNotIn('sales.status', ['voided', 'cancelled'])
            ->when($allowedBranchIds !== null, fn ($query) => $query->whereIn('sales.branch_id', $allowedBranchIds ?: [-1]))
            ->when($branchId, fn ($query) => $query->where('sales.branch_id', $branchId));
    }

    private function grossProfit(Carbon $from, Carbon $to, ?int $branchId): float
    {
        $row = (clone $this->profitBaseQuery($from, $to, $branchId))
            ->selectRaw('COALESCE(SUM(sale_items.total - (sale_items.meters * COALESCE(products.purchase_price, 0))), 0) as profit')
            ->first();

        return round((float) ($row->profit ?? 0), 2);
    }

    private function grossMarginPercent(Carbon $from, Carbon $to, ?int $branchId): float
    {
        $row = (clone $this->profitBaseQuery($from, $to, $branchId))
            ->selectRaw('COALESCE(SUM(sale_items.total), 0) as sales_total, COALESCE(SUM(sale_items.total - (sale_items.meters * COALESCE(products.purchase_price, 0))), 0) as profit')
            ->first();

        $salesTotal = (float) ($row->sales_total ?? 0);

        return $salesTotal > 0 ? round(((float) ($row->profit ?? 0) / $salesTotal) * 100, 2) : 0.0;
    }

    private function profitByProduct(Carbon $from, Carbon $to, ?int $branchId)
    {
        return (clone $this->profitBaseQuery($from, $to, $branchId))
            ->selectRaw('COALESCE(products.name, sale_items.description) as product_name, COALESCE(products.sku, "-") as sku, SUM(sale_items.total) as sales_total, SUM(sale_items.meters * COALESCE(products.purchase_price, 0)) as cost_total, SUM(sale_items.total - (sale_items.meters * COALESCE(products.purchase_price, 0))) as profit')
            ->groupBy('products.name', 'products.sku', 'sale_items.description')
            ->orderByDesc('profit')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'product' => $row->product_name,
                'sku' => $row->sku,
                'sales_total' => round((float) $row->sales_total, 2),
                'cost_total' => round((float) $row->cost_total, 2),
                'profit' => round((float) $row->profit, 2),
            ]);
    }

    private function profitBySeller(Carbon $from, Carbon $to, ?int $branchId)
    {
        return (clone $this->profitBaseQuery($from, $to, $branchId))
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->selectRaw('COALESCE(users.name, "Sin usuario") as seller, COUNT(DISTINCT sales.id) as sales_count, SUM(sale_items.total) as sales_total, SUM(sale_items.total - (sale_items.meters * COALESCE(products.purchase_price, 0))) as profit')
            ->groupBy('users.name')
            ->orderByDesc('profit')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'seller' => $row->seller,
                'sales_count' => (int) $row->sales_count,
                'sales_total' => round((float) $row->sales_total, 2),
                'profit' => round((float) $row->profit, 2),
            ]);
    }
}
