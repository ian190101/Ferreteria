import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const meterFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ deliveries, branches, sales, saleItems, statuses, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('sales.deliveries.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        status: filters.status ?? '',
        sale_id: filters.sale_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const deliveryForm = useForm({
        sale_id: sales[0]?.id ?? '',
        delivery_number: `DESP-${new Date().getFullYear()}-${String(Date.now()).slice(-6)}`,
        delivered_at: currentDateTimeLocal(),
        recipient_name: '',
        recipient_document: '',
        recipient_phone: '',
        driver_name: '',
        vehicle_plate: '',
        notes: '',
        items: [
            {
                sale_item_id: '',
                meters: '',
            },
        ],
    });

    const availableItems = saleItems.filter((item) => String(item.sale_id) === String(deliveryForm.data.sale_id));
    const selectedItem = availableItems.find((item) => String(item.id) === String(deliveryForm.data.items[0]?.sale_item_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('sales.deliveries.index'), { preserveScroll: true, preserveState: true });
    };

    const submitDelivery = (event) => {
        event.preventDefault();
        deliveryForm.post(route('sales.deliveries.store'), {
            preserveScroll: true,
            onSuccess: () => deliveryForm.reset('recipient_name', 'recipient_document', 'recipient_phone', 'driver_name', 'vehicle_plate', 'notes', 'items'),
        });
    };

    const updateFirstItem = (field, value) => {
        deliveryForm.setData('items', [{ ...deliveryForm.data.items[0], [field]: value }]);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title="Despachos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Despachos" description="Registro de entregas fisicas parciales o completas vinculadas a notas de venta." />

                {canManage ? (
                    <form onSubmit={submitDelivery} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Nota de venta" name="sale_id" value={deliveryForm.data.sale_id} onChange={(event) => deliveryForm.setData('sale_id', event.target.value)} error={deliveryForm.errors.sale_id} required>
                            <option value="">Seleccionar</option>
                            {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number} - {sale.customer_name ?? 'Cliente'}</option>)}
                        </SelectField>
                        <SelectField label="Item pendiente" name="sale_item_id" value={deliveryForm.data.items[0]?.sale_item_id ?? ''} onChange={(event) => updateFirstItem('sale_item_id', event.target.value)} error={deliveryForm.errors['items.0.sale_item_id']} required>
                            <option value="">Seleccionar</option>
                            {availableItems.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.product?.name ?? item.description} - {meterFormatter.format(Number(item.pending_meters))} m pend.
                                </option>
                            ))}
                        </SelectField>
                        <FormField label="Numero" name="delivery_number" value={deliveryForm.data.delivery_number} onChange={(event) => deliveryForm.setData('delivery_number', event.target.value)} error={deliveryForm.errors.delivery_number} required />
                        <FormField label="Fecha" name="delivered_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={deliveryForm.errors.delivered_at} />
                        <FormField label="Metros" name="meters" type="number" step="0.001" min="0.001" max={selectedItem?.pending_meters ?? undefined} value={deliveryForm.data.items[0]?.meters ?? ''} onChange={(event) => updateFirstItem('meters', event.target.value)} error={deliveryForm.errors['items.0.meters'] ?? deliveryForm.errors.items} required />
                        <FormField label="Recibe" name="recipient_name" value={deliveryForm.data.recipient_name} onChange={(event) => deliveryForm.setData('recipient_name', event.target.value)} error={deliveryForm.errors.recipient_name} />
                        <FormField label="Documento recibe" name="recipient_document" value={deliveryForm.data.recipient_document} onChange={(event) => deliveryForm.setData('recipient_document', event.target.value)} error={deliveryForm.errors.recipient_document} />
                        <FormField label="Telefono recibe" name="recipient_phone" value={deliveryForm.data.recipient_phone} onChange={(event) => deliveryForm.setData('recipient_phone', event.target.value)} error={deliveryForm.errors.recipient_phone} />
                        <FormField label="Chofer" name="driver_name" value={deliveryForm.data.driver_name} onChange={(event) => deliveryForm.setData('driver_name', event.target.value)} error={deliveryForm.errors.driver_name} />
                        <FormField label="Placa" name="vehicle_plate" value={deliveryForm.data.vehicle_plate} onChange={(event) => deliveryForm.setData('vehicle_plate', event.target.value.toUpperCase())} error={deliveryForm.errors.vehicle_plate} />
                        <div className="sm:col-span-2">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">Notas</label>
                            <textarea id="notes" name="notes" rows="2" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" value={deliveryForm.data.notes} onChange={(event) => deliveryForm.setData('notes', event.target.value)} />
                            {deliveryForm.errors.notes ? <p className="mt-2 text-sm text-red-600">{deliveryForm.errors.notes}</p> : null}
                        </div>
                        <div className="flex items-end">
                            <button disabled={deliveryForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Registrar despacho
                            </button>
                        </div>
                        {selectedItem ? (
                            <div className="rounded-md bg-slate-100 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300 sm:col-span-2 lg:col-span-4">
                                Pendiente: {meterFormatter.format(Number(selectedItem.pending_meters))} m. Entregado: {meterFormatter.format(Number(selectedItem.delivered_meters))} m. Devuelto: {meterFormatter.format(Number(selectedItem.returned_meters))} m. Origen: {selectedItem.coil?.barcode ?? 'Stock global'}.
                            </div>
                        ) : null}
                    </form>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-7">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                    </SelectField>
                    <SelectField label="Venta" name="sale_id" value={filterForm.data.sale_id} onChange={(event) => filterForm.setData('sale_id', event.target.value)}>
                        <option value="">Todas</option>
                        {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <FormField label="Buscar" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('sales.deliveries.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Despacho</th>
                                <th className="px-4 py-3 font-medium">Venta</th>
                                <th className="px-4 py-3 font-medium">Entrega</th>
                                <th className="px-4 py-3 font-medium">Items</th>
                                <th className="px-4 py-3 text-right font-medium">Metros</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {deliveries.data.map((delivery) => (
                                <tr key={delivery.id}>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <p className="font-medium">{delivery.delivery_number}</p>
                                        <p className="text-xs text-slate-500">{formatDate(delivery.delivered_at)}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{delivery.sale?.receipt_number ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{delivery.sale?.customer_name ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{delivery.recipient_name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{delivery.vehicle_plate ?? delivery.driver_name ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {delivery.items.map((item) => (
                                            <p key={item.id} className="text-xs">
                                                {item.product?.name ?? '-'} - {meterFormatter.format(Number(item.meters))} m {item.coil ? `(${item.coil.barcode})` : '(global)'}
                                            </p>
                                        ))}
                                    </td>
                                    <td className="px-4 py-3 text-right">{meterFormatter.format(Number(delivery.total_meters ?? 0))} m</td>
                                    <td className="px-4 py-3">{statusLabel(delivery.status)}</td>
                                    <td className="px-4 py-3">{delivery.user?.name ?? '-'}</td>
                                </tr>
                            ))}
                            {deliveries.data.length === 0 ? (
                                <tr>
                                    <td className="px-4 py-6 text-center text-slate-500" colSpan="7">
                                        No hay despachos registrados.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={deliveries.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function statusLabel(status) {
    return {
        partial: 'Parcial',
        completed: 'Completo',
    }[status] ?? status;
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
