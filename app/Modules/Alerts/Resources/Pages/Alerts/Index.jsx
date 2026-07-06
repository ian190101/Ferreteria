import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm } from '@inertiajs/react';

const typeLabels = {
    low_stock: 'Stock bajo',
    receivable: 'Cuenta por cobrar',
    payment_promise: 'Promesa de pago',
    cash_open: 'Caja abierta',
    customer_follow_up: 'Seguimiento cliente',
    depleted_coil: 'Bobina agotada',
};

const severityLabels = {
    critical: 'Critica',
    warning: 'Advertencia',
    info: 'Informativa',
};

export default function Index({ alerts, summary, filters, types, severities }) {
    const { data, setData, get, processing } = useForm({
        type: filters.type ?? '',
        severity: filters.severity ?? '',
        per_page: filters.per_page ?? 15,
    });

    const submit = (event) => {
        event.preventDefault();
        get(route('alerts.index'), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Alertas</h2>}
        >
            <Head title="Alertas" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Alertas operativas"
                    description="Bandeja paginada de pendientes generados desde stock, cuentas por cobrar, caja, CRM y bobinas."
                />

                <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard title="Total" value={summary.total} tone="default" />
                    <SummaryCard title="Criticas" value={summary.critical} tone="critical" />
                    <SummaryCard title="Advertencias" value={summary.warning} tone="warning" />
                    <SummaryCard title="Informativas" value={summary.info} tone="info" />
                </div>

                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <SelectField label="Tipo" name="type" value={data.type} onChange={(event) => setData('type', event.target.value)}>
                        <option value="">Todos</option>
                        {types.map((type) => (
                            <option key={type} value={type}>{alertType(type)}</option>
                        ))}
                    </SelectField>
                    <SelectField label="Severidad" name="severity" value={data.severity} onChange={(event) => setData('severity', event.target.value)}>
                        <option value="">Todas</option>
                        {severities.map((severity) => (
                            <option key={severity} value={severity}>{severityLabel(severity)}</option>
                        ))}
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="50" value={data.per_page} onChange={(event) => setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2 sm:col-span-2">
                        <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('alerts.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    {alerts.data.length === 0 ? (
                        <p className="px-4 py-6 text-sm text-slate-500 dark:text-slate-400">No hay alertas con los filtros seleccionados.</p>
                    ) : (
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {alerts.data.map((alert) => (
                                <AlertRow key={alert.id} alert={alert} />
                            ))}
                        </div>
                    )}
                </div>

                <div className="mt-6">
                    <Pagination links={alerts.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function SummaryCard({ title, value, tone }) {
    const toneClass = {
        default: 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
        critical: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950',
        warning: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950',
        info: 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950',
    }[tone];

    return (
        <article className={`rounded-lg border p-5 shadow-sm ${toneClass}`}>
            <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{title}</p>
            <p className="mt-2 text-3xl font-semibold text-slate-950 dark:text-slate-50">{value}</p>
        </article>
    );
}

function AlertRow({ alert }) {
    return (
        <article className="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <SeverityBadge severity={alert.severity} />
                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {alertType(alert.type)}
                    </span>
                    {alert.branch ? <span className="text-xs text-slate-500">{alert.branch}</span> : null}
                </div>
                <h3 className="mt-2 font-semibold text-slate-950 dark:text-slate-50">{alert.title}</h3>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{alert.message}</p>
                <p className="mt-1 text-xs text-slate-500">{formatDate(alert.sort_at)}</p>
            </div>
            {alert.source_url ? (
                <div className="flex items-center lg:justify-end">
                    <Link className="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200" href={alert.source_url}>
                        Revisar
                    </Link>
                </div>
            ) : null}
        </article>
    );
}

function SeverityBadge({ severity }) {
    const className = {
        critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-200',
        warning: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-200',
        info: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-200',
    }[severity] ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';

    return (
        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${className}`}>
            {severityLabel(severity)}
        </span>
    );
}

function alertType(type) {
    return typeLabels[type] ?? type;
}

function severityLabel(severity) {
    return severityLabels[severity] ?? severity;
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
