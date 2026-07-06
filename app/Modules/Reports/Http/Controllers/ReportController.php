<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use App\Support\UiCatalogCache;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->date('from')?->startOfDay() ?? now()->startOfMonth();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId && ! BranchAccess::canAccess($request->user(), $branchId), 403);

        $cacheKey = sprintf(
            'reports:dashboard:%s:%s:%s:%s',
            $branchId ?? 'all',
            $from->toDateString(),
            $to->toDateString(),
            $this->reportCacheVersion($branchId),
        );

        $metrics = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($from, $to, $branchId) {
            return [
                'sales_total' => (float) $this->salesQuery($from, $to, $branchId)->sum('total'),
                'sales_count' => $this->salesQuery($from, $to, $branchId)->count(),
                'quotations_count' => $this->salesQuery($from, $to, $branchId)->where('document_type', 'quotation')->count(),
                'purchase_total' => (float) $this->purchaseQuery($from, $to, $branchId)->sum('total_amount'),
                'purchase_count' => $this->purchaseQuery($from, $to, $branchId)->count(),
                'expense_total' => (float) $this->expenseQuery($from, $to, $branchId)->sum('amount'),
                'expense_count' => $this->expenseQuery($from, $to, $branchId)->count(),
                'active_coils' => $this->coilQuery($branchId)->where('status', 'available')->count(),
                'low_stock_count' => $this->lowStockQuery($branchId)->count(),
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
            'recentSales' => $this->salesQuery($from, $to, $branchId)
                ->with(['branch:id,name', 'currency:id,symbol,code'])
                ->latest('sold_at')
                ->limit(8)
                ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'document_type', 'customer_name', 'sold_at', 'total', 'status']),
            'lowStocks' => $this->lowStockQuery($branchId)
                ->with(['branch:id,name', 'product:id,name,sku,minimum_stock_meters'])
                ->orderBy('available_meters')
                ->limit(10)
                ->get(['id', 'branch_id', 'product_id', 'available_meters', 'reserved_meters']),
            'agingBuckets' => $this->agingBuckets($branchId),
            'agingReceivables' => $this->agingReceivables($branchId, $request),
            'latestMovements' => ProductCoil::query()
                ->with(['branch:id,name', 'product:id,name,sku'])
                ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->latest('id')
                ->limit(8)
                ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters', 'status', 'created_at']),
        ]);
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
        $buckets = [
            '0_7' => ['label' => '0 a 7 dias', 'count' => 0, 'total' => 0.0],
            '8_15' => ['label' => '8 a 15 dias', 'count' => 0, 'total' => 0.0],
            '16_30' => ['label' => '16 a 30 dias', 'count' => 0, 'total' => 0.0],
            '31_plus' => ['label' => '31 dias o mas', 'count' => 0, 'total' => 0.0],
        ];

        $this->receivablesQuery($branchId)
            ->get(['id', 'sold_at', 'balance_due'])
            ->each(function (Sale $sale) use (&$buckets) {
                $bucket = $this->agingBucketKey((int) $sale->sold_at->diffInDays(now()));
                $buckets[$bucket]['count']++;
                $buckets[$bucket]['total'] = round($buckets[$bucket]['total'] + (float) $sale->balance_due, 2);
            });

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

    private function reportCacheVersion(?int $branchId): string
    {
        // La version evita servir metricas viejas cuando cambian ventas, compras o inventario dentro del mismo rango.
        return collect([
            $this->tableVersion(Sale::query(), $branchId),
            $this->tableVersion(PaymentPromise::query(), $branchId),
            $this->tableVersion(Purchase::query(), $branchId),
            $this->tableVersion(Expense::query(), $branchId),
            $this->tableVersion(ProductBranchStock::query(), $branchId),
            $this->tableVersion(ProductCoil::query(), $branchId),
        ])->implode('|');
    }

    private function tableVersion($query, ?int $branchId): string
    {
        BranchAccess::apply($query, request()->user());
        $query->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        return $query->count().':'.($query->max('updated_at') ?? 'none');
    }
}
