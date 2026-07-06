import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ orders, branches, products, coils, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('production.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const productionForm = useForm({
        branch_id: branches[0]?.id ?? '',
        order_number: '',
        produced_at: currentDateTimeLocal(),
        input_product_id: products[0]?.id ?? '',
        input_product_coil_id: '',
        output_product_id: products[0]?.id ?? '',
        input_meters: '',
        output_meters: '',
        waste_meters: '0',
        output_coil_barcode: '',
        output_lot_number: '',
        notes: '',
    });

    const inputProduct = products.find((product) => String(product.id) === String(productionForm.data.input_product_id));
    const outputProduct = products.find((product) => String(product.id) === String(productionForm.data.output_product_id));
    const availableCoils = coils.filter((coil) => String(coil.branch_id) === String(productionForm.data.branch_id) && String(coil.product_id) === String(productionForm.data.input_product_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('production.index'), { preserveScroll: true, preserveState: true });
    };

    const submitProduction = (event) => {
        event.preventDefault();
        productionForm.post(route('production.store'), {
            preserveScroll: true,
            onSuccess: () => productionForm.reset('order_number', 'input_meters', 'output_meters', 'waste_meters', 'output_coil_barcode', 'output_lot_number', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Produccion</h2>}>
            <Head title="Produccion" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Produccion" description="Transformacion de material con consumo de inventario, salida de producto terminado y trazabilidad de movimientos." />

                {canManage ? (
                    <Panel title="Registrar orden terminada">
                        <form onSubmit={submitProduction} className="mb-6 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                            <SelectField label="Sucursal" name="branch_id" value={productionForm.data.branch_id} onChange={(event) => productionForm.setData('branch_id', event.target.value)} error={productionForm.errors.branch_id} required>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Numero de orden" name="order_number" value={productionForm.data.order_number} onChange={(event) => productionForm.setData('order_number', event.target.value)} error={productionForm.errors.order_number} required />
                            <FormField label="Fecha" name="produced_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={productionForm.errors.produced_at} />
                            <FormField label="Merma metros" name="waste_meters" type="number" step="0.001" min="0" value={productionForm.data.waste_meters} onChange={(event) => productionForm.setData('waste_meters', event.target.value)} error={productionForm.errors.waste_meters} />

                            <SelectField label="Producto entrada" name="input_product_id" value={productionForm.data.input_product_id} onChange={(event) => productionForm.setData('input_product_id', event.target.value)} error={productionForm.errors.input_product_id} required>
                                {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({tracking(product.inventory_tracking_mode)})</option>)}
                            </SelectField>
                            <SelectField label="Bobina entrada" name="input_product_coil_id" value={productionForm.data.input_product_coil_id} onChange={(event) => productionForm.setData('input_product_coil_id', event.target.value)} error={productionForm.errors.input_product_coil_id} disabled={inputProduct?.inventory_tracking_mode !== 'coil'}>
                                <option value="">Sin bobina</option>
                                {availableCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} · {coil.lot_number} · {coil.available_meters} m</option>)}
                            </SelectField>
                            <FormField label="Metros entrada" name="input_meters" type="number" step="0.001" min="0.001" value={productionForm.data.input_meters} onChange={(event) => productionForm.setData('input_meters', event.target.value)} error={productionForm.errors.input_meters} required />

                            <SelectField label="Producto salida" name="output_product_id" value={productionForm.data.output_product_id} onChange={(event) => productionForm.setData('output_product_id', event.target.value)} error={productionForm.errors.output_product_id} required>
                                {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({tracking(product.inventory_tracking_mode)})</option>)}
                            </SelectField>
                            <FormField label="Metros salida" name="output_meters" type="number" step="0.001" min="0.001" value={productionForm.data.output_meters} onChange={(event) => productionForm.setData('output_meters', event.target.value)} error={productionForm.errors.output_meters} required />
                            <FormField label="Barcode bobina salida" name="output_coil_barcode" value={productionForm.data.output_coil_barcode} onChange={(event) => productionForm.setData('output_coil_barcode', event.target.value)} error={productionForm.errors.output_coil_barcode} disabled={outputProduct?.inventory_tracking_mode !== 'coil'} />
                            <FormField label="Lote salida" name="output_lot_number" value={productionForm.data.output_lot_number} onChange={(event) => productionForm.setData('output_lot_number', event.target.value)} error={productionForm.errors.output_lot_number} disabled={outputProduct?.inventory_tracking_mode !== 'coil'} />
                            <div className="sm:col-span-2 lg:col-span-4">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                    Notas
                                    <textarea id="notes" rows="3" value={productionForm.data.notes} onChange={(event) => productionForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                            </div>
                            <div className="sm:col-span-2 lg:col-span-4">
                                <button disabled={productionForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar produccion
                                </button>
                            </div>
                        </form>
                    </Panel>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('production.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Ordenes de produccion">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Orden</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 font-medium">Entrada</th>
                                    <th className="px-4 py-3 font-medium">Salida</th>
                                    <th className="px-4 py-3 text-right font-medium">Merma</th>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {orders.data.map((order) => (
                                    <tr key={order.id}>
                                        <td className="px-4 py-3 font-medium">{order.order_number}</td>
                                        <td className="px-4 py-3">{order.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <p>{order.input_product?.name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{numberFormatter.format(Number(order.input_meters ?? 0))} m · {order.input_coil?.barcode ?? 'Global'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p>{order.output_product?.name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{numberFormatter.format(Number(order.output_meters ?? 0))} m · {order.output_coil?.barcode ?? 'Global'}</p>
                                        </td>
                                        <td className="px-4 py-3 text-right">{numberFormatter.format(Number(order.waste_meters ?? 0))} m</td>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(order.produced_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={orders.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Panel({ title, children }) {
    return (
        <section className="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function tracking(mode) {
    return mode === 'coil' ? 'Bobina' : 'Global';
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
