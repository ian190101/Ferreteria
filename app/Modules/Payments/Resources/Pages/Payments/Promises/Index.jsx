import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { promptAction } from '@/Utils/alerts';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export default function Index({ promises, summary, branches, receivables, statuses, channels, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('payment-promises.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        status: filters.status ?? '',
        sale_id: filters.sale_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const promiseForm = useForm({
        sale_id: receivables[0]?.id ?? '',
        promise_number: `PROM-${new Date().getFullYear()}-${String(Date.now()).slice(-6)}`,
        promised_date: new Date().toISOString().slice(0, 10),
        promised_amount: receivables[0]?.balance_due ?? '',
        contact_name: receivables[0]?.customer_name ?? '',
        contact_phone: receivables[0]?.customer_contact ?? '',
        channel: 'phone',
        notes: '',
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('payments.promises.index'), { preserveScroll: true, preserveState: true });
    };

    const selectSale = (value) => {
        const sale = receivables.find((item) => String(item.id) === String(value));
        promiseForm.setData({
            ...promiseForm.data,
            sale_id: value,
            promised_amount: sale?.balance_due ?? '',
            contact_name: sale?.customer_name ?? '',
            contact_phone: sale?.customer_contact ?? '',
        });
    };

    const submitPromise = (event) => {
        event.preventDefault();
        promiseForm.post(route('payments.promises.store'), {
            preserveScroll: true,
            onSuccess: () => promiseForm.reset('notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Pagos</h2>}>
            <Head title="Cobranza" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Cobranza" description="Seguimiento de promesas de pago para cuentas por cobrar, sin afectar caja ni saldos hasta registrar un pago real." />

                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric label="Pendientes" value={summary.pending_count} />
                    <Metric label="Vencidas" value={summary.overdue_count} tone="danger" />
                    <Metric label="Vencen hoy" value={summary.due_today_count} tone="warning" />
                    <Metric label="Monto prometido" value={`Bs ${moneyFormatter.format(Number(summary.pending_amount ?? 0))}`} />
                </div>

                <div className="mb-6 grid gap-6 xl:grid-cols-[1fr_1fr]">
                    <Panel title="Cuentas por cobrar">
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {receivables.length === 0 ? (
                                <p className="px-4 py-5 text-sm text-slate-500">No hay notas de venta con saldo pendiente.</p>
                            ) : receivables.slice(0, 12).map((sale) => (
                                <div key={sale.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                    <div>
                                        <Link href={route('sales.show', sale.id)} className="font-semibold text-brand-primary hover:underline">
                                            {sale.receipt_number}
                                        </Link>
                                        <p className="text-sm text-slate-600 dark:text-slate-300">{sale.customer_name ?? 'Sin cliente'} - {sale.branch?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{formatDate(sale.sold_at)}</p>
                                    </div>
                                    <div className="text-left sm:text-right">
                                        <p className="text-sm text-slate-500">Saldo</p>
                                        <p className="font-semibold">{sale.currency?.symbol ?? 'Bs'} {moneyFormatter.format(Number(sale.balance_due ?? 0))}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    {canManage ? (
                        <Panel title="Nueva promesa">
                            <form onSubmit={submitPromise} className="grid gap-4 p-4">
                                <SelectField label="Nota de venta" name="sale_id" value={promiseForm.data.sale_id} onChange={(event) => selectSale(event.target.value)} error={promiseForm.errors.sale_id} required>
                                    <option value="">Seleccionar</option>
                                    {receivables.map((sale) => (
                                        <option key={sale.id} value={sale.id}>
                                            {sale.receipt_number} - {sale.customer_name ?? 'Sin cliente'} - Saldo {sale.balance_due}
                                        </option>
                                    ))}
                                </SelectField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Numero" name="promise_number" value={promiseForm.data.promise_number} onChange={(event) => promiseForm.setData('promise_number', event.target.value)} error={promiseForm.errors.promise_number} required />
                                    <FormField label="Fecha prometida" name="promised_date" type="date" value={promiseForm.data.promised_date} onChange={(event) => promiseForm.setData('promised_date', event.target.value)} error={promiseForm.errors.promised_date} required />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Monto" name="promised_amount" type="number" step="0.01" min="0.01" value={promiseForm.data.promised_amount} onChange={(event) => promiseForm.setData('promised_amount', event.target.value)} error={promiseForm.errors.promised_amount} required />
                                    <SelectField label="Canal" name="channel" value={promiseForm.data.channel} onChange={(event) => promiseForm.setData('channel', event.target.value)} error={promiseForm.errors.channel} required>
                                        {channels.map((channel) => <option key={channel} value={channel}>{channelLabel(channel)}</option>)}
                                    </SelectField>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Contacto" name="contact_name" value={promiseForm.data.contact_name} onChange={(event) => promiseForm.setData('contact_name', event.target.value)} error={promiseForm.errors.contact_name} />
                                    <FormField label="Telefono" name="contact_phone" value={promiseForm.data.contact_phone} onChange={(event) => promiseForm.setData('contact_phone', event.target.value)} error={promiseForm.errors.contact_phone} />
                                </div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                    Notas
                                    <textarea id="notes" rows="3" value={promiseForm.data.notes} onChange={(event) => promiseForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                                <button disabled={promiseForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar promesa
                                </button>
                            </form>
                        </Panel>
                    ) : null}
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-7">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                    </SelectField>
                    <SelectField label="Venta" name="sale_id" value={filterForm.data.sale_id} onChange={(event) => filterForm.setData('sale_id', event.target.value)}>
                        <option value="">Todas</option>
                        {receivables.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <FormField label="Buscar" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('payments.promises.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Promesas registradas">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Promesa</th>
                                    <th className="px-4 py-3 font-medium">Venta</th>
                                    <th className="px-4 py-3 font-medium">Contacto</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                    <th className="px-4 py-3 font-medium">Usuario</th>
                                    {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {promises.data.map((promise) => (
                                    <tr key={promise.id}>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            <p className="font-medium">{promise.promise_number}</p>
                                            <p className={isOverdue(promise) ? 'text-xs text-red-600' : 'text-xs text-slate-500'}>
                                                {formatDate(promise.promised_date)}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p>{promise.sale?.receipt_number ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{promise.sale?.customer_name ?? '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p>{promise.contact_name ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{promise.contact_phone ?? channelLabel(promise.channel)}</p>
                                        </td>
                                        <td className="px-4 py-3 text-right">Bs {moneyFormatter.format(Number(promise.promised_amount ?? 0))}</td>
                                        <td className="px-4 py-3">{statusLabel(promise.status)}</td>
                                        <td className="px-4 py-3">{promise.user?.name ?? '-'}</td>
                                        {canManage ? (
                                            <td className="px-4 py-3 text-right">
                                                {promise.status === 'pending' ? (
                                                    <div className="flex justify-end gap-3">
                                                        <button type="button" className="text-brand-primary hover:underline" onClick={() => resolvePromise(promise, 'fulfilled')}>
                                                            Cumplida
                                                        </button>
                                                        <button type="button" className="text-red-600 hover:underline" onClick={() => resolvePromise(promise, 'broken')}>
                                                            Incumplida
                                                        </button>
                                                        <button type="button" className="text-slate-600 hover:underline dark:text-slate-300" onClick={() => resolvePromise(promise, 'cancelled')}>
                                                            Cancelar
                                                        </button>
                                                    </div>
                                                ) : <span className="text-slate-400">-</span>}
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                                {promises.data.length === 0 ? (
                                    <tr>
                                        <td className="px-4 py-6 text-center text-slate-500" colSpan={canManage ? 7 : 6}>
                                            No hay promesas registradas.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={promises.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value, tone = 'default' }) {
    const toneClass = {
        default: 'text-slate-900 dark:text-slate-100',
        danger: 'text-red-600',
        warning: 'text-amber-600',
    }[tone];

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${toneClass}`}>{value}</p>
        </div>
    );
}

function Panel({ title, children }) {
    return (
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h3 className="font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            </div>
            {children}
        </section>
    );
}

function statusLabel(status) {
    return {
        pending: 'Pendiente',
        fulfilled: 'Cumplida',
        broken: 'Incumplida',
        cancelled: 'Cancelada',
    }[status] ?? status;
}

function channelLabel(channel) {
    return {
        phone: 'Telefono',
        whatsapp: 'WhatsApp',
        visit: 'Visita',
        email: 'Correo',
        other: 'Otro',
    }[channel] ?? channel;
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
    }).format(new Date(value));
}

function isOverdue(promise) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const promised = new Date(promise.promised_date);
    promised.setHours(0, 0, 0, 0);

    return promise.status === 'pending' && promised < today;
}

async function resolvePromise(promise, status) {
    const notes = await promptAction({
        title: 'Actualizar promesa',
        text: `Notas para marcar ${promise.promise_number} como ${statusLabel(status)}`,
        confirmButtonText: 'Guardar',
        placeholder: 'Puedes escribir una nota breve.',
    });

    if (notes === null) {
        return;
    }

    router.patch(route('payments.promises.resolve', promise.id), { status, notes }, { preserveScroll: true });
}
