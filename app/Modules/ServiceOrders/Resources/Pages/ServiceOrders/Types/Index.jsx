import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { confirmAction } from '@/Utils/alerts';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const EMPTY_FORM = {
    code: '',
    name: '',
    description: '',
    billing_unit: 'service',
    requires_materials: false,
    requires_responsible: false,
    requires_schedule: false,
    is_delivery: false,
    is_active: true,
    sort_order: 0,
};

export default function Index({ types, filters = {}, deliveryCode }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('service-orders.manage');
    const [editing, setEditing] = useState(null);
    const form = useForm(EMPTY_FORM);
    const [query, setQuery] = useState({
        search: filters.search ?? '',
        status: filters.status ?? '',
        per_page: filters.per_page ?? 12,
    });
    const didMount = useRef(false);

    useEffect(() => {
        if (!didMount.current) {
            didMount.current = true;

            return undefined;
        }

        const timeout = window.setTimeout(() => {
            router.get(route('service-orders.types.index'), cleanQuery(query), {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [query.search, query.status, query.per_page]);

    const submit = (event) => {
        event.preventDefault();

        if (editing) {
            form.put(route('service-orders.types.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => resetForm(form, setEditing),
            });

            return;
        }

        form.post(route('service-orders.types.store'), {
            preserveScroll: true,
            onSuccess: () => resetForm(form, setEditing),
        });
    };

    const editType = (type) => {
        setEditing(type);
        form.setData({
            code: type.code ?? '',
            name: type.name ?? '',
            description: type.description ?? '',
            billing_unit: type.billing_unit ?? 'service',
            requires_materials: Boolean(type.requires_materials),
            requires_responsible: Boolean(type.requires_responsible),
            requires_schedule: Boolean(type.requires_schedule),
            is_delivery: Boolean(type.is_delivery),
            is_active: Boolean(type.is_active),
            sort_order: type.sort_order ?? 0,
        });
    };

    const deleteType = async (type) => {
        if (type.is_system) {
            return;
        }

        if (await confirmAction({ title: 'Eliminar tipo de servicio', text: 'Se ocultara para nuevas configuraciones, sin borrar historicos.', confirmButtonText: 'Eliminar' })) {
            router.delete(route('service-orders.types.destroy', type.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Servicios</h2>}>
            <Head title="Tipos de servicio" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Tipos de servicio"
                    description="Configura servicios vendibles, mano de obra, transporte, delivery y reglas base para ventas u ordenes de servicio."
                />

                <div className="mb-6 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    Los servicios pueden venderse junto con productos. Transporte/Delivery es un tipo protegido: no se borra, pero puede activarse u ocultarse segun el perfil y su estado.
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_420px]">
                    <div className="space-y-5">
                        <div className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-[1fr_180px_120px]">
                            <FormField label="Buscar" name="search" value={query.search} onChange={(event) => setQuery((current) => ({ ...current, search: event.target.value }))} placeholder="Nombre, codigo o descripcion" />
                            <SelectField label="Estado" name="status" value={query.status} onChange={(event) => setQuery((current) => ({ ...current, status: event.target.value }))}>
                                <option value="">Todos</option>
                                <option value="active">Activos</option>
                                <option value="inactive">Inactivos</option>
                            </SelectField>
                            <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={query.per_page} onChange={(event) => setQuery((current) => ({ ...current, per_page: event.target.value }))} />
                        </div>

                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Servicio</th>
                                        <th className="px-4 py-3 font-medium">Cobro</th>
                                        <th className="px-4 py-3 font-medium">Reglas</th>
                                        <th className="px-4 py-3 font-medium">Estado</th>
                                        {canManage ? <th className="px-4 py-3 font-medium">Acciones</th> : null}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {types.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={canManage ? 5 : 4} className="px-4 py-8 text-center text-slate-500">No hay tipos de servicio para estos filtros.</td>
                                        </tr>
                                    ) : types.data.map((type) => (
                                        <tr key={type.id}>
                                            <td className="px-4 py-3">
                                                <p className="font-semibold text-slate-950 dark:text-white">{type.name}</p>
                                                <p className="text-xs text-slate-500">{type.code}</p>
                                                {type.description ? <p className="mt-1 max-w-xl text-xs text-slate-500">{type.description}</p> : null}
                                                {type.code === deliveryCode ? <span className="mt-2 inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-100">Protegido: transporte/delivery</span> : null}
                                            </td>
                                            <td className="px-4 py-3">{billingUnitLabel(type.billing_unit)}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1">
                                                    {type.requires_materials ? <Badge text="Materiales" /> : null}
                                                    {type.requires_responsible ? <Badge text="Responsable" /> : null}
                                                    {type.requires_schedule ? <Badge text="Agenda" /> : null}
                                                    {type.is_delivery ? <Badge text="Delivery" /> : null}
                                                    {!type.requires_materials && !type.requires_responsible && !type.requires_schedule && !type.is_delivery ? <span className="text-slate-500">Sin reglas especiales</span> : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">{type.is_active ? 'Activo' : 'Oculto'}</td>
                                            {canManage ? (
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-wrap gap-2">
                                                        <button type="button" onClick={() => editType(type)} className="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold dark:border-slate-700">Editar</button>
                                                        <button type="button" disabled={type.is_system} onClick={() => deleteType(type)} className="rounded-md border border-red-200 px-3 py-2 text-xs font-semibold text-red-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-900/60">Eliminar</button>
                                                    </div>
                                                </td>
                                            ) : null}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="px-4 py-3">
                                <Pagination links={types.links} />
                            </div>
                        </div>
                    </div>

                    {canManage ? (
                        <form onSubmit={submit} className="h-fit rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="text-lg font-bold text-slate-950 dark:text-white">{editing ? 'Editar tipo' : 'Nuevo tipo'}</h3>
                            {editing?.is_system ? <p className="mt-1 text-xs text-amber-600">Tipo del sistema: el codigo y la marca delivery no se cambian para proteger reglas futuras.</p> : null}
                            <div className="mt-4 space-y-4">
                                <FormField label="Nombre" name="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required />
                                <FormField label="Codigo" name="code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} error={form.errors.code} disabled={editing?.is_system} placeholder="instalacion_basica" />
                                <SelectField label="Unidad de cobro" name="billing_unit" value={form.data.billing_unit} onChange={(event) => form.setData('billing_unit', event.target.value)} error={form.errors.billing_unit}>
                                    <option value="service">Por servicio</option>
                                    <option value="hour">Por hora</option>
                                    <option value="day">Por dia</option>
                                    <option value="route">Por ruta</option>
                                    <option value="session">Por sesion</option>
                                    <option value="project">Por proyecto</option>
                                </SelectField>
                                <FormField label="Descripcion" name="description" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} error={form.errors.description} />
                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <Toggle label="Materiales" checked={form.data.requires_materials} onChange={(value) => form.setData('requires_materials', value)} />
                                    <Toggle label="Responsable" checked={form.data.requires_responsible} onChange={(value) => form.setData('requires_responsible', value)} />
                                    <Toggle label="Agenda" checked={form.data.requires_schedule} onChange={(value) => form.setData('requires_schedule', value)} />
                                    <Toggle label="Delivery" checked={form.data.is_delivery} onChange={(value) => form.setData('is_delivery', value)} disabled={editing?.is_system} />
                                </div>
                                <SelectField label="Estado" name="is_active" value={form.data.is_active ? '1' : '0'} onChange={(event) => form.setData('is_active', event.target.value === '1')} error={form.errors.is_active}>
                                    <option value="1">Activo</option>
                                    <option value="0">Oculto</option>
                                </SelectField>
                                <FormField label="Orden" name="sort_order" type="number" min="0" value={form.data.sort_order} onChange={(event) => form.setData('sort_order', event.target.value)} error={form.errors.sort_order} />
                                <button className="w-full rounded-md bg-brand-primary px-4 py-3 text-sm font-bold text-white" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear tipo'}</button>
                                {editing ? <button type="button" onClick={() => resetForm(form, setEditing)} className="w-full rounded-md border border-slate-300 px-4 py-3 text-sm font-semibold dark:border-slate-700">Cancelar edicion</button> : null}
                            </div>
                        </form>
                    ) : null}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Badge({ text }) {
    return <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{text}</span>;
}

function Toggle({ label, checked, onChange, disabled = false }) {
    return (
        <label className={`flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 dark:border-slate-800 ${disabled ? 'opacity-60' : ''}`}>
            <input type="checkbox" checked={Boolean(checked)} onChange={(event) => onChange(event.target.checked)} disabled={disabled} className="h-4 w-4 rounded border-slate-300 text-brand-primary" />
            {label}
        </label>
    );
}

function billingUnitLabel(value) {
    return {
        service: 'Por servicio',
        hour: 'Por hora',
        day: 'Por dia',
        route: 'Por ruta',
        session: 'Por sesion',
        project: 'Por proyecto',
    }[value] ?? value;
}

function cleanQuery(query) {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

function resetForm(form, setEditing) {
    setEditing(null);
    form.setData(EMPTY_FORM);
    form.clearErrors();
}
