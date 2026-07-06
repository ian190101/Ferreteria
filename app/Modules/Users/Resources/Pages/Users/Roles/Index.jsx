import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, router, usePage } from '@inertiajs/react';
import { permissionLabel, roleLabel } from '../../../Utils/permissionLabels';

export default function Index({ roles }) {
    const canManage = usePage().props.auth.permissions.includes('users.manage');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Usuarios</h2>}>
            <Head title="Roles" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Roles y permisos" description="Define permisos por modulo y asigna roles a los usuarios." />
                    {canManage ? <ActionLink href={route('users.roles.create')}>Nuevo rol</ActionLink> : null}
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Rol</th>
                                <th className="px-4 py-3 font-medium">Usuarios</th>
                                <th className="px-4 py-3 font-medium">Permisos</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {roles.data.map((role) => (
                                <tr key={role.id}>
                                    <td className="px-4 py-3 font-semibold">{roleLabel(role.name)}</td>
                                    <td className="px-4 py-3">{role.users_count}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-2">
                                            {role.permissions.map((permission) => (
                                                <span key={permission.id} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                    {permissionLabel(permission.name)}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                            <IconButton href={route('users.roles.edit', role.id)} icon="edit" label="Editar" />
                                            {role.name !== 'superadmin' ? (
                                                <IconButton
                                                    icon="trash"
                                                    label="Eliminar"
                                                    tone="danger"
                                                    onClick={async () => {
                                                        if (await confirmAction({ title: 'Eliminar rol', text: 'El rol se eliminara si no esta protegido por el sistema.', confirmButtonText: 'Eliminar' })) {
                                                            router.delete(route('users.roles.destroy', role.id), { preserveScroll: true });
                                                        }
                                                    }}
                                                />
                                            ) : null}
                                            </div>
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={roles.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
