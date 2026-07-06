import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, router, usePage } from '@inertiajs/react';
import { roleLabel } from '../../Utils/permissionLabels';

export default function Index({ users }) {
    const canManage = usePage().props.auth.permissions.includes('users.manage');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Usuarios</h2>}>
            <Head title="Usuarios" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Usuarios" description="Gestion de usuarios, sucursal principal, sucursales permitidas y roles de acceso." />
                    <div className="flex flex-wrap gap-2">
                        {canManage ? <ActionLink href={route('users.create')}>Nuevo usuario</ActionLink> : null}
                        <ActionLink href={route('users.roles.index')}>Roles</ActionLink>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nombre</th>
                                <th className="px-4 py-3 font-medium">Correo</th>
                                <th className="px-4 py-3 font-medium">Sucursales</th>
                                <th className="px-4 py-3 font-medium">Roles</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-4 py-3">{user.name}</td>
                                    <td className="px-4 py-3">{user.email}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-slate-900 dark:text-slate-100">
                                            {user.branch?.name ?? 'Sin sucursal principal'}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                            {(user.accessible_branches ?? []).length > 0
                                                ? user.accessible_branches.map((branch) => branch.name).join(', ')
                                                : 'Sin sucursales adicionales'}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">{user.roles.map((role) => roleLabel(role.name)).join(', ')}</td>
                                    <td className="px-4 py-3">{user.is_active ? 'Activo' : 'Inactivo'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <IconButton href={route('users.edit', user.id)} icon="edit" label="Editar" />
                                                <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar usuario', text: 'El usuario ya no podra operar en el sistema.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('users.destroy', user.id), { preserveScroll: true });
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
                    <Pagination links={users.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
