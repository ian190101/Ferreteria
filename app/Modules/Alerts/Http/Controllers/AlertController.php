<?php

namespace App\Modules\Alerts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\CustomerInteraction;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCoil;
use App\Modules\Payments\Models\PaymentPromise;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $alerts = $this->alerts($request)
            ->when($request->filled('type'), fn (Collection $items) => $items->where('type', $request->string('type')->toString()))
            ->when($request->filled('severity'), fn (Collection $items) => $items->where('severity', $request->string('severity')->toString()))
            ->sortByDesc('sort_at')
            ->values();

        return Inertia::render('Alerts/Index', [
            'alerts' => $this->paginate($alerts, $request),
            'summary' => [
                'critical' => $alerts->where('severity', 'critical')->count(),
                'warning' => $alerts->where('severity', 'warning')->count(),
                'info' => $alerts->where('severity', 'info')->count(),
                'total' => $alerts->count(),
            ],
            'filters' => $request->only(['type', 'severity', 'per_page']),
            'types' => $this->availableTypes($request),
            'severities' => ['critical', 'warning', 'info'],
        ]);
    }

    private function alerts(Request $request): Collection
    {
        $user = $request->user();
        $branchIds = $user->isSuperAdministrator() ? [] : ($user->accessibleBranchIds() ?: [-1]);

        return collect()
            ->merge($this->can($user, 'inventory.products.view', 'inventory') ? $this->lowStockAlerts($branchIds) : [])
            ->merge($this->can($user, 'payments.view', 'sales_notes') ? $this->receivableAlerts($branchIds) : [])
            ->merge($this->can($user, 'payment-promises.view', 'payment_promises') ? $this->paymentPromiseAlerts($branchIds) : [])
            ->merge($this->can($user, 'cash.view', 'cash') ? $this->cashAlerts($branchIds) : [])
            ->merge($this->can($user, 'customers.view', 'customers') ? $this->customerFollowUpAlerts() : [])
            ->merge($this->can($user, 'inventory.coils.manage', 'inventory_lots') ? $this->coilAlerts($branchIds) : []);
    }

    private function lowStockAlerts(array $branchIds): Collection
    {
        return ProductBranchStock::query()
            ->with(['branch:id,name', 'product:id,name,sku,minimum_stock_meters'])
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id')
            ->whereColumn('product_branch_stocks.available_meters', '<=', 'products.minimum_stock_meters')
            ->when($branchIds !== [], fn ($query) => $query->whereIn('product_branch_stocks.branch_id', $branchIds))
            ->orderBy('product_branch_stocks.available_meters')
            ->limit(100)
            ->get(['product_branch_stocks.*'])
            ->map(fn (ProductBranchStock $stock) => [
                'id' => 'low-stock-'.$stock->id,
                'type' => 'low_stock',
                'severity' => ((float) $stock->available_meters <= 0) ? 'critical' : 'warning',
                'title' => 'Stock bajo',
                'message' => sprintf(
                    '%s tiene %s m disponibles. Minimo configurado: %s m.',
                    $stock->product?->name ?? 'Producto',
                    number_format((float) $stock->available_meters, 3, '.', ''),
                    number_format((float) $stock->product?->minimum_stock_meters, 3, '.', ''),
                ),
                'branch' => $stock->branch?->name,
                'sort_at' => $stock->updated_at?->toISOString(),
                'source_url' => route('inventory.products.index', ['search' => $stock->product?->sku]),
            ]);
    }

    private function receivableAlerts(array $branchIds): Collection
    {
        return Sale::query()
            ->with(['branch:id,name', 'currency:id,symbol,code'])
            ->where('document_type', 'sale_note')
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('balance_due', '>', 0)
            ->when($branchIds !== [], fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->oldest('sold_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'currency_id', 'receipt_number', 'customer_name', 'sold_at', 'balance_due', 'status'])
            ->map(function (Sale $sale) {
                $days = (int) $sale->sold_at->diffInDays(now());

                return [
                    'id' => 'receivable-'.$sale->id,
                    'type' => 'receivable',
                    'severity' => $days >= 7 ? 'critical' : 'warning',
                    'title' => 'Cuenta por cobrar',
                    'message' => sprintf(
                        '%s debe %s %s desde hace %s dias.',
                        $sale->customer_name ?? 'Cliente',
                        $sale->currency?->symbol ?? 'Bs',
                        number_format((float) $sale->balance_due, 2, '.', ''),
                        $days,
                    ),
                    'branch' => $sale->branch?->name,
                    'sort_at' => $sale->sold_at?->toISOString(),
                    'source_url' => route('sales.show', $sale->id),
                ];
            });
    }

    private function cashAlerts(array $branchIds): Collection
    {
        return CashRegisterSession::query()
            ->with(['branch:id,name', 'opener:id,name'])
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->where('opened_at', '<', now()->startOfDay())
            ->when($branchIds !== [], fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->oldest('opened_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'opened_by', 'opened_at', 'opening_amount', 'expected_cash_amount', 'status'])
            ->map(fn (CashRegisterSession $session) => [
                'id' => 'cash-'.$session->id,
                'type' => 'cash_open',
                'severity' => 'critical',
                'title' => 'Caja abierta sin cierre',
                'message' => sprintf(
                    'Caja de %s abierta por %s desde %s.',
                    $session->branch?->name ?? 'Sucursal',
                    $session->opener?->name ?? 'usuario',
                    $session->opened_at?->format('d/m/Y H:i'),
                ),
                'branch' => $session->branch?->name,
                'sort_at' => $session->opened_at?->toISOString(),
                'source_url' => route('cash.index'),
            ]);
    }

    private function paymentPromiseAlerts(array $branchIds): Collection
    {
        return PaymentPromise::query()
            ->with(['branch:id,name', 'sale:id,receipt_number,customer_name,balance_due'])
            ->where('status', PaymentPromise::STATUS_PENDING)
            ->whereDate('promised_date', '<=', today())
            ->when($branchIds !== [], fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->oldest('promised_date')
            ->limit(100)
            ->get(['id', 'sale_id', 'branch_id', 'promise_number', 'promised_date', 'promised_amount', 'contact_name', 'contact_phone', 'status'])
            ->map(function (PaymentPromise $promise) {
                $isOverdue = $promise->promised_date->isBefore(today());

                return [
                    'id' => 'payment-promise-'.$promise->id,
                    'type' => 'payment_promise',
                    'severity' => $isOverdue ? 'critical' : 'warning',
                    'title' => $isOverdue ? 'Promesa de pago vencida' : 'Promesa de pago vence hoy',
                    'message' => sprintf(
                        '%s prometio pagar Bs %s el %s. Contacto: %s.',
                        $promise->sale?->customer_name ?? $promise->contact_name ?? 'Cliente',
                        number_format((float) $promise->promised_amount, 2, '.', ''),
                        $promise->promised_date->format('d/m/Y'),
                        $promise->contact_phone ?: 'sin telefono',
                    ),
                    'branch' => $promise->branch?->name,
                    'sort_at' => $promise->promised_date?->toISOString(),
                    'source_url' => route('payments.promises.index', ['search' => $promise->promise_number]),
                ];
            });
    }

    private function coilAlerts(array $branchIds): Collection
    {
        return ProductCoil::query()
            ->with(['branch:id,name', 'product:id,name,sku'])
            ->where('status', 'depleted')
            ->when($branchIds !== [], fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->latest('updated_at')
            ->limit(100)
            ->get(['id', 'branch_id', 'product_id', 'barcode', 'lot_number', 'available_meters', 'status', 'updated_at'])
            ->map(fn (ProductCoil $coil) => [
                'id' => 'coil-'.$coil->id,
                'type' => 'depleted_coil',
                'severity' => 'info',
                'title' => 'Bobina agotada',
                'message' => sprintf('%s lote %s ya no tiene metraje disponible.', $coil->barcode, $coil->lot_number),
                'branch' => $coil->branch?->name,
                'sort_at' => $coil->updated_at?->toISOString(),
                'source_url' => route('inventory.coils.index'),
            ]);
    }

    private function customerFollowUpAlerts(): Collection
    {
        return CustomerInteraction::query()
            ->with(['customer:id,name,phone', 'user:id,name'])
            ->where('status', CustomerInteraction::STATUS_PENDING)
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->oldest('follow_up_at')
            ->limit(100)
            ->get(['id', 'customer_id', 'user_id', 'type', 'subject', 'follow_up_at', 'status'])
            ->map(function (CustomerInteraction $interaction) {
                $isOverdue = $interaction->follow_up_at->isPast() && ! $interaction->follow_up_at->isToday();

                return [
                    'id' => 'customer-follow-up-'.$interaction->id,
                    'type' => 'customer_follow_up',
                    'severity' => $isOverdue ? 'critical' : 'warning',
                    'title' => $isOverdue ? 'Seguimiento de cliente vencido' : 'Seguimiento de cliente vence hoy',
                    'message' => sprintf(
                        '%s: %s. Responsable: %s.',
                        $interaction->customer?->name ?? 'Cliente',
                        $interaction->subject,
                        $interaction->user?->name ?? 'Sin responsable',
                    ),
                    'branch' => null,
                    'sort_at' => $interaction->follow_up_at?->toISOString(),
                    'source_url' => route('customers.statement', $interaction->customer_id),
                ];
            });
    }

    private function paginate(Collection $alerts, Request $request): LengthAwarePaginator
    {
        $perPage = min(max($request->integer('per_page', 15), 5), 50);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $alerts->forPage($page, $perPage)->values(),
            $alerts->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    private function availableTypes(Request $request): array
    {
        return collect([
            $this->can($request->user(), 'inventory.products.view', 'inventory') ? 'low_stock' : null,
            $this->can($request->user(), 'payments.view', 'sales_notes') ? 'receivable' : null,
            $this->can($request->user(), 'payment-promises.view', 'payment_promises') ? 'payment_promise' : null,
            $this->can($request->user(), 'cash.view', 'cash') ? 'cash_open' : null,
            $this->can($request->user(), 'customers.view', 'customers') ? 'customer_follow_up' : null,
            $this->can($request->user(), 'inventory.coils.manage', 'inventory_lots') ? 'depleted_coil' : null,
        ])->filter()->values()->all();
    }

    private function can($user, string $permission, string $feature): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($feature === 'inventory_lots') {
            return ActiveBusinessProfile::enabled('inventory')
                && (bool) (ActiveBusinessProfile::payload()['inventory']['lot_tracking_optional'] ?? false);
        }

        return ActiveBusinessProfile::enabled($feature);
    }
}
