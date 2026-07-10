import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, usePage } from '@inertiajs/react';
import { useDecimalFormatter } from '@/Utils/formatters';
import { useEffect, useRef, useState } from 'react';

export default function Index({ products, branches = [], filters = {} }) {
    const permissions = usePage().props.auth.permissions;
    const decimalFormat = useDecimalFormatter('inventory');
    const canManage = permissions.includes('inventory.products.manage');
    const [query, setQuery] = useState({
        search: filters.search ?? '',
        branch_id: filters.branch_id ?? '',
        tracking: filters.tracking ?? '',
        per_page: filters.per_page ?? 15,
    });
    const didMount = useRef(false);

    useEffect(() => {
        if (!didMount.current) {
            didMount.current = true;

            return undefined;
        }

        const timeout = window.setTimeout(() => {
            router.get(route('inventory.products.index'), cleanQuery(query), {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [query.search, query.branch_id, query.tracking, query.per_page]);

    const updateFilter = (field, value) => setQuery((current) => ({ ...current, [field]: value }));
    const clearFilters = () => setQuery({ search: '', branch_id: '', tracking: '', per_page: 15 });

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}
        >
            <Head title="Productos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Productos" description="Catalogo general de ferreteria con stock por sucursal, unidades equivalentes y rastreo opcional por lote/unidad fisica." />
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <ActionLink href={route('inventory.products.catalogs.index')}>Categorias y caracteristicas</ActionLink>
                            <ActionLink href={route('inventory.products.create')}>Nuevo producto</ActionLink>
                        </div>
                    ) : null}
                </div>

                <div className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-[1.2fr_1fr_1fr_120px_auto]">
                    <FormField
                        label="Buscar"
                        name="search"
                        value={query.search}
                        onChange={(event) => updateFilter('search', event.target.value)}
                        placeholder="Producto, categoria, SKU o barcode"
                    />
                    <SelectField label="Sucursal" name="branch_id" value={query.branch_id} onChange={(event) => updateFilter('branch_id', event.target.value)}>
                        <option value="">Todas permitidas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Rastreo adicional" name="tracking" value={query.tracking} onChange={(event) => updateFilter('tracking', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="global">Sin lote/unidad fisica</option>
                        <option value="coil">Con lote/unidad fisica</option>
                    </SelectField>
                    <FormField
                        label="Por pagina"
                        name="per_page"
                        type="number"
                        min="5"
                        max="100"
                        value={query.per_page}
                        onChange={(event) => updateFilter('per_page', event.target.value)}
                    />
                    <div className="flex items-end">
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clearFilters}>
                            Limpiar
                        </button>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Categoria</th>
                                <th className="px-4 py-3 font-medium">SKU</th>
                                <th className="px-4 py-3 font-medium">Barcode</th>
                                <th className="px-4 py-3 font-medium">Unidad</th>
                                <th className="px-4 py-3 text-right font-medium">Compra</th>
                                <th className="px-4 py-3 text-right font-medium">Venta</th>
                                <th className="px-4 py-3 text-right font-medium">Ganancia</th>
                                <th className="px-4 py-3 font-medium">Rastreo adicional</th>
                                <th className="px-4 py-3 font-medium">Espesor</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {products.data.length === 0 ? (
                                <tr>
                                    <td colSpan={canManage ? 11 : 10} className="px-4 py-6 text-center text-slate-500">
                                        No se encontraron productos con los filtros aplicados.
                                    </td>
                                </tr>
                            ) : products.data.map((product) => (
                                <tr key={product.id}>
                                    <td className="px-4 py-3">{product.name}</td>
                                    <td className="px-4 py-3">{product.product_category?.name ?? product.category ?? 'Ferreteria general'}</td>
                                    <td className="px-4 py-3">{product.sku}</td>
                                    <td className="px-4 py-3">{product.barcode}</td>
                                    <td className="px-4 py-3">{product.unit ? `${product.unit.name} (${product.unit.symbol})` : unitLabel(product.base_unit)}</td>
                                    <td className="px-4 py-3 text-right">Bs {decimalFormat.cost(product.purchase_price ?? 0)}</td>
                                    <td className="px-4 py-3 text-right">Bs {decimalFormat.money(product.sale_price ?? 0)}</td>
                                    <td className="px-4 py-3 text-right font-semibold text-emerald-600">Bs {decimalFormat.money(Math.max(Number(product.sale_price ?? 0) - Number(product.purchase_price ?? 0), 0))}</td>
                                    <td className="px-4 py-3">{product.inventory_tracking_mode === 'coil' ? 'Con lote/unidad fisica' : 'Sin lote/unidad fisica'}</td>
                                    <td className="px-4 py-3">{product.thickness?.name ?? 'Sin espesor'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <IconButton href={route('inventory.products.edit', product.id)} icon="edit" label="Editar" />
                                                <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar producto', text: 'El producto ya no estara disponible para nuevas operaciones.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('inventory.products.destroy', product.id), { preserveScroll: true });
                                                    }
                                                }}
                                            />
                                            </div>
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={products.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function cleanQuery(query) {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    );
}

function unitLabel(unit) {
    return {
        m: 'Metro',
        unidad: 'Unidad',
        caja: 'Caja',
        paquete: 'Paquete',
        kg: 'Kg',
        ton: 'Tonelada',
        lt: 'Litro',
        galon: 'Galon',
        rollo: 'Rollo',
    }[unit] ?? (unit || '-');
}
