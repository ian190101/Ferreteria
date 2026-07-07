import Checkbox from '@/Components/Checkbox';
import PasswordMatchHint from '@/Components/PasswordMatchHint';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { roleLabel } from '../../Utils/permissionLabels';

export default function Form({ userRecord, branches, roles }) {
    const isEditing = Boolean(userRecord);
    const selectedRoles = userRecord?.roles?.map((role) => role.name) ?? [];
    const selectedBranches = userRecord?.accessible_branches?.map((branch) => branch.id) ?? [];
    const { data, setData, post, put, processing, errors } = useForm({
        branch_id: userRecord?.branch_id ?? '',
        branch_ids: selectedBranches,
        name: userRecord?.name ?? '',
        email: userRecord?.email ?? '',
        password: '',
        password_confirmation: '',
        is_active: userRecord?.is_active ?? true,
        roles: selectedRoles,
    });

    const toggleRole = (roleName, checked) => {
        setData('roles', checked ? [...data.roles, roleName] : data.roles.filter((role) => role !== roleName));
    };

    const toggleBranch = (branchId, checked) => {
        const id = Number(branchId);

        setData('branch_ids', checked ? [...data.branch_ids, id] : data.branch_ids.filter((currentId) => Number(currentId) !== id));
    };

    const updatePrimaryBranch = (branchId) => {
        const id = branchId ? Number(branchId) : '';
        const selectedIds = data.branch_ids.map(Number);

        setData({
            ...data,
            branch_id: id,
            branch_ids: id && !selectedIds.includes(id)
                ? [...data.branch_ids, id]
                : data.branch_ids,
        });
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('users.update', userRecord.id), { preserveScroll: true });
            return;
        }

        post(route('users.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Usuarios</h2>}>
            <Head title={isEditing ? 'Editar usuario' : 'Nuevo usuario'} />

            <section className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={isEditing ? 'Editar usuario' : 'Nuevo usuario'} description="Define datos de acceso, sucursal principal, sucursales permitidas y roles asignados." />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                        <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                        <FormField label="Correo electronico" name="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} required />
                        <SelectField label="Sucursal principal" name="branch_id" value={data.branch_id ?? ''} onChange={(event) => updatePrimaryBranch(event.target.value || null)} error={errors.branch_id}>
                            <option value="">Sin sucursal</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </SelectField>
                        <div>
                            <FormField label={isEditing ? 'Nueva contrasena temporal' : 'Contrasena temporal'} name="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} error={errors.password} required={!isEditing} />
                            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                El usuario debera cambiarla en su proximo inicio de sesion.
                            </p>
                        </div>
                        <div>
                            <FormField label="Confirmar contrasena" name="password_confirmation" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} error={errors.password_confirmation} required={!isEditing} />
                            <PasswordMatchHint password={data.password} confirmation={data.password_confirmation} />
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-base font-semibold text-slate-950 dark:text-white">Sucursales con acceso</h3>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Selecciona todas las sucursales donde el usuario podra operar. La sucursal principal se agrega automaticamente.
                        </p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            {branches.map((branch) => {
                                const checked = data.branch_ids.map(Number).includes(Number(branch.id));

                                return (
                                    <label key={branch.id} className="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 px-3 py-3 text-sm transition hover:border-brand-primary/40 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-white/5">
                                        <Checkbox
                                            checked={checked}
                                            onChange={(event) => toggleBranch(branch.id, event.target.checked)}
                                        />
                                        <span className="font-medium text-slate-700 dark:text-slate-200">{branch.name}</span>
                                        {Number(data.branch_id) === Number(branch.id) ? (
                                            <span className="ml-auto rounded-full bg-brand-primary/10 px-2 py-1 text-xs font-semibold text-brand-primary">
                                                Principal
                                            </span>
                                        ) : null}
                                    </label>
                                );
                            })}
                        </div>
                        {errors.branch_ids ? <p className="mt-2 text-sm text-red-600">{errors.branch_ids}</p> : null}
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">Roles</h3>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {roles.map((role) => (
                                <label key={role.id} className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                                    <input type="checkbox" checked={data.roles.includes(role.name)} onChange={(event) => toggleRole(role.name, event.target.checked)} />
                                    <span>{roleLabel(role.name)}</span>
                                </label>
                            ))}
                        </div>
                        {errors.roles ? <p className="mt-2 text-sm text-red-600">{errors.roles}</p> : null}
                    </div>

                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('users.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
