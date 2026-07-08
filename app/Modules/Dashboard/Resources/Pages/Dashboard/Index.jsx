import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm } from '@inertiajs/react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({
    scope,
    metrics = {},
    recentSales = [],
    pendingReceivables = [],
    lowStocks = [],
    openCashSessions = [],
    charts = {},
    branches = [],
    filters = {},
}) {
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        from: filters.from ?? scope.from ?? '',
        to: filters.to ?? scope.to ?? '',
    });
    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('dashboard'), { preserveScroll: true, preserveState: true });
    };
    const clearFilters = () => router.get(route('dashboard'));
    const isMetricsLoading = Object.keys(metrics).length === 0;
    const visibleMetrics = [
        metric('Ventas del rango', money(metrics.sales_range_total), `${metrics.sales_range_count ?? 0} documentos`, metrics.sales_range_total, 'default', 'sales'),
        metric('Por cobrar', money(metrics.receivables_total), `${metrics.receivables_count ?? 0} notas pendientes`, metrics.receivables_total, 'warning', 'receivables'),
        metric('Cajas abiertas', metrics.open_cash_count, 'Sesiones activas', metrics.open_cash_count, 'default', 'cash'),
        metric('Promesas vencidas', metrics.payment_promises_overdue_count, `${metrics.payment_promises_today_count ?? 0} vencen hoy`, metrics.payment_promises_overdue_count, 'danger', 'promise'),
        metric('Stock bajo', metrics.low_stock_count, 'Productos bajo minimo', metrics.low_stock_count, 'danger', 'stock'),
        metric('Bobinas activas', metrics.active_coils, 'Rastreo individual', metrics.active_coils, 'default', 'coil'),
        metric('Produccion del rango', metrics.production_range_count, 'Ordenes completadas', metrics.production_range_count, 'default', 'production'),
        metric('Compras del rango', money(metrics.purchases_range_total), 'Ingreso de mercaderia', metrics.purchases_range_total, 'default', 'purchase'),
        metric('Compras pagadas', money(metrics.purchase_payments_range_total), 'Egresos por proveedores', metrics.purchase_payments_range_total, 'danger', 'purchase'),
        metric('Gastos del rango', money(metrics.expenses_range_total), 'Egresos registrados', metrics.expenses_range_total, 'danger', 'expense'),
        metric('Ganancia del rango', money(metrics.profit_range_total), 'Ingresos menos compras pagadas y gastos', metrics.profit_range_total, Number(metrics.profit_range_total ?? 0) < 0 ? 'danger' : 'default', 'profit'),
    ].filter((item) => item.available);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Panel</h2>}
        >
            <Head title="Panel" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <ModuleHeader
                        title="Panel operativo"
                        description={`Resumen de ${scope.label.toLowerCase()} desde ${formatDateOnly(scope.from)} hasta ${formatDateOnly(scope.to)}.`}
                    />
                </div>

                <form onSubmit={submitFilters} className="mt-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 xl:grid-cols-[1.2fr_1fr_1fr_auto]">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas las permitidas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clearFilters}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {isMetricsLoading
                        ? Array.from({ length: 4 }).map((_, index) => <MetricSkeleton key={index} />)
                        : visibleMetrics.map((item) => (
                            <MetricCard key={item.title} item={item} />
                        ))}
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[1.2fr_1fr]">
                    <ChartPanel title="Tendencia de ventas" subtitle="Ultimos 7 dias en bolivianos">
                        <LineChart data={charts.salesTrend ?? []} color="rgb(var(--color-primary))" valuePrefix="Bs " />
                    </ChartPanel>

                    <ChartPanel title="Stock por producto" subtitle="Disponible por unidad configurada del producto">
                        <HorizontalBarChart data={charts.stockByProduct ?? []} color="#22c55e" unit="" />
                    </ChartPanel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[1.35fr_0.9fr]">
                    <ChartPanel title="Ingresos, compras, gastos y ganancia" subtitle="Comparativo financiero segun filtros aplicados">
                        <IncomeExpenseProfitChart data={charts.incomeExpenseProfitTrend ?? []} />
                    </ChartPanel>

                    <ChartPanel title="Ganancia por sucursal" subtitle="Ranking dentro del rango filtrado">
                        <BranchProfitChart data={charts.profitByBranch ?? []} />
                    </ChartPanel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-3">
                    <ChartPanel title="Productos mas vendidos" subtitle="Metros vendidos en los ultimos 30 dias">
                        <VerticalBarChart data={charts.topProducts ?? []} color="#3b82f6" unit=" m" />
                    </ChartPanel>

                    <ChartPanel title="Compras pagadas vs gastos" subtitle="Egresos reales segun pagos registrados">
                        <GroupedBarChart data={charts.cashFlowTrend ?? []} />
                    </ChartPanel>

                    <ChartPanel title="Antiguedad por cobrar" subtitle="Saldo pendiente por rango de dias">
                        <DonutChart data={charts.receivablesAging ?? []} />
                    </ChartPanel>
                </div>

                <div className="mt-6">
                    <Panel title="Ganancia de caja por sucursal y dia">
                        <CashProfitTable rows={charts.cashProfitByBranchDay ?? []} />
                    </Panel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[1.25fr_1fr]">
                    <Panel title="Ventas recientes">
                        <SalesList sales={recentSales} />
                    </Panel>

                    <Panel title="Cuentas por cobrar">
                        <ReceivableList sales={pendingReceivables} />
                    </Panel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_1fr]">
                    <Panel title="Stock bajo">
                        <LowStockList stocks={lowStocks} />
                    </Panel>

                    <Panel title="Caja abierta">
                        <CashList sessions={openCashSessions} />
                    </Panel>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function CashProfitTable({ rows }) {
    if (rows.length === 0) {
        return <EmptyState text="Sin ingresos o gastos en efectivo en los ultimos 7 dias." />;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th className="px-4 py-3 font-medium">Dia</th>
                        <th className="px-4 py-3 font-medium">Sucursal</th>
                        <th className="px-4 py-3 text-right font-medium">Ingresos efectivo</th>
                        <th className="px-4 py-3 text-right font-medium">Egresos efectivo</th>
                        <th className="px-4 py-3 text-right font-medium">Ganancia neta</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map((row) => (
                        <tr key={`${row.date}-${row.branch_id}`}>
                            <td className="whitespace-nowrap px-4 py-3">{formatDateOnly(row.date)}</td>
                            <td className="px-4 py-3">{row.branch_name}</td>
                            <td className="px-4 py-3 text-right text-emerald-700 dark:text-emerald-300">Bs {moneyFormatter.format(Number(row.income ?? 0))}</td>
                            <td className="px-4 py-3 text-right text-red-700 dark:text-red-300">Bs {moneyFormatter.format(Number(row.outflows ?? row.expenses ?? 0))}</td>
                            <td className={`px-4 py-3 text-right font-semibold ${Number(row.profit ?? 0) < 0 ? 'text-red-700 dark:text-red-300' : 'text-slate-900 dark:text-slate-100'}`}>
                                Bs {moneyFormatter.format(Number(row.profit ?? 0))}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ChartPanel({ title, subtitle, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-4">
                <h3 className="font-semibold text-slate-950 dark:text-slate-50">{title}</h3>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>
            </div>
            {children}
        </section>
    );
}

function LineChart({ data, color, valuePrefix = '' }) {
    const points = normalizedPoints(data);
    const max = Math.max(...data.map((item) => Number(item.value ?? 0)), 1);

    if (!hasChartData(data)) {
        return <EmptyChart text="Sin datos para graficar." />;
    }

    const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');

    return (
        <div className="h-72 w-full">
            <svg viewBox="0 0 640 300" role="img" aria-label="Grafico de tendencia de ventas" className="h-full w-full overflow-visible">
                <ChartGrid />
                <path d={path} fill="none" stroke={color} strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
                {points.map((point) => (
                    <g key={point.label}>
                        <circle cx={point.x} cy={point.y} r="5" fill="white" stroke={color} strokeWidth="4" />
                        <title>{`${point.label}: ${valuePrefix}${moneyFormatter.format(point.value)}`}</title>
                    </g>
                ))}
                {points.map((point, index) => (
                    <text key={`${point.label}-${index}`} x={point.x} y="286" textAnchor="middle" className="fill-slate-500 text-[18px] dark:fill-slate-400">
                        {point.label}
                    </text>
                ))}
                <text x="42" y="36" className="fill-slate-500 text-[16px] dark:fill-slate-400">
                    {valuePrefix}{moneyFormatter.format(max)}
                </text>
            </svg>
        </div>
    );
}

function HorizontalBarChart({ data, color, unit = '' }) {
    const cleanData = data.filter((item) => Number(item.value ?? 0) > 0).slice(0, 8);
    const max = Math.max(...cleanData.map((item) => Number(item.value)), 1);

    if (cleanData.length === 0) {
        return <EmptyChart text="Sin stock disponible para graficar." />;
    }

    return (
        <div className="space-y-3">
            {cleanData.map((item) => {
                const width = Math.max((Number(item.value) / max) * 100, 3);

                return (
                    <div key={item.label} className="grid gap-2 sm:grid-cols-[140px_1fr_auto] sm:items-center">
                        <p className="truncate text-sm font-medium text-slate-600 dark:text-slate-300">{item.label}</p>
                        <div className="h-4 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div className="h-full rounded-full" style={{ width: `${width}%`, backgroundColor: color }} />
                        </div>
                        <p className="text-right text-sm font-semibold text-slate-900 dark:text-slate-100">{numberFormatter.format(Number(item.value))} {item.unit ?? unit}</p>
                    </div>
                );
            })}
        </div>
    );
}

function VerticalBarChart({ data, color, unit = '' }) {
    const cleanData = data.filter((item) => Number(item.value ?? 0) > 0).slice(0, 6);
    const max = Math.max(...cleanData.map((item) => Number(item.value)), 1);

    if (cleanData.length === 0) {
        return <EmptyChart text="Sin ventas de productos para graficar." />;
    }

    return (
        <div className="grid h-64 grid-cols-6 items-end gap-3 border-b border-slate-200 pb-2 dark:border-slate-800">
            {cleanData.map((item) => {
                const height = Math.max((Number(item.value) / max) * 100, 5);

                return (
                    <div key={item.label} className="flex h-full flex-col justify-end gap-2">
                        <div className="flex flex-1 items-end rounded-t-md bg-slate-100 dark:bg-slate-800">
                            <div className="w-full rounded-t-md" style={{ height: `${height}%`, backgroundColor: color }}>
                                <span className="sr-only">{`${item.label}: ${numberFormatter.format(Number(item.value))}${unit}`}</span>
                            </div>
                        </div>
                        <p className="truncate text-center text-xs text-slate-500 dark:text-slate-400" title={item.label}>{item.label}</p>
                    </div>
                );
            })}
        </div>
    );
}

function IncomeExpenseProfitChart({ data }) {
    const hasData = data.some((item) => Number(item.income ?? 0) > 0 || Number(item.purchases ?? 0) > 0 || Number(item.expenses ?? 0) > 0 || Number(item.profit ?? 0) !== 0);
    const max = Math.max(...data.flatMap((item) => [
        Math.abs(Number(item.income ?? 0)),
        Math.abs(Number(item.purchases ?? 0)),
        Math.abs(Number(item.expenses ?? 0)),
        Math.abs(Number(item.profit ?? 0)),
    ]), 1);
    const points = data.map((item, index) => {
        const step = data.length > 1 ? 568 / (data.length - 1) : 568;

        return {
            label: item.label,
            value: Number(item.profit ?? 0),
            x: 52 + (index * step),
            y: 150 - ((Number(item.profit ?? 0) / max) * 90),
        };
    });
    const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');

    if (!hasData) {
        return <EmptyChart text="Sin ingresos ni egresos para el rango seleccionado." />;
    }

    return (
        <div>
            <div className="h-80 w-full">
                <svg viewBox="0 0 640 320" role="img" aria-label="Grafico de ingresos, egresos y ganancias" className="h-full w-full overflow-visible">
                    <ChartGrid />
                    <line x1="52" x2="620" y1="150" y2="150" stroke="currentColor" className="text-slate-300 dark:text-slate-700" />
                    {data.map((item, index) => {
                        const step = data.length > 1 ? 568 / (data.length - 1) : 568;
                        const x = 52 + (index * step);
                        const incomeHeight = Math.max((Number(item.income ?? 0) / max) * 90, Number(item.income ?? 0) > 0 ? 3 : 0);
                        const purchaseHeight = Math.max((Number(item.purchases ?? 0) / max) * 90, Number(item.purchases ?? 0) > 0 ? 3 : 0);
                        const expenseHeight = Math.max((Number(item.expenses ?? 0) / max) * 90, Number(item.expenses ?? 0) > 0 ? 3 : 0);

                        return (
                            <g key={item.label}>
                                <rect x={x - 20} y={150 - incomeHeight} width="10" height={incomeHeight} rx="3" fill="#22c55e" />
                                <rect x={x - 5} y={150} width="10" height={purchaseHeight} rx="3" fill="#f97316" />
                                <rect x={x + 10} y={150} width="10" height={expenseHeight} rx="3" fill="#ef4444" />
                                <text x={x} y="286" textAnchor="middle" className="fill-slate-500 text-[16px] dark:fill-slate-400">{item.label}</text>
                                <title>{`${item.label}: Ingresos Bs ${moneyFormatter.format(Number(item.income ?? 0))}, Compras Bs ${moneyFormatter.format(Number(item.purchases ?? 0))}, Gastos Bs ${moneyFormatter.format(Number(item.expenses ?? 0))}, Ganancia Bs ${moneyFormatter.format(Number(item.profit ?? 0))}`}</title>
                            </g>
                        );
                    })}
                    <path d={path} fill="none" stroke="#2563eb" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
                    {points.map((point) => (
                        <circle key={`${point.label}-profit`} cx={point.x} cy={point.y} r="4" fill="white" stroke="#2563eb" strokeWidth="3" />
                    ))}
                </svg>
            </div>
            <div className="mt-3 flex flex-wrap gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-emerald-500" />Ingresos</span>
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-orange-500" />Compras pagadas</span>
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-red-500" />Gastos</span>
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-blue-600" />Ganancia</span>
            </div>
        </div>
    );
}

function BranchProfitChart({ data }) {
    const cleanData = data
        .filter((item) => Number(item.income ?? 0) > 0 || Number(item.outflows ?? item.expenses ?? 0) > 0 || Number(item.profit ?? 0) !== 0)
        .slice(0, 8);
    const max = Math.max(...cleanData.map((item) => Math.abs(Number(item.profit ?? 0))), 1);

    if (cleanData.length === 0) {
        return <EmptyChart text="Sin ganancias por sucursal para el rango seleccionado." />;
    }

    return (
        <div className="space-y-4">
            {cleanData.map((item, index) => {
                const profit = Number(item.profit ?? 0);
                const width = Math.max((Math.abs(profit) / max) * 100, 4);
                const positive = profit >= 0;

                return (
                    <div key={item.branch_id ?? item.label} className="space-y-2">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    {index + 1}. {item.label}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Ingresos Bs {moneyFormatter.format(Number(item.income ?? 0))} - Egresos Bs {moneyFormatter.format(Number(item.outflows ?? item.expenses ?? 0))}
                                    <span className="block">Compras Bs {moneyFormatter.format(Number(item.purchases ?? 0))} - Gastos Bs {moneyFormatter.format(Number(item.expenses ?? 0))}</span>
                                </p>
                            </div>
                            <p className={`whitespace-nowrap text-sm font-bold ${positive ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`}>
                                Bs {moneyFormatter.format(profit)}
                            </p>
                        </div>
                        <div className="h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div
                                className={`h-full rounded-full ${positive ? 'bg-emerald-500' : 'bg-red-500'}`}
                                style={{ width: `${width}%` }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function GroupedBarChart({ data }) {
    const max = Math.max(...data.flatMap((item) => [Number(item.purchases ?? 0), Number(item.expenses ?? 0)]), 1);

    if (!data.some((item) => Number(item.purchases ?? 0) > 0 || Number(item.expenses ?? 0) > 0)) {
        return <EmptyChart text="Sin compras ni gastos recientes." />;
    }

    return (
        <div>
            <div className="grid h-56 grid-cols-7 items-end gap-3 border-b border-slate-200 pb-2 dark:border-slate-800">
                {data.map((item) => (
                    <div key={item.label} className="flex h-full flex-col justify-end gap-2">
                        <div className="flex flex-1 items-end justify-center gap-1 rounded-t-md bg-slate-100 px-1 dark:bg-slate-800">
                            <div className="w-3 rounded-t bg-blue-500" style={{ height: `${Math.max((Number(item.purchases ?? 0) / max) * 100, Number(item.purchases ?? 0) > 0 ? 4 : 0)}%` }} />
                            <div className="w-3 rounded-t bg-red-500" style={{ height: `${Math.max((Number(item.expenses ?? 0) / max) * 100, Number(item.expenses ?? 0) > 0 ? 4 : 0)}%` }} />
                        </div>
                        <p className="text-center text-xs text-slate-500 dark:text-slate-400">{item.label}</p>
                    </div>
                ))}
            </div>
            <div className="mt-3 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-blue-500" />Compras pagadas</span>
                <span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-red-500" />Gastos</span>
            </div>
        </div>
    );
}

function DonutChart({ data }) {
    const cleanData = data.filter((item) => Number(item.value ?? 0) > 0);
    const total = cleanData.reduce((sum, item) => sum + Number(item.value), 0);
    const colors = ['#22c55e', '#f59e0b', '#ef4444'];
    let offset = 25;

    if (total <= 0) {
        return <EmptyChart text="Sin cuentas por cobrar pendientes." />;
    }

    return (
        <div className="grid gap-4 sm:grid-cols-[150px_1fr] sm:items-center">
            <svg viewBox="0 0 120 120" className="mx-auto h-40 w-40 -rotate-90">
                <circle cx="60" cy="60" r="42" fill="none" stroke="currentColor" strokeWidth="18" className="text-slate-100 dark:text-slate-800" />
                {cleanData.map((item, index) => {
                    const percent = (Number(item.value) / total) * 100;
                    const dash = `${percent} ${100 - percent}`;
                    const currentOffset = offset;
                    offset -= percent;

                    return (
                        <circle key={item.label} cx="60" cy="60" r="42" fill="none" stroke={colors[index % colors.length]} strokeWidth="18" strokeDasharray={dash} strokeDashoffset={currentOffset} pathLength="100" />
                    );
                })}
            </svg>
            <div className="space-y-2">
                <p className="text-sm text-slate-500 dark:text-slate-400">Total pendiente</p>
                <p className="text-2xl font-semibold text-slate-950 dark:text-slate-50">Bs {moneyFormatter.format(total)}</p>
                {cleanData.map((item, index) => (
                    <div key={item.label} className="flex items-center justify-between gap-3 text-sm">
                        <span className="inline-flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: colors[index % colors.length] }} />
                            {item.label}
                        </span>
                        <span className="font-semibold text-slate-900 dark:text-slate-100">Bs {moneyFormatter.format(Number(item.value))}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ChartGrid() {
    return (
        <g>
            {[60, 120, 180, 240].map((y) => (
                <line key={y} x1="52" x2="620" y1={y} y2={y} stroke="currentColor" strokeDasharray="8 8" className="text-slate-200 dark:text-slate-800" />
            ))}
            <line x1="52" x2="52" y1="30" y2="260" stroke="currentColor" className="text-slate-300 dark:text-slate-700" />
            <line x1="52" x2="620" y1="260" y2="260" stroke="currentColor" className="text-slate-300 dark:text-slate-700" />
        </g>
    );
}

function normalizedPoints(data) {
    const max = Math.max(...data.map((item) => Number(item.value ?? 0)), 1);
    const width = 568;
    const step = data.length > 1 ? width / (data.length - 1) : width;

    return data.map((item, index) => ({
        label: item.label,
        value: Number(item.value ?? 0),
        x: 52 + (index * step),
        y: 260 - ((Number(item.value ?? 0) / max) * 220),
    }));
}

function hasChartData(data) {
    return data.some((item) => Number(item.value ?? 0) > 0);
}

function EmptyChart({ text }) {
    return (
        <div className="flex h-56 items-center justify-center rounded-md border border-dashed border-slate-200 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
            {text}
        </div>
    );
}

function metric(title, value, detail, rawValue, tone = 'default', icon = 'default') {
    return {
        title,
        value,
        detail,
        tone,
        icon,
        available: rawValue !== null && rawValue !== undefined,
    };
}

function MetricCard({ item }) {
    const toneClasses = {
        default: 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
        warning: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950',
        danger: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950',
    }[item.tone];
    const iconClasses = {
        default: 'bg-brand-primary/10 text-brand-primary',
        warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
        danger: 'bg-red-500/10 text-red-600 dark:text-red-300',
    }[item.tone];

    return (
        <article className={`rounded-lg border p-5 shadow-sm ${toneClasses}`}>
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{item.title}</p>
                <span className={`inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl ${iconClasses}`}>
                    <MetricIcon name={item.icon} />
                </span>
            </div>
            <p className="mt-4 text-2xl font-semibold text-slate-950 dark:text-slate-50">{item.value}</p>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{item.detail}</p>
        </article>
    );
}

function MetricSkeleton() {
    return (
        <article className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900" aria-label="Cargando metrica">
            <div className="flex items-start justify-between gap-3">
                <span className="h-4 w-28 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
                <span className="h-10 w-10 animate-pulse rounded-2xl bg-slate-200 dark:bg-slate-800" />
            </div>
            <span className="mt-5 block h-7 w-32 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
            <span className="mt-3 block h-4 w-24 animate-pulse rounded bg-slate-200 dark:bg-slate-800" />
        </article>
    );
}

function MetricIcon({ name }) {
    const paths = {
        sales: <><path d="M4 19V5" /><path d="M4 19h16" /><path d="M8 15l3-4 3 2 5-7" /></>,
        receivables: <><path d="M4 7h16v10H4z" /><path d="M8 11h4" /><path d="M16 11h.01" /></>,
        cash: <><path d="M5 7h14v10H5z" /><path d="M9 7a3 3 0 0 1-3 3" /><path d="M18 14a3 3 0 0 0-3 3" /><circle cx="12" cy="12" r="2" /></>,
        promise: <><path d="M7 3v3" /><path d="M17 3v3" /><path d="M4 8h16" /><rect x="4" y="5" width="16" height="16" rx="2" /><path d="M9 14l2 2 4-5" /></>,
        stock: <><path d="M4 7l8-4 8 4-8 4z" /><path d="M4 7v10l8 4 8-4V7" /><path d="M12 11v10" /></>,
        coil: <><circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3" /><path d="M12 4v5" /><path d="M12 15v5" /></>,
        production: <><path d="M4 17h16" /><path d="M6 17V9l4 3V9l4 3V7h4v10" /><path d="M8 21h8" /></>,
        purchase: <><path d="M6 6h15l-2 8H8z" /><path d="M6 6l-1-3H2" /><circle cx="9" cy="20" r="1" /><circle cx="18" cy="20" r="1" /></>,
        expense: <><path d="M12 3v18" /><path d="M17 8c0-2-2-3-5-3s-5 1-5 3 2 3 5 3 5 1 5 3-2 3-5 3-5-1-5-3" /></>,
        profit: <><path d="M4 18l6-6 4 4 6-9" /><path d="M15 7h5v5" /></>,
        default: <><path d="M4 19V5" /><path d="M4 19h16" /></>,
    };

    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            {paths[name] ?? paths.default}
        </svg>
    );
}

function Panel({ title, children }) {
    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function SalesList({ sales }) {
    if (sales.length === 0) {
        return <EmptyState text="Sin ventas recientes visibles." />;
    }

    return (
        <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {sales.map((sale) => (
                <div key={sale.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto]">
                    <div>
                        <Link className="font-semibold text-brand-primary hover:underline" href={route('sales.show', sale.id)}>
                            {sale.receipt_number}
                        </Link>
                        <p className="text-sm text-slate-600 dark:text-slate-300">{sale.customer_name ?? 'Consumidor final'}</p>
                        <p className="text-xs text-slate-500">{sale.branch?.name ?? '-'} - {documentType(sale.document_type)} - {formatDate(sale.sold_at)}</p>
                    </div>
                    <p className="text-right font-semibold text-slate-900 dark:text-slate-100">
                        {sale.currency?.symbol ?? 'Bs'} {moneyFormatter.format(Number(sale.total ?? 0))}
                    </p>
                </div>
            ))}
        </div>
    );
}

function ReceivableList({ sales }) {
    if (sales.length === 0) {
        return <EmptyState text="Sin saldos pendientes visibles." />;
    }

    return (
        <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {sales.map((sale) => (
                <div key={sale.id} className="grid gap-1 px-4 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <Link className="font-semibold text-brand-primary hover:underline" href={route('sales.show', sale.id)}>
                                {sale.receipt_number}
                            </Link>
                            <p className="text-sm text-slate-600 dark:text-slate-300">{sale.customer_name ?? 'Consumidor final'}</p>
                        </div>
                        <p className="whitespace-nowrap font-semibold text-amber-700 dark:text-amber-200">
                            {sale.currency?.symbol ?? 'Bs'} {moneyFormatter.format(Number(sale.balance_due ?? 0))}
                        </p>
                    </div>
                    <p className="text-xs text-slate-500">{sale.branch?.name ?? '-'} - {formatDate(sale.sold_at)}</p>
                </div>
            ))}
        </div>
    );
}

function LowStockList({ stocks }) {
    if (stocks.length === 0) {
        return <EmptyState text="Sin alertas de stock bajo." />;
    }

    return (
        <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {stocks.map((stock) => (
                <div key={stock.id} className="px-4 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-semibold text-slate-900 dark:text-slate-100">{stock.product?.name ?? '-'}</p>
                            <p className="text-xs text-slate-500">{stock.product?.sku ?? '-'} - {stock.branch?.name ?? '-'}</p>
                        </div>
                        <span className="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-950 dark:text-red-200">
                            Bajo
                        </span>
                    </div>
                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        {numberFormatter.format(Number(stock.available_meters ?? 0))} {unitLabel(stock.product?.base_unit)} disponibles - minimo {numberFormatter.format(Number(stock.product?.minimum_stock_meters ?? 0))} {unitLabel(stock.product?.base_unit)}
                    </p>
                </div>
            ))}
        </div>
    );
}

function CashList({ sessions }) {
    if (sessions.length === 0) {
        return <EmptyState text="No hay cajas abiertas visibles." />;
    }

    return (
        <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {sessions.map((session) => (
                <div key={session.id} className="grid gap-1 px-4 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-semibold text-slate-900 dark:text-slate-100">{session.branch?.name ?? '-'}</p>
                            <p className="text-sm text-slate-600 dark:text-slate-300">Abierta por {session.opener?.name ?? '-'}</p>
                        </div>
                        <p className="text-right font-semibold text-slate-900 dark:text-slate-100">
                            Bs {moneyFormatter.format(Number(session.expected_cash_amount ?? session.opening_amount ?? 0))}
                        </p>
                    </div>
                    <p className="text-xs text-slate-500">{formatDate(session.opened_at)}</p>
                </div>
            ))}
        </div>
    );
}

function EmptyState({ text }) {
    return <p className="px-4 py-5 text-sm text-slate-500 dark:text-slate-400">{text}</p>;
}

function money(value) {
    return value === null || value === undefined ? null : `Bs ${moneyFormatter.format(Number(value ?? 0))}`;
}

function documentType(type) {
    return type === 'quotation' ? 'Cotizacion' : 'Nota de venta';
}

function unitLabel(unit) {
    return {
        m: 'm',
        unidad: 'unid.',
        caja: 'cajas',
        paquete: 'paquetes',
        kg: 'kg',
        ton: 'ton',
        lt: 'lt',
        galon: 'gal.',
        rollo: 'rollos',
    }[unit] ?? 'unid.';
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
        dateStyle: 'medium',
    }).format(new Date(`${value}T00:00:00`));
}
