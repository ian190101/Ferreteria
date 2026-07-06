import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, router, usePage } from '@inertiajs/react';

export default function Index({ branches }) {
    const canManage = usePage().props.auth.permissions.includes('branches.manage');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Sucursales</h2>}>
            <Head title="Sucursales" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Sucursales" description="Gestion central de sucursales, identidad visual, punto de venta y estado operativo." />
                    {canManage ? <ActionLink href={route('branches.create')}>Nueva sucursal</ActionLink> : null}
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nombre</th>
                                <th className="px-4 py-3 font-medium">Codigo</th>
                                <th className="px-4 py-3 font-medium">Barcode</th>
                                <th className="px-4 py-3 font-medium">Punto de venta</th>
                                <th className="px-4 py-3 font-medium">Branding</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {branches.data.map((branch) => (
                                <tr key={branch.id}>
                                    <td className="px-4 py-3">{branch.name}</td>
                                    <td className="px-4 py-3">{branch.code}</td>
                                    <td className="px-4 py-3">{branch.barcode}</td>
                                    <td className="px-4 py-3">{branch.point_of_sale_name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex gap-2">
                                            <span className="h-5 w-5 rounded border" style={{ backgroundColor: branch.setting?.primary_color }} />
                                            <span className="h-5 w-5 rounded border" style={{ backgroundColor: branch.setting?.secondary_color }} />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{branch.is_active ? 'Activa' : 'Inactiva'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <IconButton href={route('branches.edit', branch.id)} icon="edit" label="Editar" />
                                                <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar sucursal', text: 'La sucursal dejara de estar disponible para nuevas operaciones.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('branches.destroy', branch.id), { preserveScroll: true });
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
                    <Pagination links={branches.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
