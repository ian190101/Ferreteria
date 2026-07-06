import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
});

export default function Index({ globalStocks, coilSummary, branches, filters }) {
    const { data, setData, get, processing } = useForm({
        branch_id: filters.branch_id ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });

    const submit = (event) => {
        event.preventDefault();
        get(route('inventory.stock.index'), { preserveScroll: true, preserveState: true });
    };

    const clear = () => router.get(route('inventory.stock.index'), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Inventario central" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Inventario central" description="Stock disponible por producto y sucursal, separado de ventas, compras y reportes." />

                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                    <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)}>
                        <option value="">Todas permitidas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <FormField label="Buscar" name="search" value={data.search} onChange={(event) => setData('search', event.target.value)} placeholder="Producto, SKU o codigo" />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={data.per_page} onChange={(event) => setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">Filtrar</button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clear}>Limpiar</button>
                    </div>
                </form>

                <StockTable stocks={globalStocks.data} />
                <div className="mt-4"><Pagination links={globalStocks.links} /></div>

                <section className="mt-8 rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h3 className="font-semibold text-slate-950 dark:text-white">Bobinas disponibles</h3>
                    </div>
                    <div className="grid gap-3 p-5 md:grid-cols-2 xl:grid-cols-3">
                        {coilSummary.length === 0 ? (
                            <p className="text-sm text-slate-500">Sin bobinas disponibles.</p>
                        ) : coilSummary.map((coil) => (
                            <div key={coil.id} className="rounded-lg border border-slate-200 p-4 text-sm dark:border-slate-800">
                                <p className="font-semibold text-slate-950 dark:text-white">{coil.product?.name ?? 'Producto'}</p>
                                <p className="text-slate-500">{coil.branch?.name ?? '-'} / Lote {coil.lot_number ?? '-'}</p>
                                <p className="mt-2 text-lg font-bold text-emerald-600">{numberFormatter.format(Number(coil.available_meters ?? 0))} {unitLabel(coil.product)}</p>
                                <p className="text-xs text-slate-500">Codigo: {coil.barcode}</p>
                            </div>
                        ))}
                    </div>
                </section>
            </section>
        </AuthenticatedLayout>
    );
}

function StockTable({ stocks }) {
    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th className="px-4 py-3 font-medium">Producto</th>
                        <th className="px-4 py-3 font-medium">Sucursal</th>
                        <th className="px-4 py-3 text-right font-medium">Disponible</th>
                        <th className="px-4 py-3 text-right font-medium">Reservado</th>
                        <th className="px-4 py-3 text-right font-medium">Libre</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {stocks.length === 0 ? (
                        <tr><td colSpan="5" className="px-4 py-6 text-center text-slate-500">Sin stock registrado.</td></tr>
                    ) : stocks.map((stock) => {
                        const available = Number(stock.available_meters ?? 0);
                        const reserved = Number(stock.reserved_meters ?? 0);
                        const unit = unitLabel(stock.product);

                        return (
                            <tr key={stock.id}>
                                <td className="px-4 py-3">
                                    <p className="font-semibold text-slate-950 dark:text-white">{stock.product?.name ?? '-'}</p>
                                    <p className="text-xs text-slate-500">{stock.product?.sku ?? '-'} / {stock.product?.barcode ?? '-'}</p>
                                </td>
                                <td className="px-4 py-3">{stock.branch?.name ?? '-'}</td>
                                <td className="px-4 py-3 text-right">{numberFormatter.format(available)} {unit}</td>
                                <td className="px-4 py-3 text-right">{numberFormatter.format(reserved)} {unit}</td>
                                <td className="px-4 py-3 text-right font-semibold">{numberFormatter.format(Math.max(available - reserved, 0))} {unit}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function unitLabel(product) {
    if (product?.unit?.symbol) return product.unit.symbol;
    if (product?.base_unit === 'unit') return 'unid.';
    if (product?.base_unit === 'kg') return 'kg';
    if (product?.base_unit === 'lb') return 'lb';
    return 'm';
}
