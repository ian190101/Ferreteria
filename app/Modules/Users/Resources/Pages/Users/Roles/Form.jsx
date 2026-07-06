import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { permissionLabel, sortedPermissionGroups } from '../../../Utils/permissionLabels';

export default function Form({ role, permissions }) {
    const isEditing = Boolean(role);
    const selectedPermissions = role?.permissions?.map((permission) => permission.name) ?? [];
    const { data, setData, post, put, processing, errors } = useForm({
        name: role?.name ?? '',
        permissions: selectedPermissions,
    });
    const permissionGroups = sortedPermissionGroups(permissions);

    const togglePermission = (permissionName, checked) => {
        setData('permissions', checked ? [...data.permissions, permissionName] : data.permissions.filter((permission) => permission !== permissionName));
    };

    const toggleGroup = (groupPermissions, checked) => {
        const names = groupPermissions.map((permission) => permission.name);
        const current = new Set(data.permissions);

        names.forEach((name) => {
            if (checked) {
                current.add(name);
            } else {
                current.delete(name);
            }
        });

        setData('permissions', [...current]);
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('users.roles.update', role.id), { preserveScroll: true });
            return;
        }

        post(route('users.roles.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Usuarios</h2>}>
            <Head title={isEditing ? 'Editar rol' : 'Nuevo rol'} />

            <section className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={isEditing ? 'Editar rol' : 'Nuevo rol'} description="Selecciona permisos por modulo con nombres claros para cada area del sistema." />

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <FormField label="Nombre del rol" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    </div>

                    <div className="space-y-4">
                        {permissionGroups.map(({ key, label, permissions: groupPermissions }) => {
                            const allChecked = groupPermissions.every((permission) => data.permissions.includes(permission.name));

                            return (
                                <section key={key} className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h3 className="text-base font-semibold text-slate-950 dark:text-white">{label}</h3>
                                            <p className="text-sm text-slate-500 dark:text-slate-400">{groupPermissions.length} permisos disponibles</p>
                                        </div>
                                        <label className="flex w-fit items-center gap-2 rounded-full border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-200">
                                            <input className="h-4 w-4 rounded border-slate-300 text-[rgb(var(--color-primary))] focus:ring-[rgb(var(--color-primary))]" type="checkbox" checked={allChecked} onChange={(event) => toggleGroup(groupPermissions, event.target.checked)} />
                                            Todo el modulo
                                        </label>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {groupPermissions.map((permission) => (
                                            <label key={permission.id} className="flex min-h-12 items-start gap-3 rounded-md border border-slate-200 px-3 py-3 text-sm transition hover:border-[rgb(var(--color-primary))] dark:border-slate-800">
                                                <input className="mt-0.5 h-4 w-4 rounded border-slate-300 text-[rgb(var(--color-primary))] focus:ring-[rgb(var(--color-primary))]" type="checkbox" checked={data.permissions.includes(permission.name)} onChange={(event) => togglePermission(permission.name, event.target.checked)} />
                                                <span className="font-medium text-slate-800 dark:text-slate-100">{permissionLabel(permission.name)}</span>
                                            </label>
                                        ))}
                                    </div>
                                </section>
                            );
                        })}
                        {errors.permissions ? <p className="text-sm text-red-600">{errors.permissions}</p> : null}
                    </div>

                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('users.roles.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
