import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm } from '@inertiajs/react';

const money = (value) => new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value ?? 0));
const number = (value, decimals = 3) => new Intl.NumberFormat('es-BO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value ?? 0));
const date = (value) => value ? new Intl.DateTimeFormat('es-BO').format(new Date(value)) : '-';

function MetricCard({ label, value, tone = 'default' }) {
    const tones = {
        default: 'border-slate-200 bg-white text-slate-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
        warning: 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
    };

    return (
        <div className={`rounded-lg border p-4 shadow-sm ${tones[tone]}`}>
            <p className="text-sm opacity-75">{label}</p>
            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function EmptyRow({ columns, message }) {
    return (
        <tr>
            <td colSpan={columns} className="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                {message}
            </td>
        </tr>
    );
}

export default function Statement({ supplier, metrics, purchases, items, filters }) {
    const filterForm = useForm({
        from: filters.from ?? '',
        to: filters.to ?? '',
        status: filters.status ?? '',
        per_page: filters.per_page ?? 10,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('purchases.suppliers.statement', supplier.id), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Estado de proveedor</h2>}>
            <Head title={`Estado de proveedor - ${supplier.name}`} />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Estado de proveedor" description="Resumen de compras e ingresos de mercaderia por proveedor con detalle paginado desde servidor." />
                    <Link href={route('purchases.suppliers.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        Volver
                    </Link>
                </div>

                <div className="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Proveedor</p>
                            <h3 className="mt-1 text-xl font-semibold text-slate-950 dark:text-slate-100">{supplier.name}</h3>
                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{supplier.tax_id ?? 'Sin NIT/CI'}</p>
                        </div>
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Contacto</p>
                            <p className="mt-1 font-medium text-slate-900 dark:text-slate-100">{supplier.phone ?? '-'}</p>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{supplier.email ?? '-'}</p>
                        </div>
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Estado</p>
                            <p className="mt-1 font-medium text-slate-900 dark:text-slate-100">{supplier.is_active ? 'Activo' : 'Inactivo'}</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} error={filterForm.errors.from} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} error={filterForm.errors.to} />
                    <SelectField label="Estado compra" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)} error={filterForm.errors.status}>
                        <option value="">Todos</option>
                        <option value="received">Recibidas</option>
                        <option value="pending">Pendientes</option>
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="50" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} error={filterForm.errors.per_page} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} type="submit" className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">
                            Filtrar
                        </button>
                        <button type="button" onClick={() => router.get(route('purchases.suppliers.statement', supplier.id))} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard label="Compras" value={metrics.purchases_count} />
                    <MetricCard label="Total comprado" value={`Bs ${money(metrics.purchases_total)}`} />
                    <MetricCard label="Pagado" value={`Bs ${money(metrics.paid_total)}`} tone="success" />
                    <MetricCard label="Saldo por pagar" value={`Bs ${money(metrics.balance_due)}`} tone="warning" />
                    <MetricCard label="Recibido" value={`Bs ${money(metrics.received_total)}`} tone="success" />
                    <MetricCard label="Pendiente recepcion" value={`Bs ${money(metrics.pending_total)}`} tone="warning" />
                    <MetricCard label="Metros" value={number(metrics.meters_total)} />
                    <MetricCard label="Kilogramos" value={number(metrics.kilograms_total)} />
                </div>

                <div className="space-y-6">
                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-900 dark:text-slate-100">Compras</h3>
                        </div>
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Documento</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 text-right font-medium">Items</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    <th className="px-4 py-3 text-right font-medium">Saldo</th>
                                    <th className="px-4 py-3 font-medium">Pago</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {purchases.data.length === 0 ? <EmptyRow columns={8} message="Sin compras para el periodo seleccionado." /> : purchases.data.map((purchase) => (
                                    <tr key={purchase.id}>
                                        <td className="px-4 py-3">{date(purchase.purchase_date)}</td>
                                        <td className="px-4 py-3 font-medium">
                                            <Link href={route('purchases.show', purchase.id)} className="text-brand-primary hover:underline">{purchase.document_number}</Link>
                                        </td>
                                        <td className="px-4 py-3">{purchase.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">{purchase.items_count}</td>
                                        <td className="px-4 py-3 text-right">Bs {money(purchase.total_amount)}</td>
                                        <td className="px-4 py-3 text-right">Bs {money(purchase.balance_due)}</td>
                                        <td className="px-4 py-3">{paymentStatusLabel(purchase.payment_status)}</td>
                                        <td className="px-4 py-3">{purchase.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3"><Pagination links={purchases.links} /></div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-900 dark:text-slate-100">Detalle de mercaderia</h3>
                        </div>
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Compra</th>
                                    <th className="px-4 py-3 font-medium">Producto</th>
                                    <th className="px-4 py-3 font-medium">Lote / Bobina</th>
                                    <th className="px-4 py-3 text-right font-medium">Kg</th>
                                    <th className="px-4 py-3 text-right font-medium">Metros</th>
                                    <th className="px-4 py-3 text-right font-medium">Costo</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {items.data.length === 0 ? <EmptyRow columns={6} message="Sin detalle de mercaderia." /> : items.data.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{item.purchase?.document_number ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{date(item.purchase?.purchase_date)}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-slate-900 dark:text-slate-100">{item.product?.name ?? item.description}</p>
                                            <p className="text-xs text-slate-500">{item.product?.sku ?? '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">{item.lot_number ?? item.coil_barcode ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">{number(item.kilograms)}</td>
                                        <td className="px-4 py-3 text-right">{number(item.meters)}</td>
                                        <td className="px-4 py-3 text-right">Bs {money(item.unit_cost)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3"><Pagination links={items.links} /></div>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function paymentStatusLabel(status) {
    if (status === 'paid') {
        return 'Pagada';
    }

    if (status === 'partial_paid') {
        return 'Parcial';
    }

    return 'Pendiente';
}
