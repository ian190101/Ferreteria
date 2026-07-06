import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ adjustments, branches, products, coils, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('inventory.adjustments.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        type: filters.type ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const adjustmentForm = useForm({
        branch_id: branches[0]?.id ?? '',
        product_id: products[0]?.id ?? '',
        product_coil_id: '',
        adjustment_number: '',
        type: 'increase',
        meters: '',
        reason: '',
        adjusted_at: currentDateTimeLocal(),
        notes: '',
    });

    const selectedProduct = products.find((product) => String(product.id) === String(adjustmentForm.data.product_id));
    const availableCoils = coils.filter((coil) => String(coil.branch_id) === String(adjustmentForm.data.branch_id) && String(coil.product_id) === String(adjustmentForm.data.product_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('inventory.adjustments.index'), { preserveScroll: true, preserveState: true });
    };

    const submitAdjustment = (event) => {
        event.preventDefault();
        adjustmentForm.post(route('inventory.adjustments.store'), {
            preserveScroll: true,
            onSuccess: () => adjustmentForm.reset('adjustment_number', 'meters', 'reason', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Ajustes de inventario" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Ajustes de inventario" description="Correcciones autorizadas de stock global o por bobina con movimiento trazable." />

                {canManage ? (
                    <form onSubmit={submitAdjustment} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Sucursal" name="branch_id" value={adjustmentForm.data.branch_id} onChange={(event) => adjustmentForm.setData('branch_id', event.target.value)} error={adjustmentForm.errors.branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <FormField label="Numero" name="adjustment_number" value={adjustmentForm.data.adjustment_number} onChange={(event) => adjustmentForm.setData('adjustment_number', event.target.value)} error={adjustmentForm.errors.adjustment_number} required />
                        <SelectField label="Tipo" name="type" value={adjustmentForm.data.type} onChange={(event) => adjustmentForm.setData('type', event.target.value)} error={adjustmentForm.errors.type}>
                            <option value="increase">Aumento</option>
                            <option value="decrease">Disminucion</option>
                        </SelectField>
                        <FormField label="Fecha" name="adjusted_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={adjustmentForm.errors.adjusted_at} />

                        <SelectField label="Producto" name="product_id" value={adjustmentForm.data.product_id} onChange={(event) => adjustmentForm.setData('product_id', event.target.value)} error={adjustmentForm.errors.product_id} required>
                            {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.inventory_tracking_mode === 'coil' ? 'Bobina' : 'Global'})</option>)}
                        </SelectField>
                        <SelectField label="Bobina" name="product_coil_id" value={adjustmentForm.data.product_coil_id} onChange={(event) => adjustmentForm.setData('product_coil_id', event.target.value)} error={adjustmentForm.errors.product_coil_id} disabled={selectedProduct?.inventory_tracking_mode !== 'coil'}>
                            <option value="">Sin bobina</option>
                            {availableCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} · {coil.available_meters} m</option>)}
                        </SelectField>
                        <FormField label="Metros" name="meters" type="number" step="0.001" min="0.001" value={adjustmentForm.data.meters} onChange={(event) => adjustmentForm.setData('meters', event.target.value)} error={adjustmentForm.errors.meters} required />
                        <FormField label="Motivo" name="reason" value={adjustmentForm.data.reason} onChange={(event) => adjustmentForm.setData('reason', event.target.value)} error={adjustmentForm.errors.reason} required />
                        <div className="sm:col-span-2 lg:col-span-4">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                Notas
                                <textarea id="notes" rows="3" value={adjustmentForm.data.notes} onChange={(event) => adjustmentForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            </label>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <button disabled={adjustmentForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Guardar ajuste
                            </button>
                        </div>
                    </form>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Tipo" name="type" value={filterForm.data.type} onChange={(event) => filterForm.setData('type', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="increase">Aumento</option>
                        <option value="decrease">Disminucion</option>
                    </SelectField>
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('inventory.adjustments.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Numero</th>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Tipo</th>
                                <th className="px-4 py-3 text-right font-medium">Delta</th>
                                <th className="px-4 py-3 text-right font-medium">Despues</th>
                                <th className="px-4 py-3 font-medium">Motivo</th>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {adjustments.data.map((adjustment) => (
                                <tr key={adjustment.id}>
                                    <td className="px-4 py-3 font-medium">{adjustment.adjustment_number}</td>
                                    <td className="px-4 py-3">
                                        <p>{adjustment.product?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{adjustment.coil?.barcode ?? 'Global'}</p>
                                    </td>
                                    <td className="px-4 py-3">{adjustment.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{adjustment.type === 'increase' ? 'Aumento' : 'Disminucion'}</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(adjustment.meters_delta ?? 0))} m</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(adjustment.meters_after ?? 0))} m</td>
                                    <td className="px-4 py-3">{adjustment.reason}</td>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(adjustment.adjusted_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={adjustments.links} />
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
