import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

export default function Index({ orders, filters }) {
    const canManage = usePage().props.auth.permissions.includes('purchases.manage');
    const decimalFormat = useDecimalFormatter('purchases');
    const filterForm = useForm({
        search: filters.search ?? '',
        status: filters.status ?? '',
        per_page: filters.per_page ?? 15,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('purchases.orders.index'), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ordenes de compra</h2>}>
            <Head title="Ordenes de compra" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Ordenes de compra" description="Solicitud, aprobacion y conversion controlada de mercaderia hacia compras recibidas." />
                    <div className="flex flex-wrap gap-2">
                        <ActionLink href={route('purchases.index')}>Compras</ActionLink>
                        {canManage ? <ActionLink href={route('purchases.orders.create')}>Nueva orden</ActionLink> : null}
                    </div>
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="draft">Borrador</option>
                        <option value="approved">Aprobada</option>
                        <option value="partial_received">Parcial</option>
                        <option value="converted">Convertida</option>
                        <option value="cancelled">Cancelada</option>
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('purchases.orders.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Orden</th>
                                <th className="px-4 py-3 font-medium">Proveedor</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 text-right font-medium">Items</th>
                                <th className="px-4 py-3 text-right font-medium">Recepcion</th>
                                <th className="px-4 py-3 text-right font-medium">Total</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 text-right font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {orders.data.length === 0 ? (
                                <tr>
                                    <td colSpan="9" className="px-4 py-6 text-center text-sm text-slate-500">Sin ordenes de compra registradas.</td>
                                </tr>
                            ) : orders.data.map((order) => (
                                <tr key={order.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-900 dark:text-slate-100">{order.order_number}</p>
                                        <p className="text-xs text-slate-500">{order.converted_purchase?.document_number ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{order.supplier?.name ?? 'Sin proveedor'}</td>
                                    <td className="px-4 py-3">{order.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{order.ordered_at}</td>
                                    <td className="px-4 py-3 text-right">{order.items_count}</td>
                                    <td className="px-4 py-3 text-right">{formatProgress(order, decimalFormat)}</td>
                                    <td className="px-4 py-3 text-right">Bs {decimalFormat.money(order.total_amount ?? 0)}</td>
                                    <td className="px-4 py-3">{statusLabel(order.status)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-3">
                                            {canManage && order.status === 'draft' ? (
                                                <IconButton icon="check" label="Aprobar" tone="success" onClick={() => router.patch(route('purchases.orders.approve', order.id), {}, { preserveScroll: true })} />
                                            ) : null}
                                            {canManage && ['approved', 'partial_received'].includes(order.status) ? (
                                                <IconButton href={route('purchases.orders.receive', order.id)} icon="receive" label="Recibir" />
                                            ) : null}
                                            {canManage && ['approved', 'partial_received'].includes(order.status) ? (
                                                <IconButton icon="convert" label={order.status === 'partial_received' ? 'Convertir saldo' : 'Convertir'} onClick={() => router.post(route('purchases.orders.convert', order.id), {}, { preserveScroll: true })} />
                                            ) : null}
                                            {canManage && ['draft', 'approved'].includes(order.status) ? (
                                                <IconButton icon="close" label="Cancelar" tone="danger" onClick={() => router.patch(route('purchases.orders.cancel', order.id), {}, { preserveScroll: true })} />
                                            ) : null}
                                            {order.converted_purchase ? <IconButton href={route('purchases.show', order.converted_purchase.id)} icon="eye" label="Compra" /> : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={orders.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function statusLabel(status) {
    const labels = {
        draft: 'Borrador',
        approved: 'Aprobada',
        partial_received: 'Parcial',
        converted: 'Convertida',
        cancelled: 'Cancelada',
    };

    return labels[status] ?? status;
}

function formatProgress(order, decimalFormat) {
    const ordered = Number(order.ordered_meters ?? 0);
    const received = Number(order.received_meters ?? 0);

    if (!ordered) {
        return '-';
    }

    return `${decimalFormat.measure(received)} / ${decimalFormat.measure(ordered)} m`;
}
