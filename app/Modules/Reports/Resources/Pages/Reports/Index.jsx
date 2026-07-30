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
    profitByProduct = [],
    profitBySeller = [],
    restaurantSummary = [],
    serviceOrderSummary = [],
    reservationSummary = [],
    productionSummary = [],
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
                    {profileFeatures.sales ? <MetricCard title="Utilidad bruta" value={`Bs ${decimalFormat.money(metrics.gross_profit ?? 0)}`} detail={`Margen ${decimalFormat.money(metrics.gross_margin_percent ?? 0)}%`} tone={Number(metrics.gross_profit ?? 0) < 0 ? 'warning' : 'default'} /> : null}
                    {profileFeatures.restaurant ? <MetricCard title="Restaurante" value={metrics.kitchen_orders_count ?? 0} detail={`${metrics.kitchen_pending_count ?? 0} comandas pendientes · ${metrics.restaurant_tables_count ?? 0} mesas`} tone={Number(metrics.kitchen_pending_count ?? 0) > 0 ? 'warning' : 'default'} /> : null}
                    {profileFeatures.service_orders ? <MetricCard title="Servicios" value={metrics.service_orders_count ?? 0} detail={`${metrics.service_orders_open_count ?? 0} ordenes abiertas · Bs ${decimalFormat.money(metrics.service_orders_total ?? 0)}`} /> : null}
                    {profileFeatures.reservations ? <MetricCard title="Reservas" value={metrics.reservations_count ?? 0} detail={`Total Bs ${decimalFormat.money(metrics.reservation_total ?? 0)}`} /> : null}
                    {profileFeatures.rentals ? <MetricCard title="Alquileres" value={metrics.rentals_count ?? 0} detail={`Garantias Bs ${decimalFormat.money(metrics.rental_deposit_total ?? 0)}`} /> : null}
                    {profileFeatures.production ? <MetricCard title="Produccion" value={metrics.production_orders_count ?? 0} detail={`Costo Bs ${decimalFormat.money(metrics.production_total_cost ?? 0)} · Merma ${decimalFormat.measure(metrics.production_waste_total ?? 0)}`} /> : null}
                </div>

                {(profileFeatures.restaurant || profileFeatures.service_orders) ? (
                    <div className="mb-6 grid gap-6 xl:grid-cols-2">
                        {profileFeatures.restaurant ? (
                            <Panel title="Restaurante: comandas y mesas">
                                <DataTable>
                                    <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Comanda</th>
                                            <th className="px-4 py-3 font-medium">Mesa/canal</th>
                                            <th className="px-4 py-3 font-medium">Mesero</th>
                                            <th className="px-4 py-3 font-medium">Estado</th>
                                            <th className="px-4 py-3 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {restaurantSummary.map((order) => (
                                            <tr key={order.id}>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium">{order.order_number}</p>
                                                    <p className="text-xs text-slate-500">{formatDate(order.sent_at)}</p>
                                                </td>
                                                <td className="px-4 py-3">{order.table?.name ?? order.channel ?? '-'}</td>
                                                <td className="px-4 py-3">{order.waiter?.name ?? '-'}</td>
                                                <td className="px-4 py-3">{statusLabel(order.status)}</td>
                                                <td className="px-4 py-3 text-right">Bs {decimalFormat.money(order.subtotal ?? 0)}</td>
                                            </tr>
                                        ))}
                                        {restaurantSummary.length === 0 ? <EmptyTableRow colSpan={5} /> : null}
                                    </tbody>
                                </DataTable>
                            </Panel>
                        ) : null}

                        {profileFeatures.service_orders ? (
                            <Panel title="Servicios: ordenes por tecnico">
                                <DataTable>
                                    <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Orden</th>
                                            <th className="px-4 py-3 font-medium">Servicio</th>
                                            <th className="px-4 py-3 font-medium">Tecnico</th>
                                            <th className="px-4 py-3 font-medium">Estado</th>
                                            <th className="px-4 py-3 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {serviceOrderSummary.map((order) => (
                                            <tr key={order.id}>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium">{order.order_number}</p>
                                                    <p className="text-xs text-slate-500">{formatDate(order.scheduled_at)}</p>
                                                </td>
                                                <td className="px-4 py-3">{order.title}</td>
                                                <td className="px-4 py-3">{order.worker?.name ?? '-'}</td>
                                                <td className="px-4 py-3">{statusLabel(order.status)}</td>
                                                <td className="px-4 py-3 text-right">Bs {decimalFormat.money(order.total_amount ?? 0)}</td>
                                            </tr>
                                        ))}
                                        {serviceOrderSummary.length === 0 ? <EmptyTableRow colSpan={5} /> : null}
                                    </tbody>
                                </DataTable>
                            </Panel>
                        ) : null}
                    </div>
                ) : null}

                {(profileFeatures.reservations || profileFeatures.rentals || profileFeatures.production) ? (
                    <div className="mb-6 grid gap-6 xl:grid-cols-2">
                        {(profileFeatures.reservations || profileFeatures.rentals) ? (
                            <Panel title="Reservas y alquileres">
                                <DataTable>
                                    <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Numero</th>
                                            <th className="px-4 py-3 font-medium">Cliente/recurso</th>
                                            <th className="px-4 py-3 font-medium">Periodo</th>
                                            <th className="px-4 py-3 font-medium">Estado</th>
                                            <th className="px-4 py-3 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {reservationSummary.map((reservation) => (
                                            <tr key={reservation.id}>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium">{reservation.reservation_number}</p>
                                                    <p className="text-xs text-slate-500">{reservation.type === 'rental' ? 'Alquiler' : 'Reserva'}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p>{reservation.customer_name ?? reservation.title}</p>
                                                    <p className="text-xs text-slate-500">{reservation.resource?.name ?? '-'}</p>
                                                </td>
                                                <td className="px-4 py-3">{formatDate(reservation.start_at)}</td>
                                                <td className="px-4 py-3">{statusLabel(reservation.status)}</td>
                                                <td className="px-4 py-3 text-right">Bs {decimalFormat.money(reservation.total_amount ?? 0)}</td>
                                            </tr>
                                        ))}
                                        {reservationSummary.length === 0 ? <EmptyTableRow colSpan={5} /> : null}
                                    </tbody>
                                </DataTable>
                            </Panel>
                        ) : null}

                        {profileFeatures.production ? (
                            <Panel title="Produccion: ordenes y costos">
                                <DataTable>
                                    <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Orden</th>
                                            <th className="px-4 py-3 font-medium">Producto</th>
                                            <th className="px-4 py-3 text-right font-medium">Producido</th>
                                            <th className="px-4 py-3 text-right font-medium">Merma</th>
                                            <th className="px-4 py-3 text-right font-medium">Costo</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {productionSummary.map((order) => (
                                            <tr key={order.id}>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium">{order.order_number}</p>
                                                    <p className="text-xs text-slate-500">{formatDate(order.produced_at)}</p>
                                                </td>
                                                <td className="px-4 py-3">{order.output_product?.name ?? '-'}</td>
                                                <td className="px-4 py-3 text-right">{decimalFormat.quantity(order.actual_output_quantity ?? 0)}</td>
                                                <td className="px-4 py-3 text-right">{decimalFormat.measure(order.waste_meters ?? 0)}</td>
                                                <td className="px-4 py-3 text-right">Bs {decimalFormat.money(order.total_cost ?? 0)}</td>
                                            </tr>
                                        ))}
                                        {productionSummary.length === 0 ? <EmptyTableRow colSpan={5} /> : null}
                                    </tbody>
                                </DataTable>
                            </Panel>
                        ) : null}
                    </div>
                ) : null}

                {profileFeatures.sales ? (
                    <div className="mb-6 grid gap-6 xl:grid-cols-2">
                        <Panel title="Productos con mayor utilidad">
                            <DataTable>
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Producto</th>
                                        <th className="px-4 py-3 text-right font-medium">Venta</th>
                                        <th className="px-4 py-3 text-right font-medium">Costo</th>
                                        <th className="px-4 py-3 text-right font-medium">Utilidad</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {profitByProduct.map((row, index) => (
                                        <tr key={`${row.sku}-${index}`}>
                                            <td className="px-4 py-3">
                                                <p className="font-medium">{row.product}</p>
                                                <p className="text-xs text-slate-500">{row.sku}</p>
                                            </td>
                                            <td className="px-4 py-3 text-right">Bs {decimalFormat.money(row.sales_total ?? 0)}</td>
                                            <td className="px-4 py-3 text-right">Bs {decimalFormat.money(row.cost_total ?? 0)}</td>
                                            <td className="px-4 py-3 text-right font-semibold">Bs {decimalFormat.money(row.profit ?? 0)}</td>
                                        </tr>
                                    ))}
                                    {profitByProduct.length === 0 ? <EmptyTableRow colSpan={4} /> : null}
                                </tbody>
                            </DataTable>
                        </Panel>

                        <Panel title="Utilidad por vendedor">
                            <DataTable>
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Vendedor</th>
                                        <th className="px-4 py-3 text-right font-medium">Ventas</th>
                                        <th className="px-4 py-3 text-right font-medium">Total</th>
                                        <th className="px-4 py-3 text-right font-medium">Utilidad</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {profitBySeller.map((row) => (
                                        <tr key={row.seller}>
                                            <td className="px-4 py-3 font-medium">{row.seller}</td>
                                            <td className="px-4 py-3 text-right">{row.sales_count}</td>
                                            <td className="px-4 py-3 text-right">Bs {decimalFormat.money(row.sales_total ?? 0)}</td>
                                            <td className="px-4 py-3 text-right font-semibold">Bs {decimalFormat.money(row.profit ?? 0)}</td>
                                        </tr>
                                    ))}
                                    {profitBySeller.length === 0 ? <EmptyTableRow colSpan={4} /> : null}
                                </tbody>
                            </DataTable>
                        </Panel>
                    </div>
                ) : null}

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
                                        Disponible: {formatQuantityForUnit(stock.available_meters ?? 0, productUnitLabel(stock.product), decimalFormat)} {productUnitLabel(stock.product)} · Minimo: {formatQuantityForUnit(stock.product?.minimum_stock_meters ?? 0, productUnitLabel(stock.product), decimalFormat)} {productUnitLabel(stock.product)}
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
                                <th className="px-4 py-3 text-right font-medium">Disponible</th>
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
                                    <td className="px-4 py-3 text-right">{formatQuantityForUnit(coil.available_meters ?? 0, productUnitLabel(coil.product), decimalFormat)} {productUnitLabel(coil.product)}</td>
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

