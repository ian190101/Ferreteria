import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, router, usePage } from '@inertiajs/react';

export default function Index({ products }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('inventory.products.manage');

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}
        >
            <Head title="Productos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Productos" description="Catalogo general de ferreteria con barcode independiente, categoria, unidad y modo de rastreo." />
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <ActionLink href={route('inventory.products.catalogs.index')}>Categorias y caracteristicas</ActionLink>
                            <ActionLink href={route('inventory.products.create')}>Nuevo producto</ActionLink>
                        </div>
                    ) : null}
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
                                <th className="px-4 py-3 font-medium">Rastreo</th>
                                <th className="px-4 py-3 font-medium">Espesor</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {products.data.map((product) => (
                                <tr key={product.id}>
                                    <td className="px-4 py-3">{product.name}</td>
                                    <td className="px-4 py-3">{product.product_category?.name ?? product.category ?? 'Ferreteria general'}</td>
                                    <td className="px-4 py-3">{product.sku}</td>
                                    <td className="px-4 py-3">{product.barcode}</td>
                                    <td className="px-4 py-3">{product.unit ? `${product.unit.name} (${product.unit.symbol})` : unitLabel(product.base_unit)}</td>
                                    <td className="px-4 py-3">{product.inventory_tracking_mode === 'coil' ? 'Por bobina' : 'Global'}</td>
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
