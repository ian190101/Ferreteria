<?php

namespace App\Modules\Exports\Services;

use App\Modules\Banks\Models\BankAccount;
use App\Modules\Banks\Models\BankTransaction;
use App\Modules\Billing\Models\SiatBranchSetting;
use App\Modules\Billing\Models\SiatCatalogItem;
use App\Modules\Billing\Models\SiatInvoice;
use App\Modules\Billing\Models\SiatLog;
use App\Modules\Billing\Models\SiatProductMapping;
use App\Modules\Billing\Models\SiatSignificantEvent;
use App\Modules\Branches\Models\Branch;
use App\Modules\Cash\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Expenses\Models\Expense;
use App\Modules\HumanResources\Models\SalaryPayment;
use App\Modules\HumanResources\Models\Worker;
use App\Modules\Inventory\Models\BarcodeLabelTemplate;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Payments\Models\PurchasePayment;
use App\Modules\Payments\Models\SalePayment;
use App\Modules\Purchases\Models\Purchase;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Services\ActiveBusinessProfile;
use App\Support\BranchAccess;
use App\Models\Audit;
use App\Models\User;
use App\Support\SystemRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExportDatasetService
{
    public function catalog(?Request $request = null): array
    {
        if (! $this->exportsEnabled($request)) {
            return [];
        }

        return collect([
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
            'suppliers' => [
                'label' => 'Proveedores',
                'description' => 'Directorio de proveedores activos e inactivos.',
                'fields' => [
                    'name' => 'Nombre',
                    'document' => 'Documento',
                    'phone' => 'Telefono',
                    'email' => 'Email',
                    'active' => 'Activo',
                ],
            ],
            'expenses' => [
                'label' => 'Gastos / egresos',
                'description' => 'Detalle de gastos registrados, incluyendo pago de sueldos si aplica.',
                'fields' => [
                    'date' => 'Fecha',
                    'branch' => 'Sucursal',
                    'category' => 'Categoria',
                    'description' => 'Descripcion',
                    'payment_method' => 'Metodo',
                    'worker' => 'Trabajador',
                    'status' => 'Estado',
                    'amount' => 'Monto',
                ],
            ],
            'banks_accounts' => [
                'label' => 'Cuentas bancarias',
                'description' => 'Cuentas por sucursal y saldos actuales.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'bank' => 'Banco',
                    'name' => 'Cuenta',
                    'number' => 'Numero',
                    'currency' => 'Moneda',
                    'balance' => 'Saldo actual',
                    'active' => 'Activa',
                ],
            ],
            'banks_transactions' => [
                'label' => 'Movimientos bancarios',
                'description' => 'Ingresos, egresos y ajustes bancarios por sucursal.',
                'fields' => [
                    'date' => 'Fecha',
                    'branch' => 'Sucursal',
                    'account' => 'Cuenta',
                    'type' => 'Tipo',
                    'description' => 'Descripcion',
                    'reference' => 'Referencia',
                    'status' => 'Estado',
                    'amount' => 'Monto',
                    'reconciled' => 'Conciliado',
                ],
            ],
            'cash_sessions' => [
                'label' => 'Cajas',
                'description' => 'Aperturas y cierres de caja con efectivo y QR/Banco.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'opened_by' => 'Aperturado por',
                    'closed_by' => 'Cerrado por',
                    'opened_at' => 'Apertura',
                    'closed_at' => 'Cierre',
                    'opening' => 'Monto inicial',
                    'cash_income' => 'Ingreso efectivo',
                    'cash_expense' => 'Egreso efectivo',
                    'bank_net' => 'Neto QR/Banco',
                    'expected' => 'Esperado',
                    'counted' => 'Contado',
                    'difference' => 'Diferencia',
                    'status' => 'Estado',
                ],
            ],
            'workers' => [
                'label' => 'Trabajadores',
                'description' => 'Personal registrado, vinculado o no a usuarios.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'name' => 'Nombre',
                    'document' => 'Documento',
                    'phone' => 'Telefono',
                    'position' => 'Cargo',
                    'user' => 'Usuario vinculado',
                    'hired_at' => 'Fecha ingreso',
                    'salary' => 'Sueldo',
                    'frequency' => 'Frecuencia',
                    'active' => 'Activo',
                ],
            ],
            'payroll' => [
                'label' => 'Pago de sueldos',
                'description' => 'Pagos de sueldo por trabajador, sucursal y metodo.',
                'fields' => [
                    'date' => 'Fecha pago',
                    'branch' => 'Sucursal',
                    'worker' => 'Trabajador',
                    'period' => 'Periodo',
                    'method' => 'Metodo',
                    'reference' => 'Referencia',
                    'status' => 'Estado',
                    'amount' => 'Monto',
                ],
            ],
            'barcode_templates' => [
                'label' => 'Plantillas barcode',
                'description' => 'Formatos configurados para imprimir etiquetas de productos.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'name' => 'Nombre',
                    'paper_type' => 'Tipo papel',
                    'width' => 'Ancho mm',
                    'height' => 'Alto mm',
                    'barcode_height' => 'Alto barcode mm',
                    'font_size' => 'Tamano texto',
                    'default' => 'Predeterminada',
                    'active' => 'Activa',
                ],
            ],
            'billing_invoices' => [
                'label' => 'Facturacion SIAT',
                'description' => 'Facturas fiscales, CUF, estado SIAT y datos principales de emision.',
                'fields' => [
                    'date' => 'Fecha emision',
                    'branch' => 'Sucursal',
                    'number' => 'Numero',
                    'cuf' => 'CUF',
                    'sale' => 'Nota de venta',
                    'customer' => 'Cliente',
                    'document' => 'Documento',
                    'status' => 'Estado',
                    'reception' => 'Recepcion',
                    'total' => 'Total',
                ],
            ],
            'billing_settings' => [
                'label' => 'Configuracion SIAT',
                'description' => 'Configuracion fiscal por sucursal para ambiente, modalidad, NIT, CUIS/CUFD y punto de venta.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'nit' => 'NIT',
                    'business_name' => 'Razon social',
                    'environment' => 'Ambiente',
                    'modality' => 'Modalidad',
                    'siat_branch' => 'Sucursal SIAT',
                    'point_of_sale' => 'Punto venta SIAT',
                    'active' => 'Activa',
                ],
            ],
            'billing_products' => [
                'label' => 'Homologacion SIAT productos',
                'description' => 'Productos vinculados a actividad economica, codigo producto SIN y unidad SIAT.',
                'fields' => [
                    'product' => 'Producto',
                    'sku' => 'SKU',
                    'barcode' => 'Barcode',
                    'activity' => 'Actividad economica',
                    'sin_product' => 'Producto SIN',
                    'unit' => 'Unidad SIAT',
                    'description' => 'Descripcion fiscal',
                    'invoiceable' => 'Facturable',
                ],
            ],
            'billing_events' => [
                'label' => 'Eventos y paquetes SIAT',
                'description' => 'Eventos significativos registrados para contingencia y envio posterior.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'event_code' => 'Codigo evento',
                    'started_at' => 'Inicio',
                    'ended_at' => 'Fin',
                    'reception' => 'Recepcion',
                    'status' => 'Estado',
                    'description' => 'Descripcion',
                ],
            ],
            'billing_logs' => [
                'label' => 'Logs tecnicos SIAT',
                'description' => 'Historial tecnico de llamadas SOAP, respuestas, duracion y errores para certificacion.',
                'fields' => [
                    'date' => 'Fecha',
                    'branch' => 'Sucursal',
                    'invoice' => 'Factura',
                    'service' => 'Servicio',
                    'operation' => 'Operacion',
                    'status' => 'Estado',
                    'message' => 'Mensaje',
                    'duration' => 'Duracion ms',
                ],
            ],
            'deliveries' => [
                'label' => 'Despachos',
                'description' => 'Despachos vinculados a ventas y responsables de transporte.',
                'fields' => [
                    'branch' => 'Sucursal',
                    'sale' => 'Venta',
                    'driver' => 'Conductor',
                    'truck' => 'Camion',
                    'date' => 'Fecha',
                    'status' => 'Estado',
                    'notes' => 'Notas',
                ],
            ],
            'branches' => [
                'label' => 'Sucursales',
                'description' => 'Datos generales de sucursales y puntos de venta.',
                'fields' => [
                    'name' => 'Sucursal',
                    'code' => 'Codigo',
                    'barcode' => 'Barcode',
                    'point_of_sale' => 'Punto de venta',
                    'phone' => 'Telefono',
                    'secondary_phone' => 'Telefono 2',
                    'address' => 'Direccion',
                    'active' => 'Activa',
                ],
            ],
            'users' => [
                'label' => 'Usuarios',
                'description' => 'Usuarios, roles y sucursales asignadas.',
                'fields' => [
                    'name' => 'Nombre',
                    'email' => 'Correo',
                    'main_branch' => 'Sucursal principal',
                    'branches' => 'Sucursales permitidas',
                    'roles' => 'Roles',
                    'active' => 'Activo',
                    'force_password_change' => 'Debe cambiar contrasena',
                    'last_login_at' => 'Ultimo ingreso',
                ],
            ],
            'audit' => [
                'label' => 'Auditoria',
                'description' => 'Historial de acciones registradas en el sistema.',
                'fields' => [
                    'date' => 'Fecha',
                    'user' => 'Usuario',
                    'event' => 'Accion',
                    'model' => 'Modulo afectado',
                    'record' => 'Registro',
                    'description' => 'Descripcion',
                    'ip' => 'IP',
                ],
            ],
        ])->filter(fn (array $definition, string $module) => $this->moduleAllowed($module, $request))->all();
    }

    public function build(Request $request): array
    {
        abort_unless($this->exportsEnabled($request), 404);

        $catalog = $this->catalog($request);
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
            'suppliers' => $this->supplierRows(),
            'expenses' => $this->expenseRows($request, $from, $to, $branchId),
            'banks_accounts' => $this->bankAccountRows($request, $branchId),
            'banks_transactions' => $this->bankTransactionRows($request, $from, $to, $branchId),
            'cash_sessions' => $this->cashSessionRows($request, $from, $to, $branchId),
            'workers' => $this->workerRows($request, $branchId),
            'payroll' => $this->payrollRows($request, $from, $to, $branchId),
            'barcode_templates' => $this->barcodeTemplateRows($request, $branchId),
            'billing_invoices' => $this->billingInvoiceRows($request, $from, $to, $branchId),
            'billing_settings' => $this->billingSettingRows($request, $branchId),
            'billing_products' => $this->billingProductRows(),
            'billing_events' => $this->billingEventRows($request, $from, $to, $branchId),
            'billing_logs' => $this->billingLogRows($request, $from, $to, $branchId),
            'deliveries' => $this->deliveryRows($request, $from, $to, $branchId),
            'branches' => $this->branchRows($request, $branchId),
            'users' => $this->userRows($request, $branchId),
            'audit' => $this->auditRows($request, $from, $to),
            default => [],
        };
    }

    private function moduleAllowed(string $module, ?Request $request): bool
    {
        return $this->moduleEnabled($module) && $this->canExport($module, $request);
    }

    private function moduleEnabled(string $module): bool
    {
        return match ($module) {
            'inventory' => ActiveBusinessProfile::enabled('inventory'),
            'sales' => ActiveBusinessProfile::enabled('quotes') || ActiveBusinessProfile::enabled('sales_notes') || ActiveBusinessProfile::enabled('pos'),
            'purchases' => ActiveBusinessProfile::enabled('purchases'),
            'finance' => ActiveBusinessProfile::enabled('sales_notes')
                || ActiveBusinessProfile::enabled('purchases')
                || ActiveBusinessProfile::enabled('expenses')
                || ActiveBusinessProfile::enabled('banks')
                || ActiveBusinessProfile::enabled('cash'),
            'customers' => ActiveBusinessProfile::enabled('customers'),
            'suppliers' => ActiveBusinessProfile::enabled('suppliers'),
            'expenses' => ActiveBusinessProfile::enabled('expenses'),
            'banks_accounts', 'banks_transactions' => ActiveBusinessProfile::enabled('banks'),
            'cash_sessions' => ActiveBusinessProfile::enabled('cash'),
            'workers' => ActiveBusinessProfile::enabled('workers'),
            'payroll' => ActiveBusinessProfile::enabled('payroll'),
            'barcode_templates' => ActiveBusinessProfile::enabled('barcode_labels'),
            'billing_invoices', 'billing_settings', 'billing_products', 'billing_events', 'billing_logs' => $this->billingEnabled(),
            'deliveries' => ActiveBusinessProfile::enabled('deliveries'),
            'branches', 'users', 'audit' => true,
            default => true,
        };
    }

    private function exportsEnabled(?Request $request): bool
    {
        if ($request?->user()?->hasRole(SystemRoles::SYSTEM_SUPERADMIN)) {
            return true;
        }

        return ActiveBusinessProfile::enabled('exports');
    }

    private function billingEnabled(): bool
    {
        $payload = ActiveBusinessProfile::payload();

        return (bool) ($payload['modules']['billing'] ?? false)
            && (bool) ($payload['billing']['enabled'] ?? false);
    }

    private function canExport(string $module, ?Request $request): bool
    {
        if (! $request?->user()) {
            return true;
        }

        return match ($module) {
            'inventory' => $request->user()->can('inventory.products.view'),
            'sales' => $request->user()->can('sales.view'),
            'purchases', 'suppliers' => $request->user()->can('purchases.view'),
            'finance' => $request->user()->can('payments.view') || $request->user()->can('purchases.view') || $request->user()->can('expenses.view'),
            'customers' => $request->user()->can('customers.view'),
            'expenses' => $request->user()->can('expenses.view'),
            'banks_accounts', 'banks_transactions' => $request->user()->can('banks.view'),
            'cash_sessions' => $request->user()->can('cash.view'),
            'workers' => $request->user()->can('workers.view'),
            'payroll' => $request->user()->can('payroll.view'),
            'barcode_templates' => $request->user()->can('barcode-labels.view'),
            'billing_invoices', 'billing_settings', 'billing_products', 'billing_events', 'billing_logs' => $request->user()->can('billing.view'),
            'deliveries' => $request->user()->can('sales.deliveries.view'),
            'branches' => $request->user()->can('branches.view'),
            'users' => $request->user()->can('users.view'),
            'audit' => $request->user()->can('audit.view'),
            default => true,
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

    private function supplierRows(): array
    {
        return Supplier::query()
            ->orderBy('name')
            ->limit(5000)
            ->get(['tax_id', 'name', 'phone', 'email', 'is_active'])
            ->map(fn (Supplier $supplier) => [
                'document' => $supplier->tax_id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'active' => $supplier->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function expenseRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return Expense::query()
            ->with(['branch:id,name', 'category:id,name', 'paymentMethod:id,name', 'salaryPayment.worker:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('spent_at', [$from, $to])
            ->latest('spent_at')
            ->limit(5000)
            ->get(['id', 'branch_id', 'expense_category_id', 'payment_method_id', 'spent_at', 'description', 'amount', 'status'])
            ->map(fn (Expense $expense) => [
                'date' => $expense->spent_at?->format('d/m/Y H:i'),
                'branch' => $expense->branch?->name,
                'category' => $expense->category?->name,
                'description' => $expense->description,
                'payment_method' => $expense->paymentMethod?->name,
                'worker' => $expense->salaryPayment?->worker?->name ?? '-',
                'status' => $expense->status === Expense::STATUS_REGISTERED ? 'Registrado' : 'Anulado',
                'amount' => (float) $expense->amount,
            ])
            ->all();
    }

    private function bankAccountRows(Request $request, ?int $branchId): array
    {
        return BankAccount::query()
            ->with('branch:id,name')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->limit(5000)
            ->get(['branch_id', 'bank_name', 'name', 'account_number', 'currency_code', 'current_balance', 'is_active'])
            ->map(fn (BankAccount $account) => [
                'branch' => $account->branch?->name,
                'bank' => $account->bank_name,
                'name' => $account->name,
                'number' => $account->account_number,
                'currency' => $account->currency_code,
                'balance' => (float) $account->current_balance,
                'active' => $account->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function bankTransactionRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return BankTransaction::query()
            ->with(['branch:id,name', 'account:id,name,account_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('transacted_at', [$from, $to])
            ->latest('transacted_at')
            ->limit(5000)
            ->get(['bank_account_id', 'branch_id', 'type', 'transacted_at', 'amount', 'reference', 'description', 'status', 'reconciled_at'])
            ->map(fn (BankTransaction $transaction) => [
                'date' => $transaction->transacted_at?->format('d/m/Y H:i'),
                'branch' => $transaction->branch?->name,
                'account' => trim(($transaction->account?->name ?? '').' '.$transaction->account?->account_number),
                'type' => match ($transaction->type) {
                    BankTransaction::TYPE_DEPOSIT => 'Ingreso',
                    BankTransaction::TYPE_WITHDRAWAL => 'Egreso',
                    default => 'Ajuste',
                },
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'status' => $transaction->status === BankTransaction::STATUS_REGISTERED ? 'Registrado' : 'Anulado',
                'amount' => (float) $transaction->amount,
                'reconciled' => $transaction->reconciled_at ? 'Si' : 'No',
            ])
            ->all();
    }

    private function cashSessionRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return CashRegisterSession::query()
            ->with(['branch:id,name', 'opener:id,name', 'closer:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('opened_at', [$from, $to])
            ->latest('opened_at')
            ->limit(5000)
            ->get()
            ->map(fn (CashRegisterSession $session) => [
                'branch' => $session->branch?->name,
                'opened_by' => $session->opener?->name,
                'closed_by' => $session->closer?->name ?? '-',
                'opened_at' => $session->opened_at?->format('d/m/Y H:i'),
                'closed_at' => $session->closed_at?->format('d/m/Y H:i') ?? '-',
                'opening' => (float) $session->opening_amount,
                'cash_income' => (float) $session->cash_income_amount,
                'cash_expense' => (float) $session->cash_expense_amount,
                'bank_net' => (float) $session->bank_net_amount,
                'expected' => (float) $session->expected_cash_amount,
                'counted' => (float) $session->counted_cash_amount,
                'difference' => (float) $session->difference_amount,
                'status' => $session->status === CashRegisterSession::STATUS_OPEN ? 'Abierta' : 'Cerrada',
            ])
            ->all();
    }

    private function workerRows(Request $request, ?int $branchId): array
    {
        return Worker::query()
            ->with(['branch:id,name', 'user:id,name,email'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->limit(5000)
            ->get()
            ->map(fn (Worker $worker) => [
                'branch' => $worker->branch?->name,
                'name' => $worker->name,
                'document' => $worker->document_number,
                'phone' => $worker->phone,
                'position' => $worker->position,
                'user' => $worker->user ? "{$worker->user->name} ({$worker->user->email})" : 'Sin usuario',
                'hired_at' => $worker->hired_at?->format('d/m/Y'),
                'salary' => (float) $worker->salary_amount,
                'frequency' => $this->salaryFrequency($worker->salary_frequency),
                'active' => $worker->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function payrollRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return SalaryPayment::query()
            ->with(['branch:id,name', 'worker:id,name', 'paymentMethod:id,name'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('paid_at', [$from, $to])
            ->latest('paid_at')
            ->limit(5000)
            ->get()
            ->map(fn (SalaryPayment $payment) => [
                'date' => $payment->paid_at?->format('d/m/Y H:i'),
                'branch' => $payment->branch?->name,
                'worker' => $payment->worker?->name,
                'period' => trim(($payment->period_from?->format('d/m/Y') ?? '-').' / '.($payment->period_to?->format('d/m/Y') ?? '-')),
                'method' => $payment->paymentMethod?->name ?? '-',
                'reference' => $payment->reference,
                'status' => $payment->status === SalaryPayment::STATUS_PAID ? 'Pagado' : 'Anulado',
                'amount' => (float) $payment->amount,
            ])
            ->all();
    }

    private function barcodeTemplateRows(Request $request, ?int $branchId): array
    {
        $allowedBranchIds = $request->user()->isSuperAdministrator()
            ? null
            : $request->user()->accessibleBranchIds();

        return BarcodeLabelTemplate::query()
            ->with('branch:id,name')
            ->when($allowedBranchIds !== null, fn ($query) => $query->where(function ($nested) use ($allowedBranchIds) {
                $nested->whereNull('branch_id')->orWhereIn('branch_id', $allowedBranchIds ?: [-1]);
            }))
            ->when($branchId, fn ($query) => $query->where(function ($nested) use ($branchId) {
                $nested->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (BarcodeLabelTemplate $template) => [
                'branch' => $template->branch?->name ?? 'Global',
                'name' => $template->name,
                'paper_type' => $template->paper_type,
                'width' => (int) $template->label_width_mm,
                'height' => (int) $template->label_height_mm,
                'barcode_height' => (int) $template->barcode_height_mm,
                'font_size' => (int) $template->font_size,
                'default' => $template->is_default ? 'Si' : 'No',
                'active' => $template->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function deliveryRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return DeliveryNote::query()
            ->with(['branch:id,name', 'sale:id,receipt_number', 'driver:id,name', 'truck:id,plate'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('delivered_at', [$from, $to])
            ->latest('delivered_at')
            ->limit(5000)
            ->get()
            ->map(fn (DeliveryNote $delivery) => [
                'branch' => $delivery->branch?->name,
                'sale' => $delivery->sale?->receipt_number,
                'driver' => $delivery->driver?->name ?? $delivery->driver_name ?? '-',
                'truck' => $delivery->truck?->plate ?? $delivery->vehicle_plate ?? '-',
                'date' => $delivery->delivered_at?->format('d/m/Y H:i'),
                'status' => $delivery->status,
                'notes' => $delivery->notes,
            ])
            ->all();
    }

    private function billingInvoiceRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return SiatInvoice::query()
            ->with(['branch:id,name', 'sale:id,receipt_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('issued_at', [$from, $to])
            ->latest('issued_at')
            ->limit(5000)
            ->get(['id', 'sale_id', 'branch_id', 'invoice_number', 'cuf', 'issued_at', 'customer_name', 'customer_document', 'status', 'reception_code', 'total_amount'])
            ->map(fn (SiatInvoice $invoice) => [
                'date' => $invoice->issued_at?->format('d/m/Y H:i'),
                'branch' => $invoice->branch?->name,
                'number' => $invoice->invoice_number,
                'cuf' => $invoice->cuf ?? '-',
                'sale' => $invoice->sale?->receipt_number ?? '-',
                'customer' => $invoice->customer_name,
                'document' => $invoice->customer_document,
                'status' => $this->siatStatusLabel($invoice->status),
                'reception' => $invoice->reception_code ?? '-',
                'total' => (float) $invoice->total_amount,
            ])
            ->all();
    }

    private function billingSettingRows(Request $request, ?int $branchId): array
    {
        return SiatBranchSetting::query()
            ->with('branch:id,name')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('branch_id')
            ->get(['branch_id', 'nit', 'business_name', 'environment_code', 'modality_code', 'siat_branch_code', 'point_of_sale_code', 'is_active'])
            ->map(fn (SiatBranchSetting $setting) => [
                'branch' => $setting->branch?->name,
                'nit' => $setting->nit,
                'business_name' => $setting->business_name,
                'environment' => (int) $setting->environment_code === 1 ? 'Produccion' : 'Piloto',
                'modality' => (int) $setting->modality_code === 1 ? 'Electronica en linea' : 'Computarizada en linea',
                'siat_branch' => $setting->siat_branch_code,
                'point_of_sale' => $setting->point_of_sale_code,
                'active' => $setting->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function billingProductRows(): array
    {
        return SiatProductMapping::query()
            ->with('product:id,name,sku,barcode')
            ->orderBy('product_id')
            ->get(['product_id', 'economic_activity_code', 'sin_product_code', 'unit_measure_code', 'fiscal_description', 'is_invoiceable'])
            ->map(fn (SiatProductMapping $mapping) => [
                'product' => $mapping->product?->name,
                'sku' => $mapping->product?->sku,
                'barcode' => $mapping->product?->barcode,
                'activity' => $mapping->economic_activity_code,
                'sin_product' => $mapping->sin_product_code,
                'unit' => $mapping->unit_measure_code,
                'description' => $mapping->fiscal_description,
                'invoiceable' => $mapping->is_invoiceable ? 'Si' : 'No',
            ])
            ->all();
    }

    private function billingEventRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return SiatSignificantEvent::query()
            ->with('branch:id,name')
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('started_at', [$from, $to])
            ->latest('started_at')
            ->limit(5000)
            ->get(['branch_id', 'event_code', 'started_at', 'ended_at', 'reception_code', 'status', 'description'])
            ->map(fn (SiatSignificantEvent $event) => [
                'branch' => $event->branch?->name,
                'event_code' => $event->event_code,
                'started_at' => $event->started_at?->format('d/m/Y H:i'),
                'ended_at' => $event->ended_at?->format('d/m/Y H:i') ?? '-',
                'reception' => $event->reception_code ?? '-',
                'status' => $event->status,
                'description' => $event->description,
            ])
            ->all();
    }

    private function billingLogRows(Request $request, Carbon $from, Carbon $to, ?int $branchId): array
    {
        return SiatLog::query()
            ->with(['branch:id,name', 'invoice:id,invoice_number'])
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user()))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from, $to])
            ->latest('created_at')
            ->limit(5000)
            ->get(['branch_id', 'siat_invoice_id', 'service', 'operation', 'status', 'message', 'duration_ms', 'created_at'])
            ->map(fn (SiatLog $log) => [
                'date' => $log->created_at?->format('d/m/Y H:i:s'),
                'branch' => $log->branch?->name ?? '-',
                'invoice' => $log->invoice?->invoice_number ?? '-',
                'service' => $log->service,
                'operation' => $log->operation,
                'status' => $log->status,
                'message' => $log->message ?? '-',
                'duration' => $log->duration_ms ?? '-',
            ])
            ->all();
    }

    private function branchRows(Request $request, ?int $branchId): array
    {
        return Branch::query()
            ->when(true, fn ($query) => BranchAccess::apply($query, $request->user(), 'branches.id'))
            ->when($branchId, fn ($query) => $query->whereKey($branchId))
            ->orderBy('name')
            ->limit(5000)
            ->get(['name', 'code', 'barcode', 'point_of_sale_name', 'phone', 'secondary_phone', 'address', 'is_active'])
            ->map(fn (Branch $branch) => [
                'name' => $branch->name,
                'code' => $branch->code,
                'barcode' => $branch->barcode,
                'point_of_sale' => $branch->point_of_sale_name,
                'phone' => $branch->phone,
                'secondary_phone' => $branch->secondary_phone,
                'address' => $branch->address,
                'active' => $branch->is_active ? 'Si' : 'No',
            ])
            ->all();
    }

    private function userRows(Request $request, ?int $branchId): array
    {
        return User::query()
            ->with(['branch:id,name', 'accessibleBranches:id,name', 'roles:id,name'])
            ->withoutSystemSuperadmins()
            ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query->whereKey($request->user()->id))
            ->when($branchId, fn ($query) => $query->where(function ($nested) use ($branchId) {
                $nested->where('branch_id', $branchId)
                    ->orWhereHas('accessibleBranches', fn ($branchQuery) => $branchQuery->whereKey($branchId));
            }))
            ->orderBy('name')
            ->limit(5000)
            ->get(['id', 'branch_id', 'name', 'email', 'is_active', 'force_password_change', 'last_login_at'])
            ->map(fn (User $user) => [
                'name' => $user->name,
                'email' => $user->email,
                'main_branch' => $user->branch?->name ?? '-',
                'branches' => $user->accessibleBranches->pluck('name')->prepend($user->branch?->name)->filter()->unique()->implode(', '),
                'roles' => $user->roles->pluck('name')->map(fn (string $role) => $this->roleLabel($role))->implode(', '),
                'active' => $user->is_active ? 'Si' : 'No',
                'force_password_change' => $user->force_password_change ? 'Si' : 'No',
                'last_login_at' => $user->last_login_at?->format('d/m/Y H:i') ?? '-',
            ])
            ->all();
    }

    private function auditRows(Request $request, Carbon $from, Carbon $to): array
    {
        return Audit::query()
            ->with('user:id,name,email')
            ->where(fn ($query) => $this->withoutSystemUserAudits($query))
            ->when(! $request->user()->isSuperAdministrator(), fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereBetween('created_at', [$from, $to])
            ->latest('id')
            ->limit(5000)
            ->get(['user_id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'created_at'])
            ->map(fn (Audit $audit) => [
                'date' => $audit->created_at?->format('d/m/Y H:i'),
                'user' => $audit->user?->name ?? 'Sistema',
                'event' => $this->eventLabel($audit->event),
                'model' => $this->modelLabel($audit->auditable_type),
                'record' => $audit->auditable_id,
                'description' => "{$this->eventLabel($audit->event)} en {$this->modelLabel($audit->auditable_type)} #{$audit->auditable_id}",
                'ip' => $audit->ip_address ?? '-',
            ])
            ->all();
    }

    private function withoutSystemUserAudits($query)
    {
        $reservedUserIds = User::query()
            ->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', SystemRoles::reserved()))
            ->select('id');

        return $query
            ->where(fn ($nested) => $nested
                ->whereNull('user_id')
                ->orWhereNotIn('user_id', clone $reservedUserIds))
            ->where(fn ($nested) => $nested
                ->where('auditable_type', '!=', User::class)
                ->orWhereNull('auditable_type')
                ->orWhereNotIn('auditable_id', clone $reservedUserIds));
    }

    private function salaryFrequency(?string $frequency): string
    {
        return match ($frequency) {
            'weekly' => 'Semanal',
            'biweekly' => 'Quincenal',
            'monthly' => 'Mensual',
            'custom' => 'Personalizado',
            default => $frequency ?: '-',
        };
    }

    private function roleLabel(string $role): string
    {
        return [
            'sistemasuperadmin' => 'Sistema superadmin',
            'superadmin' => 'Superadministrador',
            'admin' => 'Administrador',
            'manager' => 'Encargado',
            'seller' => 'Vendedor',
            'cashier' => 'Cajero',
        ][$role] ?? $role;
    }

    private function eventLabel(?string $event): string
    {
        return [
            'created' => 'Creacion',
            'updated' => 'Edicion',
            'deleted' => 'Eliminacion',
            'restored' => 'Restauracion',
        ][$event] ?? ucfirst((string) $event);
    }

    private function modelLabel(?string $type): string
    {
        $short = class_basename((string) $type);

        return [
            'Sale' => 'Venta / cotizacion',
            'SaleItem' => 'Detalle de venta',
            'SalePayment' => 'Pago de cliente',
            'Purchase' => 'Compra de mercaderia',
            'PurchasePayment' => 'Pago a proveedor',
            'Product' => 'Producto',
            'ProductCoil' => 'Unidad fisica / lote',
            'ProductBranchStock' => 'Stock por sucursal',
            'InventoryMovement' => 'Movimiento de inventario',
            'InventoryAdjustment' => 'Ajuste de inventario',
            'InventoryTransfer' => 'Transferencia de inventario',
            'CashRegisterSession' => 'Caja',
            'BankAccount' => 'Cuenta bancaria',
            'BankTransaction' => 'Movimiento bancario',
            'Expense' => 'Gasto',
            'Customer' => 'Cliente',
            'Supplier' => 'Proveedor',
            'ReceiptTemplate' => 'Plantilla de comprobante',
            'BusinessProfile' => 'Perfil empresarial aplicado',
            'BusinessProfileDraft' => 'Borrador de perfil empresarial',
            'BusinessProfileVersion' => 'Version anterior de perfil empresarial',
            'SiatInvoice' => 'Factura SIAT',
            'SiatBranchSetting' => 'Configuracion SIAT',
            'SiatProductMapping' => 'Homologacion SIAT de producto',
            'SiatSignificantEvent' => 'Evento significativo SIAT',
            'SiatPackage' => 'Paquete SIAT',
            'User' => 'Usuario',
            'Branch' => 'Sucursal',
        ][$short] ?? $short;
    }

    private function siatStatusLabel(?string $status): string
    {
        return [
            'draft' => 'Borrador',
            'pending' => 'Pendiente',
            'validated' => 'Validada',
            'observed' => 'Observada',
            'contingency' => 'Contingencia',
            'temporary' => 'Recibo temporal',
            'voided' => 'Anulada',
        ][$status] ?? ($status ?: '-');
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
