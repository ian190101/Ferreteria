<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\Banks\Models\BankAccount;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Exports\Services\ExportDatasetService;
use App\Modules\Exports\Services\SimplePdfExporter;
use App\Modules\Exports\Services\SimpleXlsxExporter;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Models\CustomerQrOrder;
use App\Support\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AiAssistantToolRegistry
{
    public function __construct(
        private readonly ExportDatasetService $exports,
        private readonly SimpleXlsxExporter $xlsx,
        private readonly SimplePdfExporter $pdf,
    ) {}

    public function toolsFor(User $user): array
    {
        return collect([
            'consultar_ventas' => $user->can('sales.view'),
            'consultar_ganancias' => $user->can('reports.view'),
            'consultar_stock' => $user->can('inventory.products.view'),
            'buscar_productos' => $user->can('inventory.products.view'),
            'consultar_clientes' => $user->can('customers.view'),
            'consultar_caja' => $user->can('cash.view'),
            'consultar_bancos' => $user->can('banks.view'),
            'consultar_estado_pedido' => $user->can('customer-qr-orders.view') || $user->can('sales.view'),
            'generar_exportacion_excel' => $user->can('ai-assistant.reports.generate') && $user->can('settings.manage'),
            'generar_exportacion_pdf' => $user->can('ai-assistant.reports.generate') && $user->can('settings.manage'),
            'crear_pedido' => $user->can('ai-assistant.orders.create'),
            'crear_cotizacion' => $user->can('ai-assistant.orders.create') && $user->can('sales.manage'),
        ])
            ->filter()
            ->keys()
            ->values()
            ->all();
    }

    public function run(User $user, string $tool, array $input = []): array
    {
        abort_unless(in_array($tool, $this->toolsFor($user), true), 403, 'No tienes permiso para usar esta herramienta.');

        return match ($tool) {
            'consultar_ventas' => $this->salesSummary($user, $input),
            'consultar_ganancias' => $this->profitSummary($user, $input),
            'consultar_stock' => $this->stockSummary($user, $input),
            'buscar_productos' => $this->productSearch($user, $input),
            'consultar_clientes' => $this->customerSummary($user, $input),
            'consultar_caja' => $this->cashSummary($user, $input),
            'consultar_bancos' => $this->bankSummary($user, $input),
            'consultar_estado_pedido' => $this->orderStatus($user, $input),
            'generar_exportacion_excel' => $this->generateExport($user, 'xlsx', $input),
            'generar_exportacion_pdf' => $this->generateExport($user, 'pdf', $input),
            default => [
                'status' => 'requires_confirmation',
                'message' => 'La herramienta esta registrada, pero esta accion debe confirmarse desde el flujo operativo.',
                'input' => $input,
            ],
        };
    }

    public function detectIntent(string $message): string
    {
        $text = str($message)->lower()->ascii()->toString();

        return match (true) {
            str_contains($text, 'stock') || str_contains($text, 'inventario') => 'consultar_stock',
            str_contains($text, 'ganancia') || str_contains($text, 'utilidad') || str_contains($text, 'margen') => 'consultar_ganancias',
            str_contains($text, 'cliente') || str_contains($text, 'ci ') || str_contains($text, 'nit') => 'consultar_clientes',
            str_contains($text, 'caja') || str_contains($text, 'cierre') || str_contains($text, 'efectivo') => 'consultar_caja',
            str_contains($text, 'banco') || str_contains($text, 'qr') || str_contains($text, 'transferencia') => 'consultar_bancos',
            str_contains($text, 'estado') && str_contains($text, 'pedido') => 'consultar_estado_pedido',
            str_contains($text, 'producto') || str_contains($text, 'precio') || str_contains($text, 'calamina') => 'buscar_productos',
            str_contains($text, 'excel') => 'generar_exportacion_excel',
            str_contains($text, 'pdf') => 'generar_exportacion_pdf',
            str_contains($text, 'pedido') || str_contains($text, 'cotizacion') => 'crear_pedido',
            default => 'consultar_ventas',
        };
    }

    private function salesSummary(User $user, array $input): array
    {
        [$from, $to] = $this->dateRange($input);
        $query = Sale::query()
            ->where('document_type', 'sale_note')
            ->whereNotIn('status', ['voided', 'cancelled'])
            ->whereBetween('sold_at', [$from, $to]);
        BranchAccess::apply($query, $user);

        return [
            'tool' => 'consultar_ventas',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales_count' => (clone $query)->count(),
            'sales_total' => round((float) (clone $query)->sum('total'), 2),
            'branches' => $this->branchNames($user),
        ];
    }

    private function profitSummary(User $user, array $input): array
    {
        [$from, $to] = $this->dateRange($input);
        $query = Sale::query()
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.document_type', 'sale_note')
            ->whereNotIn('sales.status', ['voided', 'cancelled'])
            ->whereBetween('sales.sold_at', [$from, $to]);
        BranchAccess::apply($query, $user, 'sales.branch_id');

        $row = $query
            ->selectRaw('COALESCE(SUM(sale_items.total), 0) as sales_total')
            ->selectRaw('COALESCE(SUM(sale_items.total - (sale_items.meters * COALESCE(products.purchase_price, 0))), 0) as profit')
            ->first();

        $salesTotal = (float) ($row->sales_total ?? 0);
        $profit = (float) ($row->profit ?? 0);

        return [
            'tool' => 'consultar_ganancias',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales_total' => round($salesTotal, 2),
            'profit' => round($profit, 2),
            'margin_percent' => $salesTotal > 0 ? round(($profit / $salesTotal) * 100, 2) : 0,
        ];
    }

    private function stockSummary(User $user, array $input): array
    {
        $query = ProductBranchStock::query()
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id');
        BranchAccess::apply($query, $user, 'product_branch_stocks.branch_id');

        if ($search = trim((string) ($input['search'] ?? ''))) {
            $query->where('products.name', 'like', '%'.$search.'%');
        }

        return [
            'tool' => 'consultar_stock',
            'products_count' => (clone $query)->distinct('products.id')->count('products.id'),
            'available_total' => round((float) (clone $query)->sum('product_branch_stocks.available_meters'), 2),
            'low_stock_count' => (clone $query)->whereColumn('product_branch_stocks.available_meters', '<=', 'products.minimum_stock_meters')->count(),
        ];
    }

    private function productSearch(User $user, array $input): array
    {
        $search = trim((string) ($input['search'] ?? $input['message'] ?? ''));
        $products = Product::query()
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'sale_price', 'unit']);

        return [
            'tool' => 'buscar_productos',
            'items' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'sale_price' => (float) $product->sale_price,
                'unit' => $product->unit,
            ])->all(),
        ];
    }

    private function customerSummary(User $user, array $input): array
    {
        $search = trim((string) ($input['search'] ?? $input['message'] ?? ''));
        $query = Customer::query();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('document_number', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        return [
            'tool' => 'consultar_clientes',
            'customers_count' => (clone $query)->count(),
            'items' => $query->orderBy('name')->limit(6)->get(['id', 'name', 'document_number', 'phone', 'is_active'])
                ->map(fn (Customer $customer): array => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'document_number' => $customer->document_number,
                    'phone' => $customer->phone,
                    'active' => (bool) $customer->is_active,
                ])->all(),
        ];
    }

    private function cashSummary(User $user, array $input): array
    {
        [$from, $to] = $this->dateRange($input);
        $query = CashRegisterSession::query()->whereBetween('opened_at', [$from, $to]);
        BranchAccess::apply($query, $user);

        return [
            'tool' => 'consultar_caja',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'open_sessions' => (clone $query)->where('status', 'open')->count(),
            'closed_sessions' => (clone $query)->where('status', 'closed')->count(),
            'expected_cash' => round((float) (clone $query)->sum('expected_cash_amount'), 2),
            'counted_cash' => round((float) (clone $query)->sum('counted_cash_amount'), 2),
        ];
    }

    private function bankSummary(User $user, array $input): array
    {
        $query = BankAccount::query();
        BranchAccess::apply($query, $user);

        return [
            'tool' => 'consultar_bancos',
            'accounts_count' => (clone $query)->count(),
            'total_balance' => round((float) (clone $query)->sum('current_balance'), 2),
            'items' => $query->orderBy('bank_name')->limit(6)->get(['id', 'bank_name', 'name', 'current_balance', 'currency_code'])
                ->map(fn (BankAccount $account): array => [
                    'id' => $account->id,
                    'bank' => $account->bank_name,
                    'account' => $account->name,
                    'balance' => (float) $account->current_balance,
                    'currency' => $account->currency_code,
                ])->all(),
        ];
    }

    private function orderStatus(User $user, array $input): array
    {
        $code = trim((string) ($input['code'] ?? $input['message'] ?? ''));
        $query = CustomerQrOrder::query()->latest('submitted_at');
        BranchAccess::apply($query, $user);

        if ($code !== '') {
            $query->where(function ($builder) use ($code) {
                $builder->where('public_code', 'like', '%'.$code.'%')
                    ->orWhere('customer_name', 'like', '%'.$code.'%')
                    ->orWhere('customer_phone', 'like', '%'.$code.'%');
            });
        }

        return [
            'tool' => 'consultar_estado_pedido',
            'items' => $query->limit(5)->get(['id', 'public_code', 'customer_name', 'status', 'total', 'submitted_at'])
                ->map(fn (CustomerQrOrder $order): array => [
                    'id' => $order->id,
                    'code' => $order->public_code,
                    'customer' => $order->customer_name,
                    'status' => $order->status,
                    'total' => (float) $order->total,
                    'submitted_at' => $order->submitted_at?->toDateTimeString(),
                ])->all(),
        ];
    }

    private function generateExport(User $user, string $format, array $input): array
    {
        $modules = $this->exportModules($input);
        $request = Request::create('/exports/download', 'POST', [
            'format' => $format,
            'branch_id' => $input['branch_id'] ?? null,
            'from' => $input['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $input['to'] ?? now()->toDateString(),
            'modules' => $modules,
            'fields' => [],
        ]);
        $request->setUserResolver(fn () => $user);

        $dataset = $this->exports->build($request);
        $filename = 'ai-assistant/exportacion-ia-'.now()->format('Ymd-His').'.'.$format;
        $absolutePath = Storage::disk('local')->path($filename);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        if ($format === 'xlsx') {
            $this->xlsx->save($dataset, $absolutePath);
        } else {
            $this->pdf->save($dataset, $absolutePath);
        }

        return [
            'tool' => 'generar_exportacion_'.$format,
            'status' => 'completed',
            'format' => $format,
            'path' => $filename,
            'modules' => $modules,
            'message' => 'Archivo generado en almacenamiento interno. Debe descargarse desde una ruta autenticada del ERP.',
        ];
    }

    private function exportModules(array $input): array
    {
        $message = str($input['message'] ?? '')->lower()->ascii()->toString();

        return match (true) {
            str_contains($message, 'stock') || str_contains($message, 'inventario') => ['inventory'],
            str_contains($message, 'compra') => ['purchases'],
            str_contains($message, 'banco') => ['banks_accounts', 'banks_transactions'],
            str_contains($message, 'caja') || str_contains($message, 'ganancia') || str_contains($message, 'finanza') => ['finance'],
            str_contains($message, 'cliente') => ['customers'],
            default => ['sales'],
        };
    }

    private function dateRange(array $input): array
    {
        $from = filled($input['from'] ?? null) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth();
        $to = filled($input['to'] ?? null) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    private function branchNames(User $user): array
    {
        $query = Branch::query()->orderBy('name');

        if (! $user->isSuperAdministrator()) {
            $query->whereIn('id', $user->accessibleBranchIds() ?: [-1]);
        }

        return $query->pluck('name')->all();
    }
}
