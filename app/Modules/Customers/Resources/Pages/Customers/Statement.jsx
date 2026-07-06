import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const money = (value) => new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value ?? 0));
const date = (value) => value ? new Intl.DateTimeFormat('es-BO').format(new Date(value)) : '-';

function MetricCard({ label, value, tone = 'default' }) {
    const tones = {
        default: 'border-slate-200 bg-white text-slate-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100',
        warning: 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
    };

    return (
        <div className={`rounded-lg border p-4 shadow-sm ${tones[tone]}`}>
            <p className="text-sm opacity-75">{label}</p>
            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function EmptyRow({ columns, message }) {
    return (
        <tr>
            <td colSpan={columns} className="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                {message}
            </td>
        </tr>
    );
}

export default function Statement({ customer, metrics, filters, sales, payments, creditNotes, promises, interactions }) {
    const canManage = usePage().props.auth.permissions.includes('customers.manage');
    const filterForm = useForm({
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 10,
    });
    const interactionForm = useForm({
        type: 'call',
        contact_at: currentDateTimeLocal(),
        follow_up_at: '',
        subject: '',
        notes: '',
        status: 'pending',
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('customers.statement', customer.id), { preserveScroll: true, preserveState: true });
    };

    const submitInteraction = (event) => {
        event.preventDefault();
        interactionForm.post(route('customers.interactions.store', customer.id), {
            preserveScroll: true,
            onSuccess: () => interactionForm.reset('follow_up_at', 'subject', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Estado de cuenta</h2>}>
            <Head title={`Estado de cuenta - ${customer.name}`} />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Estado de cuenta" description="Resumen comercial del cliente con ventas, pagos, notas de credito y promesas de pago paginadas desde el servidor." />
                    <Link href={route('customers.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        Volver
                    </Link>
                </div>

                <div className="mb-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-[1.3fr_1fr_1fr]">
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Cliente</p>
                            <h3 className="mt-1 text-xl font-semibold text-slate-950 dark:text-slate-100">{customer.name}</h3>
                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{customer.document_number ?? 'Sin documento'} · {customer.type?.name ?? 'Sin tipo'}</p>
                        </div>
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Contacto</p>
                            <p className="mt-1 font-medium text-slate-900 dark:text-slate-100">{customer.phone ?? customer.email ?? '-'}</p>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{customer.address ?? '-'}</p>
                        </div>
                        <div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Estado</p>
                            <p className="mt-1 font-medium text-slate-900 dark:text-slate-100">{customer.is_active ? 'Activo' : 'Inactivo'}</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} error={filterForm.errors.from} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} error={filterForm.errors.to} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="50" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} error={filterForm.errors.per_page} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} type="submit" className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white">
                            Filtrar
                        </button>
                        <button type="button" onClick={() => router.get(route('customers.statement', customer.id))} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <MetricCard label="Facturado" value={`Bs ${money(metrics.sales_total)}`} />
                    <MetricCard label="Pagos recibidos" value={`Bs ${money(metrics.payments_total)}`} tone="success" />
                    <MetricCard label="Notas de credito" value={`Bs ${money(metrics.credit_notes_total)}`} />
                    <MetricCard label="Saldo por cobrar" value={`Bs ${money(metrics.balance_due)}`} tone="warning" />
                    <MetricCard label="Promesas pendientes" value={`${metrics.pending_promises_count} · Bs ${money(metrics.pending_promises_amount)}`} tone="warning" />
                </div>

                <div className="space-y-6">
                    <div className="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                        {canManage ? (
                            <form onSubmit={submitInteraction} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                                <h3 className="mb-4 font-semibold text-slate-900 dark:text-slate-100">Nuevo seguimiento</h3>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <SelectField label="Tipo" name="interaction_type" value={interactionForm.data.type} onChange={(event) => interactionForm.setData('type', event.target.value)} error={interactionForm.errors.type}>
                                        <option value="call">Llamada</option>
                                        <option value="whatsapp">WhatsApp</option>
                                        <option value="visit">Visita</option>
                                        <option value="email">Correo electronico</option>
                                        <option value="note">Nota</option>
                                    </SelectField>
                                    <SelectField label="Estado" name="interaction_status" value={interactionForm.data.status} onChange={(event) => interactionForm.setData('status', event.target.value)} error={interactionForm.errors.status}>
                                        <option value="pending">Pendiente</option>
                                        <option value="completed">Completado</option>
                                    </SelectField>
                                    <FormField label="Contacto" name="contact_at" type="datetime-local" value={interactionForm.data.contact_at} onChange={(event) => interactionForm.setData('contact_at', event.target.value)} error={interactionForm.errors.contact_at} />
                                    <FormField label="Proximo seguimiento" name="follow_up_at" type="datetime-local" value={interactionForm.data.follow_up_at} onChange={(event) => interactionForm.setData('follow_up_at', event.target.value)} error={interactionForm.errors.follow_up_at} />
                                    <div className="sm:col-span-2">
                                        <FormField label="Asunto" name="subject" value={interactionForm.data.subject} onChange={(event) => interactionForm.setData('subject', event.target.value)} error={interactionForm.errors.subject} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <FormField label="Notas" name="notes" value={interactionForm.data.notes} onChange={(event) => interactionForm.setData('notes', event.target.value)} error={interactionForm.errors.notes} />
                                    </div>
                                </div>
                                <button disabled={interactionForm.processing} className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar seguimiento
                                </button>
                            </form>
                        ) : null}

                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                                <h3 className="font-semibold text-slate-900 dark:text-slate-100">CRM y seguimiento</h3>
                            </div>
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Fecha</th>
                                        <th className="px-4 py-3 font-medium">Asunto</th>
                                        <th className="px-4 py-3 font-medium">Seguimiento</th>
                                        <th className="px-4 py-3 font-medium">Estado</th>
                                        {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {interactions.data.length === 0 ? <EmptyRow columns={canManage ? 5 : 4} message="Sin interacciones registradas." /> : interactions.data.map((interaction) => (
                                        <tr key={interaction.id}>
                                            <td className="px-4 py-3">{date(interaction.contact_at)}</td>
                                            <td className="px-4 py-3">
                                                <p className="font-medium text-slate-900 dark:text-slate-100">{interaction.subject}</p>
                                                <p className="text-xs text-slate-500">{typeLabel(interaction.type)} - {interaction.user?.name ?? 'Sistema'}</p>
                                            </td>
                                            <td className="px-4 py-3">{date(interaction.follow_up_at)}</td>
                                            <td className="px-4 py-3">{interaction.status === 'completed' ? 'Completado' : 'Pendiente'}</td>
                                            {canManage ? (
                                                <td className="px-4 py-3 text-right">
                                                    {interaction.status === 'pending' ? (
                                                        <IconButton icon="check" label="Completar" tone="success" onClick={() => router.patch(route('customers.interactions.complete', [customer.id, interaction.id]), {}, { preserveScroll: true })} />
                                                    ) : null}
                                                </td>
                                            ) : null}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="px-4 py-3"><Pagination links={interactions.links} /></div>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-900 dark:text-slate-100">Ventas</h3>
                        </div>
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Comprobante</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    <th className="px-4 py-3 text-right font-medium">Saldo</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {sales.data.length === 0 ? <EmptyRow columns={6} message="Sin ventas para el periodo seleccionado." /> : sales.data.map((sale) => (
                                    <tr key={sale.id}>
                                        <td className="px-4 py-3">{date(sale.sold_at)}</td>
                                        <td className="px-4 py-3 font-medium">{sale.receipt_number}</td>
                                        <td className="px-4 py-3">{sale.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">{sale.currency?.symbol ?? 'Bs'} {money(sale.total)}</td>
                                        <td className="px-4 py-3 text-right">Bs {money(sale.balance_due)}</td>
                                        <td className="px-4 py-3">{sale.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3"><Pagination links={sales.links} /></div>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                                <h3 className="font-semibold text-slate-900 dark:text-slate-100">Pagos</h3>
                            </div>
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Fecha</th>
                                        <th className="px-4 py-3 font-medium">Venta</th>
                                        <th className="px-4 py-3 font-medium">Metodo</th>
                                        <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {payments.data.length === 0 ? <EmptyRow columns={4} message="Sin pagos registrados." /> : payments.data.map((payment) => (
                                        <tr key={payment.id}>
                                            <td className="px-4 py-3">{date(payment.paid_at)}</td>
                                            <td className="px-4 py-3">{payment.sale?.receipt_number ?? '-'}</td>
                                            <td className="px-4 py-3">{payment.method?.name ?? '-'}</td>
                                            <td className="px-4 py-3 text-right">Bs {money(payment.amount_bob)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="px-4 py-3"><Pagination links={payments.links} /></div>
                        </div>

                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                                <h3 className="font-semibold text-slate-900 dark:text-slate-100">Notas de credito</h3>
                            </div>
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Fecha</th>
                                        <th className="px-4 py-3 font-medium">Nota</th>
                                        <th className="px-4 py-3 font-medium">Venta</th>
                                        <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {creditNotes.data.length === 0 ? <EmptyRow columns={4} message="Sin notas de credito." /> : creditNotes.data.map((note) => (
                                        <tr key={note.id}>
                                            <td className="px-4 py-3">{date(note.issued_at)}</td>
                                            <td className="px-4 py-3">{note.credit_number}</td>
                                            <td className="px-4 py-3">{note.sale?.receipt_number ?? '-'}</td>
                                            <td className="px-4 py-3 text-right">Bs {money(note.amount_bob)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <div className="px-4 py-3"><Pagination links={creditNotes.links} /></div>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-900 dark:text-slate-100">Promesas de pago</h3>
                        </div>
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha promesa</th>
                                    <th className="px-4 py-3 font-medium">Codigo</th>
                                    <th className="px-4 py-3 font-medium">Venta</th>
                                    <th className="px-4 py-3 font-medium">Contacto</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {promises.data.length === 0 ? <EmptyRow columns={6} message="Sin promesas de pago." /> : promises.data.map((promise) => (
                                    <tr key={promise.id}>
                                        <td className="px-4 py-3">{date(promise.promised_date)}</td>
                                        <td className="px-4 py-3">{promise.promise_number}</td>
                                        <td className="px-4 py-3">{promise.sale?.receipt_number ?? '-'}</td>
                                        <td className="px-4 py-3">{promise.contact_name ?? promise.contact_phone ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">Bs {money(promise.promised_amount)}</td>
                                        <td className="px-4 py-3">{promise.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3"><Pagination links={promises.links} /></div>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function typeLabel(type) {
    const labels = {
        call: 'Llamada',
        whatsapp: 'WhatsApp',
        visit: 'Visita',
        email: 'Correo electronico',
        note: 'Nota',
    };

    return labels[type] ?? type;
}
