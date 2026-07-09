import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ transfers, branches, products, coils, filters }) {
    const permissions = usePage().props.auth.permissions;
    const decimalFormat = useDecimalFormatter('inventory');
    const canManage = permissions.includes('inventory.transfers.manage');
    const filterForm = useForm({
        from_branch_id: filters.from_branch_id ?? '',
        to_branch_id: filters.to_branch_id ?? '',
        product_id: filters.product_id ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const transferForm = useForm({
        from_branch_id: branches[0]?.id ?? '',
        to_branch_id: branches[1]?.id ?? '',
        product_id: products[0]?.id ?? '',
        product_coil_id: '',
        transfer_number: '',
        meters: '',
        transferred_at: currentDateTimeLocal(),
        reason: '',
        notes: '',
    });

    const selectedProduct = products.find((product) => String(product.id) === String(transferForm.data.product_id));
    const availableCoils = coils.filter((coil) => String(coil.branch_id) === String(transferForm.data.from_branch_id) && String(coil.product_id) === String(transferForm.data.product_id));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('inventory.transfers.index'), { preserveScroll: true, preserveState: true });
    };

    const submitTransfer = (event) => {
        event.preventDefault();
        transferForm.post(route('inventory.transfers.store'), {
            preserveScroll: true,
            onSuccess: () => transferForm.reset('product_coil_id', 'transfer_number', 'meters', 'reason', 'notes'),
        });
    };

    const selectProduct = (productId) => {
        transferForm.setData((data) => ({
            ...data,
            product_id: productId,
            product_coil_id: '',
            meters: '',
        }));
    };

    const selectCoil = (coilId) => {
        const coil = coils.find((item) => String(item.id) === String(coilId));
        transferForm.setData((data) => ({
            ...data,
            product_coil_id: coilId,
            meters: coil ? coil.available_meters : data.meters,
        }));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Transferencias de inventario" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Transferencias de inventario" description="Movimiento controlado entre sucursales con salida, entrada y Kardex trazable." />

                {canManage ? (
                    <form onSubmit={submitTransfer} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Origen" name="from_branch_id" value={transferForm.data.from_branch_id} onChange={(event) => transferForm.setData('from_branch_id', event.target.value)} error={transferForm.errors.from_branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Destino" name="to_branch_id" value={transferForm.data.to_branch_id} onChange={(event) => transferForm.setData('to_branch_id', event.target.value)} error={transferForm.errors.to_branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <FormField label="Numero" name="transfer_number" value={transferForm.data.transfer_number} onChange={(event) => transferForm.setData('transfer_number', event.target.value)} error={transferForm.errors.transfer_number} required />
                        <FormField label="Fecha" name="transferred_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={transferForm.errors.transferred_at} />

                        <SelectField label="Producto" name="product_id" value={transferForm.data.product_id} onChange={(event) => selectProduct(event.target.value)} error={transferForm.errors.product_id} required>
                            {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({trackingLabel(product)})</option>)}
                        </SelectField>
                        <SelectField label="Lote/unidad fisica" name="product_coil_id" value={transferForm.data.product_coil_id} onChange={(event) => selectCoil(event.target.value)} error={transferForm.errors.product_coil_id} disabled={selectedProduct?.inventory_tracking_mode !== 'coil'}>
                            <option value="">Sin lote/unidad</option>
                            {availableCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} - {formatProductQuantity(coil.available_meters, selectedProduct, decimalFormat)}</option>)}
                        </SelectField>
                        <FormField label={`Cantidad (${productUnitSymbol(selectedProduct)})`} name="meters" type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind(selectedProduct)))} min="0.001" value={transferForm.data.meters} onChange={(event) => transferForm.setData('meters', event.target.value)} error={transferForm.errors.meters} required />
                        <FormField label="Motivo" name="reason" value={transferForm.data.reason} onChange={(event) => transferForm.setData('reason', event.target.value)} error={transferForm.errors.reason} required />
                        <div className="sm:col-span-2 lg:col-span-4">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                Notas
                                <textarea id="notes" rows="3" value={transferForm.data.notes} onChange={(event) => transferForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                            </label>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <button disabled={transferForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Registrar transferencia
                            </button>
                        </div>
                    </form>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-6">
                    <SelectField label="Origen" name="from_branch_id" value={filterForm.data.from_branch_id} onChange={(event) => filterForm.setData('from_branch_id', event.target.value)}>
                        <option value="">Todos</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Destino" name="to_branch_id" value={filterForm.data.to_branch_id} onChange={(event) => filterForm.setData('to_branch_id', event.target.value)}>
                        <option value="">Todos</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Producto" name="product_id" value={filterForm.data.product_id} onChange={(event) => filterForm.setData('product_id', event.target.value)}>
                        <option value="">Todos</option>
                        {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                    </SelectField>
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('inventory.transfers.index'))}>
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
                                <th className="px-4 py-3 font-medium">Origen</th>
                                <th className="px-4 py-3 font-medium">Destino</th>
                                <th className="px-4 py-3 text-right font-medium">Cantidad</th>
                                <th className="px-4 py-3 font-medium">Motivo</th>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {transfers.data.map((transfer) => (
                                <tr key={transfer.id}>
                                    <td className="px-4 py-3 font-medium">{transfer.transfer_number}</td>
                                    <td className="px-4 py-3">
                                        <p>{transfer.product?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{transfer.coil?.barcode ?? 'Global por sucursal'}</p>
                                    </td>
                                    <td className="px-4 py-3">{transfer.from_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{transfer.to_branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{formatProductQuantity(transfer.meters ?? 0, transfer.product, decimalFormat)}</td>
                                    <td className="px-4 py-3">{transfer.reason}</td>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(transfer.transferred_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={transfers.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function productUnitSymbol(product) {
    return product?.unit?.symbol ?? product?.base_unit ?? 'unidad';
}

function quantityKind(product) {
    const unit = String(productUnitSymbol(product)).toLowerCase();

    if (['m', 'metro', 'metros'].includes(unit)) return 'measure';
    if (['kg', 'lb'].includes(unit)) return 'weight';

    return 'quantity';
}

function formatProductQuantity(value, product, decimalFormat) {
    const unit = productUnitSymbol(product);

    return `${decimalFormat.format(value, quantityKind(product))} ${unit}`;
}

function trackingLabel(product) {
    return product?.inventory_tracking_mode === 'coil'
        ? 'Individual por lote/unidad'
        : 'Global por sucursal';
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
