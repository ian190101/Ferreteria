import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import FormField from '../../../../Shared/Resources/Components/FormField';
import { Head, router, useForm } from '@inertiajs/react';
import { useDecimalFormatter } from '@/Utils/formatters';

export default function Index({
    filters,
    branches = [],
    metrics = {},
    recentSales = [],
    lowStocks = [],
    agingBuckets = {},
    agingReceivables = { data: [], links: [] },
    latestMovements = [],
    profileFeatures = {},
}) {
    const decimalFormat = useDecimalFormatter('finance');
    const { data, setData, get, processing } = useForm({
        branch_id: filters.branch_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        get(route('reports.index'), { preserveScroll: true, preserveState: true });
    };

    const clear = () => {
        router.get(route('reports.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Reportes</h2>}>
            <Head title="Reportes" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Reportes" description="Resumen operativo de ventas, compras, lotes/unidades activas y alertas de stock por rango y sucursal." />

                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={data.from} onChange={(event) => setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={data.to} onChange={(event) => setData('to', event.target.value)} />
                    <div className="flex items-end gap-2 sm:col-span-2">
                        <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clear}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {profileFeatures.sales ? <MetricCard title="Ventas" value={`Bs ${decimalFormat.money(metrics.sales_total ?? 0)}`} detail={`${metrics.sales_count ?? 0} documentos emitidos`} /> : null}
                    {profileFeatures.quotes ? <MetricCard title="Cotizaciones" value={metrics.quotations_count ?? 0} detail="Documentos tipo cotizacion" /> : null}
                    {profileFeatures.purchases ? <MetricCard title="Compras" value={`Bs ${decimalFormat.money(metrics.purchase_total ?? 0)}`} detail={`${metrics.purchase_count ?? 0} ingresos registrados`} /> : null}
                    {profileFeatures.expenses ? <MetricCard title="Gastos" value={`Bs ${decimalFormat.money(metrics.expense_total ?? 0)}`} detail={`${metrics.expense_count ?? 0} egresos registrados`} /> : null}
                    {profileFeatures.inventory ? <MetricCard title="Inventario" value={metrics.active_coils ?? 0} detail={`${metrics.low_stock_count ?? 0} alertas de stock bajo`} tone={Number(metrics.low_stock_count ?? 0) > 0 ? 'warning' : 'default'} /> : null}
                </div>

                <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                    {profileFeatures.sales ? <Panel title="Ventas recientes">
                        <DataTable>
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Numero</th>
                                    <th className="px-4 py-3 font-medium">Cliente</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {recentSales.map((sale) => (
                                    <tr key={sale.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(sale.sold_at)}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{sale.receipt_number}</p>
                                            <p className="text-xs text-slate-500">{documentType(sale.document_type)}</p>
                                        </td>
                                        <td className="px-4 py-3">{sale.customer_name ?? '-'}</td>
                                        <td className="px-4 py-3">{sale.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">{sale.currency?.symbol ?? 'Bs'} {decimalFormat.money(sale.total ?? 0)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </DataTable>
                    </Panel> : null}

                    {profileFeatures.inventory ? <Panel title="Stock bajo">
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {lowStocks.length === 0 ? (
                                <p className="px-4 py-5 text-sm text-slate-500">Sin alertas de stock bajo.</p>
                            ) : lowStocks.map((stock) => (
                                <div key={stock.id} className="grid gap-1 px-4 py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium text-slate-900 dark:text-slate-100">{stock.product?.name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{stock.product?.sku ?? '-'} · {stock.branch?.name ?? '-'}</p>
                                        </div>
                                        <span className="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                            Bajo
                                        </span>
                                    </div>
                                    <p className="text-sm text-slate-600 dark:text-slate-300">
                                        Disponible: {decimalFormat.measure(stock.available_meters ?? 0)} m · Minimo: {decimalFormat.measure(stock.product?.minimum_stock_meters ?? 0)} m
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Panel> : null}
                </div>

                {profileFeatures.payments ? <Panel title="Antiguedad de cuentas por cobrar" className="mt-6">
                    <div className="grid gap-4 border-b border-slate-200 p-4 dark:border-slate-800 sm:grid-cols-2 xl:grid-cols-4">
                        {Object.entries(agingBuckets).map(([key, bucket]) => (
                            <MetricCard
                                key={key}
                                title={bucket.label}
                                value={`Bs ${decimalFormat.money(bucket.total ?? 0)}`}
                                detail={`${bucket.count ?? 0} cuentas`}
                                tone={key === '31_plus' && Number(bucket.count ?? 0) > 0 ? 'warning' : 'default'}
                            />
                        ))}
                    </div>
                    <DataTable>
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Venta</th>
                                <th className="px-4 py-3 font-medium">Cliente</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 text-right font-medium">Saldo</th>
                                <th className="px-4 py-3 text-right font-medium">Dias</th>
                                <th className="px-4 py-3 font-medium">Promesa</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {agingReceivables.data.map((sale) => (
                                <tr key={sale.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-medium">{sale.receipt_number}</p>
                                        <p className="text-xs text-slate-500">{formatDate(sale.sold_at)}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{sale.customer_name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{sale.customer_contact ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{sale.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{sale.currency?.symbol ?? 'Bs'} {decimalFormat.money(sale.balance_due ?? 0)}</td>
                                    <td className="px-4 py-3 text-right">{sale.aging_days}</td>
                                    <td className="px-4 py-3">{sale.next_promise_date ? formatDateOnly(sale.next_promise_date) : '-'}</td>
                                </tr>
                            ))}
                            {agingReceivables.data.length === 0 ? (
                                <tr>
                                    <td className="px-4 py-6 text-center text-slate-500" colSpan="6">
                                        No hay cuentas por cobrar pendientes.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </DataTable>
                    <div className="px-4 py-3">
                        <Pagination links={agingReceivables.links} />
                    </div>
                </Panel> : null}

                {profileFeatures.inventory_lots ? <Panel title="Ultimos lotes/unidades registrados" className="mt-6">
                    <DataTable>
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Barcode</th>
                                <th className="px-4 py-3 font-medium">Lote</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 text-right font-medium">Metros</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {latestMovements.map((coil) => (
                                <tr key={coil.id}>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(coil.created_at)}</td>
                                    <td className="px-4 py-3">
                                        <p className="font-medium">{coil.product?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{coil.product?.sku ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{coil.barcode}</td>
                                    <td className="px-4 py-3">{coil.lot_number}</td>
                                    <td className="px-4 py-3">{coil.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{decimalFormat.measure(coil.available_meters ?? 0)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </DataTable>
                </Panel> : null}
            </section>
        </AuthenticatedLayout>
    );
}

function MetricCard({ title, value, detail, tone = 'default' }) {
    const toneClasses = tone === 'warning'
        ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100'
        : 'border-slate-200 bg-white text-slate-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100';

    return (
        <article className={`rounded-lg border p-5 shadow-sm ${toneClasses}`}>
            <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{title}</p>
            <p className="mt-3 text-2xl font-semibold">{value}</p>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{detail}</p>
        </article>
    );
}

function Panel({ title, className = '', children }) {
    return (
        <section className={`overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900 ${className}`}>
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function DataTable({ children }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                {children}
            </table>
        </div>
    );
}

function documentType(type) {
    return type === 'quotation' ? 'Cotizacion' : 'Nota de venta';
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function formatDateOnly(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
    }).format(new Date(`${value}T00:00:00`));
}
