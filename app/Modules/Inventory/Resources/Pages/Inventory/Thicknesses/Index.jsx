import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, router, usePage } from '@inertiajs/react';

export default function Index({ thicknesses }) {
    const canManage = usePage().props.auth.permissions.includes('inventory.products.manage');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Espesores" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Espesores" description="Peso por metro configurable para convertir kg o toneladas a metros durante ingresos de mercaderia." />
                    {canManage ? <ActionLink href={route('inventory.thicknesses.create')}>Nuevo espesor</ActionLink> : null}
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nombre</th>
                                <th className="px-4 py-3 font-medium">Milimetros</th>
                                <th className="px-4 py-3 font-medium">Peso kg/m</th>
                                <th className="px-4 py-3 font-medium">Metros por kg</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {thicknesses.data.map((thickness) => (
                                <tr key={thickness.id}>
                                    <td className="px-4 py-3">{thickness.name}</td>
                                    <td className="px-4 py-3">{thickness.millimeters}</td>
                                    <td className="px-4 py-3">{thickness.kg_per_meter}</td>
                                    <td className="px-4 py-3">{thickness.kg_to_meter_factor}</td>
                                    <td className="px-4 py-3">{thickness.is_active ? 'Activo' : 'Inactivo'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <IconButton href={route('inventory.thicknesses.edit', thickness.id)} icon="edit" label="Editar" />
                                                <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar espesor', text: 'El espesor dejara de estar disponible para productos nuevos.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('inventory.thicknesses.destroy', thickness.id), { preserveScroll: true });
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
                    <Pagination links={thicknesses.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
