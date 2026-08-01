import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Index({
    movements,
    summary,
    bySource = [],
    byBranch = [],
    byMethod = [],
    filters,
    branches = [],
    paymentMethods = [],
    canViewAllBranches = false,
}) {
    const decimalFormat = useDecimalFormatter('finance');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        payment_method_id: filters.payment_method_id ?? '',
        type: filters.type ?? 'all',
        source: filters.source ?? 'all',
        smart_filter: filters.smart_filter ?? '',
        search: filters.search ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 25,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('cash-flow.index'), { preserveScroll: true, preserveState: true });
    };

    const applySmartFilter = (value) => {
        router.get(route('cash-flow.index'), { ...filterForm.data, smart_filter: value }, { preserveScroll: true, preserveState: true });
    };

    const clear = () => {
        router.get(route('cash-flow.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Flujo de efectivo</h2>}>
            <Head title="Flujo de efectivo" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Flujo de efectivo"
                    description="Ingresos, egresos, resultado neto y detalle de movimientos por sucursal, fecha, origen y metodo de pago."
                />

                <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard title="Ingresos" value={`Bs ${decimalFormat.money(summary.income ?? 0)}`} detail={`${summary.income_count ?? 0} entradas registradas`} tone="income" />
                    <MetricCard title="Egresos" value={`Bs ${decimalFormat.money(summary.expense ?? 0)}`} detail={`${summary.expense_count ?? 0} salidas registradas`} tone="expense" />
                    <MetricCard title="Resultado neto" value={`Bs ${decimalFormat.money(summary.net ?? 0)}`} detail="Ingresos menos egresos del rango filtrado" tone={Number(summary.net ?? 0) >= 0 ? 'income' : 'expense'} />
                    <MetricCard title="Movimientos" value={summary.count ?? 0} detail={`Promedio ingreso Bs ${decimalFormat.money(summary.average_income ?? 0)} · egreso Bs ${decimalFormat.money(summary.average_expense ?? 0)}`} />
                </div>

                <div className="mb-6 flex flex-wrap gap-2">
                    {[
                        ['today', 'Hoy'],
                        ['this_week', 'Esta semana'],
                        ['this_month', 'Este mes'],
                        ['last_month', 'Mes anterior'],
                        ['high_value', 'Montos altos'],
                        ['without_reference', 'Sin referencia'],
                    ].map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => applySmartFilter(value)}
                            className={[
                                'rounded-full border px-3 py-1.5 text-sm font-semibold transition',
                                filterForm.data.smart_filter === value
                                    ? 'border-brand-primary bg-brand-primary text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-brand-primary hover:text-brand-primary dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
                            ].join(' ')}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 xl:grid-cols-8">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)} disabled={!canViewAllBranches && branches.length <= 1}>
                        <option value="">Todas permitidas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Tipo" name="type" value={filterForm.data.type} onChange={(event) => filterForm.setData('type', event.target.value)}>
                        <option value="all">Ingresos y egresos</option>
                        <option value="income">Solo ingresos</option>
                        <option value="expense">Solo egresos</option>
                    </SelectField>
                    <SelectField label="Origen" name="source" value={filterForm.data.source} onChange={(event) => filterForm.setData('source', event.target.value)}>
                        <option value="all">Todos</option>
                        <option value="sales">Cobros clientes</option>
                        <option value="purchases">Pagos proveedores</option>
                        <option value="expenses">Gastos</option>
                        <option value="banks">Banco manual</option>
                    </SelectField>
                    <SelectField label="Metodo" name="payment_method_id" value={filterForm.data.payment_method_id} onChange={(event) => filterForm.setData('payment_method_id', event.target.value)}>
                        <option value="">Todos</option>
                        {paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => {
                        filterForm.setData({ ...filterForm.data, from: event.target.value, smart_filter: '' });
                    }} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => {
                        filterForm.setData({ ...filterForm.data, to: event.target.value, smart_filter: '' });
                    }} />
                    <FormField label="Buscar" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} placeholder="Documento, cliente, referencia" />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clear}>
                            Limpiar
                        </button>
                    </div>
                    <p className="text-sm text-slate-500 dark:text-slate-400 sm:col-span-2 xl:col-span-8">
                        Si no eliges fechas, se muestra todo el historial permitido. Usa los botones rapidos para revisar periodos concretos como hoy, esta semana o este mes.
                    </p>
                </form>

                <div className="mb-6 grid gap-6 xl:grid-cols-3">
                    <Breakdown title="Por flujo" rows={bySource} formatter={decimalFormat} />
                    <Breakdown title="Por sucursal" rows={byBranch} formatter={decimalFormat} />
                    <Breakdown title="Por metodo/cuenta" rows={byMethod} formatter={decimalFormat} />
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Flujo</th>
                                    <th className="px-4 py-3 font-medium">Documento / detalle</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 font-medium">Metodo</th>
                                    <th className="px-4 py-3 text-right font-medium">Ingreso</th>
                                    <th className="px-4 py-3 text-right font-medium">Egreso</th>
                                    <th className="px-4 py-3 text-right font-medium">Neto</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {movements.data.map((movement) => (
                                    <tr key={movement.key} className="align-top">
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(movement.date)}</td>
                                        <td className="px-4 py-3">
                                            <span className={[
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                                movement.type === 'income'
                                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200'
                                                    : 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-200',
                                            ].join(' ')}>
                                                {movement.source_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {movement.related_url ? (
                                                <Link href={movement.related_url} className="font-semibold text-brand-primary hover:underline">
                                                    {movement.document}
                                                </Link>
                                            ) : (
                                                <p className="font-semibold">{movement.document}</p>
                                            )}
                                            <p className="mt-1 text-xs text-slate-500">{movement.description}</p>
                                            {movement.reference ? <p className="mt-1 text-xs text-slate-400">Ref: {movement.reference}</p> : null}
                                        </td>
                                        <td className="px-4 py-3">{movement.branch_name}</td>
                                        <td className="px-4 py-3">{movement.method_name}</td>
                                        <td className="px-4 py-3 text-right text-emerald-700 dark:text-emerald-300">
                                            {movement.type === 'income' ? `Bs ${decimalFormat.money(movement.amount)}` : '-'}
                                        </td>
                                        <td className="px-4 py-3 text-right text-rose-700 dark:text-rose-300">
                                            {movement.type === 'expense' ? `Bs ${decimalFormat.money(movement.amount)}` : '-'}
                                        </td>
                                        <td className={[
                                            'px-4 py-3 text-right font-semibold',
                                            Number(movement.signed_amount) >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300',
                                        ].join(' ')}>
                                            Bs {decimalFormat.money(movement.signed_amount)}
                                        </td>
                                    </tr>
                                ))}
                                {movements.data.length === 0 ? (
                                    <tr>
                                        <td colSpan="8" className="px-4 py-8 text-center text-slate-500">
                                            No hay movimientos para los filtros seleccionados.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={movements.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function MetricCard({ title, value, detail, tone = 'default' }) {
    const toneClass = {
        income: 'text-emerald-700 dark:text-emerald-300',
        expense: 'text-rose-700 dark:text-rose-300',
        default: 'text-slate-950 dark:text-white',
    }[tone] ?? 'text-slate-950 dark:text-white';

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{title}</p>
            <p className={`mt-2 text-2xl font-bold ${toneClass}`}>{value}</p>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{detail}</p>
        </div>
    );
}

function Breakdown({ title, rows, formatter }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
            <div className="mt-4 space-y-3">
                {rows.slice(0, 6).map((row) => (
                    <div key={row.label} className="grid grid-cols-[1fr_auto] gap-3 text-sm">
                        <div className="min-w-0">
                            <p className="truncate font-medium text-slate-800 dark:text-slate-100">{row.label}</p>
                            <p className="text-xs text-slate-500">{row.count} movimientos · In Bs {formatter.money(row.income)} · Eg Bs {formatter.money(row.expense)}</p>
                        </div>
                        <p className={Number(row.net) >= 0 ? 'font-semibold text-emerald-700 dark:text-emerald-300' : 'font-semibold text-rose-700 dark:text-rose-300'}>
                            Bs {formatter.money(row.net)}
                        </p>
                    </div>
                ))}
                {rows.length === 0 ? <p className="text-sm text-slate-500">Sin datos en este rango.</p> : null}
            </div>
        </div>
    );
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
