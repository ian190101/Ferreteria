import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    maximumFractionDigits: 3,
});

export default function Index({ movements, branches = [], products = [], coils = [], types = [], filters = {} }) {
    const { data, setData, get, processing } = useForm({
        branch_id: filters.branch_id ?? '',
        product_id: filters.product_id ?? '',
        product_coil_id: filters.product_coil_id ?? '',
        type: filters.type ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });

    const filteredCoils = coils.filter((coil) => {
        const branchMatches = data.branch_id === '' || String(coil.branch_id) === String(data.branch_id);
        const productMatches = data.product_id === '' || String(coil.product_id) === String(data.product_id);

        return branchMatches && productMatches;
    });

    const submit = (event) => {
        event.preventDefault();
        get(route('inventory.movements.index'), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Kardex" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Kardex" description="Historial paginado de movimientos de inventario con trazabilidad por producto, bobina, usuario y origen." />

                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-8">
                    <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Producto" name="product_id" value={data.product_id} onChange={(event) => setData('product_id', event.target.value)}>
                        <option value="">Todos</option>
                        {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.sku})</option>)}
                    </SelectField>
                    <SelectField label="Bobina" name="product_coil_id" value={data.product_coil_id} onChange={(event) => setData('product_coil_id', event.target.value)}>
                        <option value="">Todas</option>
                        {filteredCoils.map((coil) => <option key={coil.id} value={coil.id}>{coil.barcode} · {coil.lot_number}</option>)}
                    </SelectField>
                    <SelectField label="Tipo" name="type" value={data.type} onChange={(event) => setData('type', event.target.value)}>
                        <option value="">Todos</option>
                        {types.map((type) => <option key={type} value={type}>{movementType(type)}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={data.from} onChange={(event) => setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={data.to} onChange={(event) => setData('to', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={data.per_page} onChange={(event) => setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('inventory.movements.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Bobina</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Tipo</th>
                                <th className="px-4 py-3 text-right font-medium">Delta</th>
                                <th className="px-4 py-3 text-right font-medium">Antes</th>
                                <th className="px-4 py-3 text-right font-medium">Despues</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                                <th className="px-4 py-3 font-medium">Motivo</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {movements.data.map((movement) => (
                                <tr key={movement.id}>
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(movement.created_at)}</td>
                                    <td className="px-4 py-3">
                                        <p>{movement.product?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{movement.product?.sku ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{movement.coil?.barcode ?? 'Global'}</td>
                                    <td className="px-4 py-3">{movement.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{movementType(movement.type)}</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(movement.meters_delta ?? 0))} m</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(movement.meters_before ?? 0))} m</td>
                                    <td className="px-4 py-3 text-right">{numberFormatter.format(Number(movement.meters_after ?? 0))} m</td>
                                    <td className="px-4 py-3">{movement.user?.name ?? '-'}</td>
                                    <td className="px-4 py-3">{movement.reason ?? '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={movements.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function movementType(type) {
    const labels = {
        coil_entry: 'Ingreso bobina',
        inventory_adjustment: 'Ajuste inventario',
        production_input_coil: 'Produccion entrada bobina',
        production_input_global: 'Produccion entrada global',
        production_output_coil: 'Produccion salida bobina',
        production_output_global: 'Produccion salida global',
        purchase_entry_coil: 'Compra bobina',
        purchase_entry_global: 'Compra global',
    };

    return labels[type] ?? type;
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
