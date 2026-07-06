<?php

namespace App\Modules\Exports\Services;

use App\Modules\Branches\Models\Branch;
use App\Modules\Customers\Models\Customer;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Sales\Models\Sale;
use App\Support\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExportDatasetService
{
    public function catalog(): array
    {
        return [
            'inventory' => [
                'label' => 'Inventario',
                'description' => 'Stock por producto, unidad configurada y sucursal.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'product' => 'Producto',
                    'sku' => 'SKU/modelo',
                    'barcode' => 'Codigo de barras',
                    'unit' => 'Unidad',
                    'available' => 'Disponible',
                    'reserved' => 'Reservado',
                    'minimum' => 'Stock minimo',
                ],
            ],
            'sales' => [
                'label' => 'Ventas',
                'description' => 'Cotizaciones y notas de venta dentro del rango.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'number' => 'Numero',
                    'type' => 'Tipo documento',
                    'customer' => 'Cliente',
                    'date' => 'Fecha',
                    'status' => 'Estado',
                    'total' => 'Total',
                    'paid' => 'Pagado',
                    'balance' => 'Saldo',
                ],
            ],
            'purchases' => [
                'label' => 'Compras',
                'description' => 'Compras de mercaderia registradas.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'number' => 'Documento',
                    'supplier' => 'Proveedor',
                    'date' => 'Fecha',
                    'status' => 'Estado',
                    'total' => 'Total',
                    'paid' => 'Pagado',
                    'balance' => 'Saldo',
                ],
            ],
            'finance' => [
                'label' => 'Economia/contable',
                'description' => 'Ingresos cobrados, compras pagadas, gastos y ganancia.',
                'fields' => [
                    'date' => 'Fecha',
                    'branch' => 'Sucursal',
                    'income' => 'Ingresos',
                    'purchase_payments' => 'Compras pagadas',
                    'expenses' => 'Gastos',
                    'outflows' => 'Egresos',
                    'profit' => 'Ganancia',
                ],
            ],
            'customers' => [
                'label' => 'Clientes',
                'description' => 'Directorio de clientes.',
                'fields' => [
                    'type' => 'Tipo',
                    'document' => 'Documento',
                    'name' => 'Nombre',
                    'phone' => 'Telefono',
                    'email' => 'Email',
                    'active' => 'Activo',
                ],
            ],
        ];
    }

    public function build(Request $request): array
    {
        $catalog = $this->catalog();
        $modules = collect($request->input('modules', []))
            ->filter(fn ($module) => isset($catalog[$module]))
            ->values();

        if ($modules->isEmpty()) {
            abort(422, 'Debe seleccionar al menos un modulo para exportar.');
        }

        $from = $this->date($request->input('from'), now()->startOfMonth())->startOfDay();
        $to = $this->date($request->input('to'), now())->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $branchId = $request->integer('branch_id') ?: null;
        abort_if($branchId && ! BranchAccess::canAccess($request->user(), $branchId), 403);

        return [
            'title' => 'Exportacion del sistema',
            'generated_at' => now()->format('d/m/Y H:i'),
            'branding' => $this->branding($request, $branchId),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $branchId ? Branch::query()->whereKey($branchId)->value('name') : 'Todas las sucursales permitidas',
            ],
            'sections' => $modules
                ->map(fn (string $module) => $this->section($module, $catalog[$module], $request, $from, $to, $branchId))
                ->values()
                ->all(),
        ];
    }

    private function section(string $module, array $definition, Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $selectedFields = collect($request->input("fields.{$module}", []))
            ->filter(fn ($field) => isset($definition['fields'][$field]))
            ->values()
            ->all();

        if ($selectedFields === []) {
            $selectedFields = array_keys($definition['fields']);
        }

        $allRows = $this->rows($module, $request, $from, $to, $branchId);
        $headers = collect($selectedFields)->mapWithKeys(fn ($field) => [$field => $definition['fields'][$field]])->all();

        return [
            'key' => $module,
            'title' => $definition['label'],
            'description' => $definition['description'],
            'headers' => array_values($headers),
            'rows' => collect($allRows)
                ->map(fn (array $row) => collect(array_keys($headers))->map(fn ($field) => $row[$field] ?? '-')->all())
                ->all(),
        ];
    }

    private function rows(string $module, Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return match ($module) {
            'inventory' => $this->inventoryRows($request, $branchId),
            'sales' => $this->salesRows($request, $from, $to, $branchId),
            'purchases' => $this->purchaseRows($request, $from, $to, $branchId),
            'finance' => $this->financeRows($request, $from, $to, $branchId),
            'customers' => $this->customerRows(),
            default => [],
        };
    }

    private function inventoryRows(Request $request, ?int $branchId): array
    {
        return ProductBranchStock::query()
            ->join('branches', 'product_branch_stocks.branch_id', '=', 'branches.id')
            ->join('products', 'product_branch_stocks.product_id', '=', 'products.id')
            ->leftJoin('product_units', 'products.product_unit_id', '=', 'product_units.id')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'product_branch_stocks.branch_id'))
            ->when($branchId, fn ($query) => $query->where('product_branch_stocks.branch_id', $branchId))
            ->orderBy('branches.name')
            ->orderBy('products.name')
            ->get([
                DB::raw('branches.name as branch'),
                DB::raw('products.name as product'),
                DB::raw('products.sku as sku'),
                DB::raw('products.barcode as barcode'),
                DB::raw('COALESCE(product_units.symbol, products.base_unit) as unit'),
                DB::raw('product_branch_stocks.available_meters as available'),
                DB::raw('product_branch_stocks.reserved_meters as reserved'),
                DB::raw('products.minimum_stock_meters as minimum'),
            ])
            ->map(fn ($row) => [
                'branch' => $row->branch,
                'product' => $row->product,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'unit' => $row->unit ?: 'm',
                'available' => round((float) $row->available, 3),
                'reserved' => round((float) $row->reserved, 3),
                'minimum' => round((float) $row->minimum, 3),
            ])
            ->all();
    }

    private function salesRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return Sale::query()
            ->with('branch:id,name')
            ->withSum(['payments as payments_total_bob' => fn ($query) => $query->whereNull('deleted_at')], 'amount_bob')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('sold_at', [$from, $to])
            ->latest('sold_at')
            ->limit(5000)
            ->get(['id', 'branch_id', 'receipt_number', 'document_type', 'customer_name', 'sold_at', 'status', 'total', 'advance_amount', 'balance_due'])
            ->map(fn (Sale $sale) => [
                'branch' => $sale->branch?->name,
                'number' => $sale->receipt_number,
                'type' => $sale->document_type === 'quotation' ? 'Cotizacion' : 'Nota de venta',
                'customer' => $sale->customer_name,
                'date' => $sale->sold_at?->format('d/m/Y H:i'),
                'status' => $sale->status,
                'total' => (float) $sale->total,
                'paid' => round((float) $sale->advance_amount + (float) ($sale->payments_total_bob ?? 0), 2),
                'balance' => (float) $sale->balance_due,
            ])
            ->all();
    }

    private function purchaseRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return Purchase::query()
            ->with(['branch:id,name', 'supplier:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()])
            ->latest('purchase_date')
            ->limit(5000)
            ->get(['branch_id', 'supplier_id', 'document_number', 'purchase_date', 'status', 'total_amount', 'paid_amount', 'balance_due'])
            ->map(fn (Purchase $purchase) => [
                'branch' => $purchase->branch?->name,
                'number' => $purchase->document_number,
                'supplier' => $purchase->supplier?->name,
                'date' => $purchase->purchase_date?->format('d/m/Y'),
                'status' => $purchase->status,
                'total' => (float) $purchase->total_amount,
                'paid' => (float) $purchase->paid_amount,
                'balance' => (float) $purchase->balance_due,
            ])
            ->all();
    }

    private function financeRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        $branches = Branch::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'branches.id'))
            ->when($branchId, fn ($query) => $query->whereKey($branchId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy('id');

        $income = SalePayment::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as date, branch_id, SUM(amount_bob) as total')
            ->groupBy('date', 'branch_id')
            ->get();

        $purchasePayments = PurchasePayment::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as date, branch_id, SUM(amount) as total')
            ->groupBy('date', 'branch_id')
            ->get();

        $expenses = Expense::query()
            ->where('status', Expense::STATUS_REGISTERED)
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('spent_at', [$from, $to])
            ->selectRaw('DATE(spent_at) as date, branch_id, SUM(amount) as total')
            ->groupBy('date', 'branch_id')
            ->get();

        $rows = [];
        foreach ([$income, $purchasePayments, $expenses] as $collection) {
            foreach ($collection as $row) {
                $key = "{$row->date}:{$row->branch_id}";
                $rows[$key] ??= [
                    'date' => Carbon::parse($row->date)->format('d/m/Y'),
                    'branch' => $branches->get($row->branch_id)?->name ?? 'Sucursal',
                    'income' => 0.0,
                    'purchase_payments' => 0.0,
                    'expenses' => 0.0,
                ];
            }
        }

        foreach ($income as $row) {
            $rows["{$row->date}:{$row->branch_id}"]['income'] = round((float) $row->total, 2);
        }
        foreach ($purchasePayments as $row) {
            $rows["{$row->date}:{$row->branch_id}"]['purchase_payments'] = round((float) $row->total, 2);
        }
        foreach ($expenses as $row) {
            $rows["{$row->date}:{$row->branch_id}"]['expenses'] = round((float) $row->total, 2);
        }

        return collect($rows)->map(function (array $row) {
            $row['outflows'] = round($row['purchase_payments'] + $row['expenses'], 2);
            $row['profit'] = round($row['income'] - $row['outflows'], 2);

            return $row;
        })->sortBy('date')->values()->all();
    }

    private function customerRows(): array
    {
        return Customer::query()
            ->with('type:id,name')
            ->orderBy('name')
            ->limit(5000)
            ->get(['customer_type_id', 'document_number', 'name', 'phone', 'email', 'is_active'])
            ->map(fn (Customer $customer) => [
                'type' => $customer->type?->name,
                'document' => $customer->document_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'active' => $customer->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function branding(Request $request, ?int $branchId): array
    {
        $query = Branch::query()
            ->with('setting:id,branch_id,primary_color,secondary_color')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'branches.id'));

        $branch = $branchId
            ? (clone $query)->whereKey($branchId)->first()
            : (clone $query)->where('name', 'like', '%Central%')->first();

        $branch ??= $query->orderBy('name')->first();

        return [
            'branch' => $branch?->name ?? 'Sucursal',
            'primary' => $branch?->setting?->primary_color ?: '#2563eb',
            'secondary' => $branch?->setting?->secondary_color ?: '#0f172a',
        ];
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        try {
            return Carbon::parse($value ?: $fallback->toDateString());
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
