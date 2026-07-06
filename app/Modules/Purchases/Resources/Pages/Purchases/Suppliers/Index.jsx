import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

export default function Index({ suppliers, filters }) {
    const canManage = usePage().props.auth.permissions.includes('purchases.manage');
    const filterForm = useForm({
        search: filters.search ?? '',
        is_active: filters.is_active ?? '',
        per_page: filters.per_page ?? 15,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('purchases.suppliers.index'), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title="Proveedores" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Proveedores" description="Catalogo de proveedores para compras e ingresos de mercaderia." />
                    {canManage ? <ActionLink href={route('purchases.suppliers.create')}>Nuevo proveedor</ActionLink> : null}
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <SelectField label="Estado" name="is_active" value={filterForm.data.is_active} onChange={(event) => filterForm.setData('is_active', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="1">Activos</option>
                        <option value="0">Inactivos</option>
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('purchases.suppliers.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nombre</th>
                                <th className="px-4 py-3 font-medium">NIT/CI</th>
                                <th className="px-4 py-3 font-medium">Telefono</th>
                                <th className="px-4 py-3 font-medium">Correo</th>
                                <th className="px-4 py-3 text-right font-medium">Compras</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {suppliers.data.map((supplier) => (
                                <tr key={supplier.id}>
                                    <td className="px-4 py-3">{supplier.name}</td>
                                    <td className="px-4 py-3">{supplier.tax_id ?? '-'}</td>
                                    <td className="px-4 py-3">{supplier.phone ?? '-'}</td>
                                    <td className="px-4 py-3">{supplier.email ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{supplier.purchases_count ?? 0}</td>
                                    <td className="px-4 py-3">{supplier.is_active ? 'Activo' : 'Inactivo'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                        <IconButton href={route('purchases.suppliers.statement', supplier.id)} icon="eye" label="Estado" />
                                        {canManage ? (
                                            <>
                                            <IconButton href={route('purchases.suppliers.edit', supplier.id)} icon="edit" label="Editar" />
                                            <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar proveedor', text: 'El proveedor dejara de estar disponible para nuevas compras.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('purchases.suppliers.destroy', supplier.id), { preserveScroll: true });
                                                    }
                                                }}
                                            />
                                            </>
                                        ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={suppliers.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
