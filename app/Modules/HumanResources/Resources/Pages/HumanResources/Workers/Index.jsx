import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ workers, branches = [], users = [], filters = {} }) {
    const canManage = usePage().props.auth.permissions.includes('workers.manage');
    const [editing, setEditing] = useState(null);
    const filterForm = useForm({ search: filters.search ?? '', per_page: filters.per_page ?? 15 });
    const form = useForm(emptyWorker(branches));

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => cancelEdit() };

        if (editing) {
            form.put(route('human-resources.workers.update', editing.id), options);
            return;
        }

        form.post(route('human-resources.workers.store'), options);
    };

    const startEdit = (worker) => {
        setEditing(worker);
        form.setData({
            user_id: worker.user_id ?? '',
            branch_id: worker.branch_id ?? branches[0]?.id ?? '',
            name: worker.name ?? '',
            document_number: worker.document_number ?? '',
            phone: worker.phone ?? '',
            position: worker.position ?? '',
            hired_at: worker.hired_at ?? '',
            salary_amount: worker.salary_amount ?? 0,
            salary_frequency: worker.salary_frequency ?? 'monthly',
            is_active: Boolean(worker.is_active),
            notes: worker.notes ?? '',
        });
    };

    const cancelEdit = () => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyWorker(branches));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Trabajadores</h2>}>
            <Head title="Trabajadores" />
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Trabajadores" description="Gestiona personal interno, vinculado o no a usuarios del sistema, por sucursal y cargo." />

                {canManage ? (
                    <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-4">
                        <SelectField label="Usuario vinculado" name="user_id" value={form.data.user_id} onChange={(event) => form.setData('user_id', event.target.value)} helpTooltip="Opcional. Usalo si el trabajador tambien ingresa al sistema.">
                            <option value="">Sin usuario</option>
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
                        </SelectField>
                        <SelectField label="Sucursal" name="branch_id" value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)} error={form.errors.branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <FormField label="Nombre" name="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required />
                        <FormField label="Documento" name="document_number" value={form.data.document_number} onChange={(event) => form.setData('document_number', event.target.value)} error={form.errors.document_number} />
                        <FormField label="Telefono" name="phone" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} error={form.errors.phone} />
                        <FormField label="Cargo" name="position" value={form.data.position} onChange={(event) => form.setData('position', event.target.value)} error={form.errors.position} />
                        <FormField label="Fecha ingreso" name="hired_at" type="date" value={form.data.hired_at} onChange={(event) => form.setData('hired_at', event.target.value)} error={form.errors.hired_at} />
                        <FormField label="Sueldo" name="salary_amount" type="number" step="0.01" min="0" value={form.data.salary_amount} onChange={(event) => form.setData('salary_amount', event.target.value)} error={form.errors.salary_amount} required />
                        <SelectField label="Frecuencia" name="salary_frequency" value={form.data.salary_frequency} onChange={(event) => form.setData('salary_frequency', event.target.value)}>
                            <option value="weekly">Semanal</option>
                            <option value="biweekly">Quincenal</option>
                            <option value="monthly">Mensual</option>
                            <option value="custom">Personalizado</option>
                        </SelectField>
                        <div className="md:col-span-2 xl:col-span-4">
                            <FormField label="Notas" name="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} error={form.errors.notes} />
                        </div>
                        <div className="flex flex-wrap gap-2 md:col-span-2 xl:col-span-4">
                            <button className="rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white" disabled={form.processing}>{editing ? 'Actualizar trabajador' : 'Registrar trabajador'}</button>
                            {editing ? <button type="button" onClick={cancelEdit} className="rounded-full border border-slate-300 px-5 py-2.5 text-sm">Cancelar</button> : null}
                        </div>
                    </form>
                ) : null}

                <form onSubmit={(event) => { event.preventDefault(); filterForm.get(route('human-resources.workers.index'), { preserveScroll: true, preserveState: true }); }} className="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-[1fr_120px_auto]">
                    <FormField label="Buscar" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} placeholder="Nombre, documento o cargo" />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end"><button className="rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white">Filtrar</button></div>
                </form>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th className="px-4 py-3">Trabajador</th><th className="px-4 py-3">Sucursal</th><th className="px-4 py-3">Cargo</th><th className="px-4 py-3 text-right">Sueldo</th><th className="px-4 py-3">Estado</th>{canManage ? <th className="px-4 py-3 text-right">Acciones</th> : null}</tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {workers.data.map((worker) => (
                                    <tr key={worker.id}>
                                        <td className="px-4 py-3"><strong>{worker.name}</strong><br /><span className="text-xs text-slate-500">{worker.user?.email ?? worker.document_number ?? 'Sin usuario vinculado'}</span></td>
                                        <td className="px-4 py-3">{worker.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{worker.position ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">Bs {Number(worker.salary_amount ?? 0).toFixed(2)}</td>
                                        <td className="px-4 py-3">{worker.is_active ? 'Activo' : 'Inactivo'}</td>
                                        {canManage ? <td className="px-4 py-3"><div className="flex justify-end gap-2"><IconButton icon="edit" label="Editar" onClick={() => startEdit(worker)} /><IconButton icon="power" label="Desactivar" tone="danger" onClick={async () => { if (await confirmAction({ title: 'Desactivar trabajador', text: 'No se eliminara su historial de pagos.', confirmButtonText: 'Desactivar' })) router.delete(route('human-resources.workers.destroy', worker.id), { preserveScroll: true }); }} /></div></td> : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 py-3"><Pagination links={workers.links} /></div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function emptyWorker(branches) {
    return { user_id: '', branch_id: branches[0]?.id ?? '', name: '', document_number: '', phone: '', position: '', hired_at: '', salary_amount: 0, salary_frequency: 'monthly', is_active: true, notes: '' };
}
