import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, router, useForm, usePage } from '@inertiajs/react';

export default function Index({ returns, branches, sales, saleItems, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('sales.returns.manage');
    const decimalFormat = useDecimalFormatter('sales');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        sale_id: filters.sale_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const returnForm = useForm({
        sale_id: sales[0]?.id ?? '',
        return_number: `DEV-${new Date().getFullYear()}-${String(Date.now()).slice(-6)}`,
        returned_at: currentDateTimeLocal(),
        reason: '',
        notes: '',
        items: [
            {
                sale_item_id: '',
                meters: '',
            },
        ],
    });

    const availableItems = saleItems.filter((item) => String(item.sale_id) === String(returnForm.data.sale_id));
    const selectedItem = availableItems.find((item) => String(item.id) === String(returnForm.data.items[0]?.sale_item_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('sales.returns.index'), { preserveScroll: true, preserveState: true });
    };

    const submitReturn = (event) => {
        event.preventDefault();
        returnForm.post(route('sales.returns.store'), {
            preserveScroll: true,
            onSuccess: () => returnForm.reset('reason', 'notes', 'items'),
        });
    };

    const updateFirstItem = (field, value) => {
        returnForm.setData('items', [{ ...returnForm.data.items[0], [field]: value }]);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title="Devoluciones de venta" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Devoluciones de venta" description="Registra devoluciones parciales de notas de venta y reingresa el metraje al stock global o a la bobina original." />

                {canManage ? (
                    <form onSubmit={submitReturn} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Nota de venta" name="sale_id" value={returnForm.data.sale_id} onChange={(event) => returnForm.setData('sale_id', event.target.value)} error={returnForm.errors.sale_id} required>
                            <option value="">Seleccionar</option>
                            {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number} - {sale.customer_name ?? 'Cliente'}</option>)}
                        </SelectField>
                        <SelectField label="Item a devolver" name="sale_item_id" value={returnForm.data.items[0]?.sale_item_id ?? ''} onChange={(event) => updateFirstItem('sale_item_id', event.target.value)} error={returnForm.errors['items.0.sale_item_id']} required>
                            <option value="">Seleccionar</option>
                            {availableItems.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.product?.name ?? item.description} - {decimalFormat.measure(item.remaining_meters)} m disp.
                                </option>
                            ))}
                        </SelectField>
                        <FormField label="Numero" name="return_number" value={returnForm.data.return_number} onChange={(event) => returnForm.setData('return_number', event.target.value)} error={returnForm.errors.return_number} required />
                        <FormField label="Fecha" name="returned_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={returnForm.errors.returned_at} />
                        <FormField label="Metros" name="meters" type="number" step={decimalStep(decimalFormat.decimalsFor('measure'))} min={decimalStep(decimalFormat.decimalsFor('measure'))} max={selectedItem?.remaining_meters ?? undefined} value={returnForm.data.items[0]?.meters ?? ''} onChange={(event) => updateFirstItem('meters', event.target.value)} error={returnForm.errors['items.0.meters'] ?? returnForm.errors.items} required />
                        <FormField label="Motivo" name="reason" value={returnForm.data.reason} onChange={(event) => returnForm.setData('reason', event.target.value)} error={returnForm.errors.reason} required />
                        <div className="sm:col-span-2">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">Notas</label>
                            <textarea id="notes" name="notes" rows="2" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" value={returnForm.data.notes} onChange={(event) => returnForm.setData('notes', event.target.value)} />
                            {returnForm.errors.notes ? <p className="mt-2 text-sm text-red-600">{returnForm.errors.notes}</p> : null}
                        </div>
                        <div className="flex items-end">
                            <button disabled={returnForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Registrar devolucion
                            </button>
                        </div>
                        {selectedItem ? (
                            <div className="rounded-md bg-slate-100 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300 sm:col-span-2 lg:col-span-4">
                                Disponible: {decimalFormat.measure(selectedItem.remaining_meters)} m. Bobina: {selectedItem.coil?.barcode ?? 'Stock global'}. Precio: Bs {decimalFormat.money(selectedItem.unit_price)}.
                            </div>
                        ) : null}
                    </form>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-6">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
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
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('sales.returns.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Devolucion</th>
                                <th className="px-4 py-3 font-medium">Venta</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Items</th>
                                <th className="px-4 py-3 text-right font-medium">Total</th>
                                <th className="px-4 py-3 font-medium">Motivo</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {returns.data.map((saleReturn) => (
                                <tr key={saleReturn.id}>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <p className="font-medium">{saleReturn.return_number}</p>
                                        <p className="text-xs text-slate-500">{formatDate(saleReturn.returned_at)}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{saleReturn.sale?.receipt_number ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{saleReturn.sale?.customer_name ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{saleReturn.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        {saleReturn.items.map((item) => (
                                            <p key={item.id} className="text-xs">
                                                {item.product?.name ?? '-'} - {decimalFormat.measure(item.meters)} m {item.coil ? `(${item.coil.barcode})` : '(global)'}
                                            </p>
                                        ))}
                                    </td>
                                    <td className="px-4 py-3 text-right">Bs {decimalFormat.money(saleReturn.total_amount ?? 0)}</td>
                                    <td className="px-4 py-3">{saleReturn.reason}</td>
                                    <td className="px-4 py-3">{saleReturn.user?.name ?? '-'}</td>
                                </tr>
                            ))}
                            {returns.data.length === 0 ? (
                                <tr>
                                    <td className="px-4 py-6 text-center text-slate-500" colSpan="7">
                                        No hay devoluciones registradas.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={returns.links} />
                </div>
            </section>
        </AuthenticatedLayout>
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
