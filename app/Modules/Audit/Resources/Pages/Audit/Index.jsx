import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import FormField from '../../../../Shared/Resources/Components/FormField';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({ audits, filters, users, events, auditableTypes, canViewGlobal }) {
    const { data, setData, get, processing } = useForm({
        event: filters.event ?? '',
        user_id: filters.user_id ?? '',
        auditable_type: filters.auditable_type ?? '',
        ip_address: filters.ip_address ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });

    const submit = (event) => {
        event.preventDefault();
        get(route('audit.index'), { preserveScroll: true, preserveState: true });
    };

    const clear = () => {
        router.get(route('audit.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Auditoria</h2>}>
            <Head title="Auditoria" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Auditoria" description="Historial inmutable de cambios criticos con usuario, IP, fecha, valores anteriores y nuevos." />

                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-3 lg:grid-cols-6">
                    <SelectField label="Evento" name="event" value={data.event} onChange={(event) => setData('event', event.target.value)}>
                        <option value="">Todos</option>
                        {events.map((event) => <option key={event} value={event}>{event}</option>)}
                    </SelectField>
                    {canViewGlobal ? (
                        <SelectField label="Usuario" name="user_id" value={data.user_id} onChange={(event) => setData('user_id', event.target.value)}>
                            <option value="">Todos</option>
                            {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                        </SelectField>
                    ) : null}
                    <SelectField label="Modelo" name="auditable_type" value={data.auditable_type} onChange={(event) => setData('auditable_type', event.target.value)}>
                        <option value="">Todos</option>
                        {auditableTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                    </SelectField>
                    <FormField label="IP" name="ip_address" value={data.ip_address} onChange={(event) => setData('ip_address', event.target.value)} />
                    <FormField label="Desde" name="from" type="date" value={data.from} onChange={(event) => setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={data.to} onChange={(event) => setData('to', event.target.value)} />
                    <div className="flex items-end gap-2 sm:col-span-3 lg:col-span-6">
                        <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={clear}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Evento</th>
                                <th className="px-4 py-3 font-medium">Modelo</th>
                                <th className="px-4 py-3 font-medium">Descripcion</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                                <th className="px-4 py-3 font-medium">IP</th>
                                <th className="px-4 py-3 font-medium">Cambios</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {audits.data.map((audit) => (
                                <tr key={audit.id} className="align-top">
                                    <td className="whitespace-nowrap px-4 py-3">{formatDate(audit.created_at)}</td>
                                    <td className="px-4 py-3">{audit.event_label ?? audit.event}</td>
                                    <td className="px-4 py-3">
                                        <p>{audit.auditable_label ?? shortType(audit.auditable_type)}</p>
                                        <p className="text-xs text-slate-500">ID: {audit.auditable_id}</p>
                                    </td>
                                    <td className="px-4 py-3">{audit.description ?? '-'}</td>
                                    <td className="px-4 py-3">{audit.user?.name ?? 'Sistema'}</td>
                                    <td className="px-4 py-3">{audit.ip_address ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <ChangeBlock title="Antes" value={audit.old_values} />
                                        <ChangeBlock title="Nuevo" value={audit.new_values} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={audits.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function ChangeBlock({ title, value }) {
    const parsed = parseAuditValue(value);
    const keys = Object.keys(parsed);

    if (keys.length === 0) {
        return <p className="text-xs text-slate-500">{title}: sin datos</p>;
    }

    return (
        <details className="mb-2">
            <summary className="cursor-pointer text-xs font-semibold text-brand-primary">{title}</summary>
            <dl className="mt-2 grid gap-1 text-xs">
                {keys.map((key) => (
                    <div key={key} className="grid grid-cols-[120px_1fr] gap-2">
                        <dt className="font-semibold">{key}</dt>
                        <dd className="break-all">{String(parsed[key] ?? '')}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

function parseAuditValue(value) {
    if (!value) {
        return {};
    }

    if (typeof value === 'object') {
        return value;
    }

    try {
        return JSON.parse(value);
    } catch {
        return { valor: value };
    }
}

function shortType(type) {
    return type?.split('\\').at(-1) ?? '-';
}

function formatDate(value) {
    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
