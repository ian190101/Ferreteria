import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ reservations, branches, products, coils, quotations, filters, statuses }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('inventory.reservations.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        product_id: filters.product_id ?? '',
        status: filters.status ?? '',
        per_page: filters.per_page ?? 15,
    });
    const reservationForm = useForm({
        branch_id: branches[0]?.id ?? '',
        product_id: products[0]?.id ?? '',
        product_coil_id: '',
        sale_id: '',
        meters: '',
        expires_at: '',
        reason: '',
        notes: '',
    });

    const selectedProduct = products.find((product) => String(product.id) === String(reservationForm.data.product_id));
    const availableCoils = coils.filter((coil) => String(coil.branch_id) === String(reservationForm.data.branch_id) && String(coil.product_id) === String(reservationForm.data.product_id));
    const availableQuotations = quotations.filter((quotation) => String(quotation.branch_id) === String(reservationForm.data.branch_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('inventory.reservations.index'), { preserveScroll: true, preserveState: true });
    };

    const submitReservation = (event) => {
        event.preventDefault();
        reservationForm.post(route('inventory.reservations.store'), {
            preserveScroll: true,
            onSuccess: () => reservationForm.reset('product_coil_id', 'sale_id', 'meters', 'expires_at', 'reason', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Reservas de inventario" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Reservas de inventario" description="Bloquea cantidad disponible para cotizaciones o pedidos antes de emitir la nota de venta." />

                {canManage ? (
                    <form onSubmit={submitReservation} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Sucursal" name="branch_id" value={reservationForm.data.branch_id} onChange={(event) => reservationForm.setData('branch_id', event.target.value)} error={reservationForm.errors.branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Producto" name="product_id" value={reservationForm.data.product_id} onChange={(event) => reservationForm.setData('product_id', event.target.value)} error={reservationForm.errors.product_id} required>
                            {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.inventory_tracking_mode === 'coil' ? 'Individual por lote/unidad' : 'Global por sucursal'})</option>)}
                        </SelectField>
                        <SelectField label="Lote/unidad fisica" name="product_coil_id" value={reservationForm.data.product_coil_id} onChange={(event) => reservationForm.setData('product_coil_id', event.target.value)} error={reservationForm.errors.product_coil_id} disabled={selectedProduct?.inventory_tracking_mode !== 'coil'}>
                            <option value="">Sin lote/unidad</option>
                            {availableCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} · {coil.available_meters} m</option>)}
                        </SelectField>
                        <SelectField label="Cotizacion" name="sale_id" value={reservationForm.data.sale_id} onChange={(event) => reservationForm.setData('sale_id', event.target.value)} error={reservationForm.errors.sale_id}>
                            <option value="">Sin cotizacion</option>
                            {availableQuotations.map((quotation) => <option key={quotation.id} value={quotation.id}>{quotation.receipt_number} · {quotation.customer_name ?? 'Cliente'}</option>)}
                        </SelectField>
                        <FormField label="Cantidad" name="meters" type="number" step="0.001" min="0.001" value={reservationForm.data.meters} onChange={(event) => reservationForm.setData('meters', event.target.value)} error={reservationForm.errors.meters} required />
                        <FormField label="Expira" name="expires_at" type="datetime-local" value={reservationForm.data.expires_at} onChange={(event) => reservationForm.setData('expires_at', event.target.value)} error={reservationForm.errors.expires_at} />
                        <FormField label="Motivo" name="reason" value={reservationForm.data.reason} onChange={(event) => reservationForm.setData('reason', event.target.value)} error={reservationForm.errors.reason} />
                        <div className="flex items-end">
                            <button disabled={reservationForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Reservar
                            </button>
                        </div>
                    </form>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Producto" name="product_id" value={filterForm.data.product_id} onChange={(event) => filterForm.setData('product_id', event.target.value)}>
                        <option value="">Todos</option>
                        {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('inventory.reservations.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Cotizacion</th>
                                <th className="px-4 py-3 text-right font-medium">Cantidad</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Expira</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                                {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {reservations.data.map((reservation) => (
                                <tr key={reservation.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-medium">{reservation.product?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{reservation.coil?.barcode ?? 'Global'}</p>
                                    </td>
                                    <td className="px-4 py-3">{reservation.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{reservation.sale?.receipt_number ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(reservation.meters ?? 0))} m</td>
                                    <td className="px-4 py-3">{statusLabel(reservation.status)}</td>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(reservation.expires_at)}</td>
                                    <td className="px-4 py-3">{reservation.user?.name ?? '-'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3 text-right">
                                            {reservation.status === 'active' ? (
                                                <button type="button" className="text-red-600 hover:underline" onClick={() => releaseReservation(reservation)}>
                                                    Liberar
                                                </button>
                                            ) : <span className="text-slate-400">-</span>}
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={reservations.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

async function releaseReservation(reservation) {
    if (!await confirmAction({ title: 'Liberar reserva', text: 'El stock reservado volvera a quedar disponible.', confirmButtonText: 'Liberar' })) {
        return;
    }

    router.patch(route('inventory.reservations.release', reservation.id), {}, { preserveScroll: true });
}

function statusLabel(status) {
    return {
        active: 'Activa',
        released: 'Liberada',
        consumed: 'Consumida',
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