function statusLabel(status) {
    const labels = {
        pending: 'Pendiente',
        in_progress: 'En proceso',
        finished: 'Terminado',
        delivered: 'Entregado',
        cancelled: 'Cancelado',
        sent: 'Enviado',
        preparing: 'En preparacion',
        ready: 'Listo',
        closed: 'Cerrado',
        confirmed: 'Confirmado',
        completed: 'Finalizado',
        no_show: 'No asistio',
        rescheduled: 'Reprogramado',
    };

    return labels[status] ?? status ?? '-';
}

function EmptyTableRow({ colSpan }) {
    return (
        <tr>
            <td className="px-4 py-6 text-center text-slate-500" colSpan={colSpan}>
                Sin datos para el rango seleccionado.
            </td>
        </tr>
    );
}

function productUnitLabel(product) {
    return unitLabel(product?.unit?.symbol ?? product?.base_unit);
}

function unitLabel(unit) {
    const normalized = String(unit ?? '').trim().toLowerCase();

    if (['m', 'mt', 'mts', 'metro', 'metros'].includes(normalized)) return 'm';
    if (['unit', 'u', 'unid', 'unid.', 'unidad', 'unidades', 'pza', 'pzas', 'pieza', 'piezas'].includes(normalized)) return 'unid.';
    if (['kg', 'kilo', 'kilos'].includes(normalized)) return 'kg';
    if (['lb', 'lbs', 'libra', 'libras'].includes(normalized)) return 'lb';
    if (['ton', 'tn', 'tonelada', 'toneladas'].includes(normalized)) return 'ton';
    if (['caja', 'cajas'].includes(normalized)) return 'cajas';
    if (['bolsa', 'bolsas'].includes(normalized)) return 'bolsas';
    if (['paquete', 'paquetes'].includes(normalized)) return 'paquetes';
    if (['rollo', 'rollos'].includes(normalized)) return 'rollos';
    if (['lt', 'litro', 'litros'].includes(normalized)) return 'lt';
    if (['galon', 'galones', 'galón'].includes(normalized)) return 'gal.';

    return normalized || 'unid.';
}

function formatQuantityForUnit(value, unit, decimalFormat) {
    const normalized = String(unit ?? '').toLowerCase();

    if (['m', 'mt', 'mts', 'metro', 'metros'].includes(normalized)) {
        return decimalFormat.measure(value);
    }

    if (['kg', 'kilo', 'kilos', 'ton', 'tn', 'tonelada', 'toneladas', 'lb', 'lbs'].includes(normalized)) {
        return decimalFormat.weight(value);
    }

    return decimalFormat.quantity(value);
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
