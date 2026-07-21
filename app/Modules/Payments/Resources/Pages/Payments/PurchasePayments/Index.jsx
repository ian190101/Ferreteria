import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import { promptAction } from '@/Utils/alerts';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

export default function Index({ payments, payables, branches, methods, filters }) {
    const canManage = usePage().props.auth.permissions.includes('payments.manage');
    const decimalFormat = useDecimalFormatter('finance');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        payment_method_id: filters.payment_method_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });
    const paymentForm = useForm({
        purchase_id: payables[0]?.id ?? '',
        payment_method_id: methods[0]?.id ?? '',
        paid_at: currentDateTimeLocal(),
        amount: payables[0]?.balance_due ?? '',
        reference: '',
        notes: '',
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('payments.purchase-payments.index'), { preserveScroll: true, preserveState: true });
    };

    const selectPurchase = (value) => {
        const purchase = payables.find((item) => String(item.id) === String(value));
        paymentForm.setData({
            ...paymentForm.data,
            purchase_id: value,
            amount: purchase?.balance_due ?? '',
        });
    };

    const submitPayment = (event) => {
        event.preventDefault();
        paymentForm.post(route('payments.purchase-payments.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Pagos a proveedores</h2>}>
            <Head title="Pagos a proveedores" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Pagos a proveedores" description="Registro de abonos y cuentas por pagar de compras con saldos controlados desde backend." />
                    <Link href={route('payments.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        Pagos de clientes
                    </Link>
                </div>

                <div className="mb-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <Panel title="Cuentas por pagar">
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {payables.length === 0 ? (
                                <p className="px-4 py-5 text-sm text-slate-500">No hay compras con saldo pendiente.</p>
                            ) : payables.map((purchase) => (
                                <div key={purchase.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                    <div>
                                        <Link href={route('purchases.show', purchase.id)} className="font-semibold text-brand-primary hover:underline">
                                            {purchase.document_number}
                                        </Link>
                                        <p className="text-sm text-slate-600 dark:text-slate-300">{purchase.supplier?.name ?? 'Sin proveedor'} · {purchase.branch?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{formatDate(purchase.purchase_date)} · {purchase.status}</p>
                                    </div>
                                    <div className="text-left sm:text-right">
                                        <p className="text-sm text-slate-500">Saldo</p>
                                        <p className="font-semibold">Bs {decimalFormat.money(purchase.balance_due ?? 0)}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    {canManage ? (
                        <Panel title="Registrar pago a proveedor">
                            <form onSubmit={submitPayment} className="grid gap-4 p-4">
                                <SelectField label="Compra" name="purchase_id" value={paymentForm.data.purchase_id} onChange={(event) => selectPurchase(event.target.value)} error={paymentForm.errors.purchase_id} required>
                                    <option value="">Seleccionar</option>
                                    {payables.map((purchase) => (
                                        <option key={purchase.id} value={purchase.id}>
                                            {purchase.document_number} - {purchase.supplier?.name ?? 'Sin proveedor'} - Saldo {purchase.balance_due}
                                        </option>
                                    ))}
                                </SelectField>
                                <SelectField label="Metodo de pago" name="payment_method_id" value={paymentForm.data.payment_method_id} onChange={(event) => paymentForm.setData('payment_method_id', event.target.value)} error={paymentForm.errors.payment_method_id} required>
                                    <option value="">Seleccionar</option>
                                    {methods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                </SelectField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Fecha de pago" name="paid_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={paymentForm.errors.paid_at} />
                                    <FormField label="Monto" name="amount" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} min={decimalStep(decimalFormat.decimalsFor('money'))} value={paymentForm.data.amount} onChange={(event) => paymentForm.setData('amount', event.target.value)} error={paymentForm.errors.amount} required />
                                </div>
                                <FormField label="Referencia" name="reference" value={paymentForm.data.reference} onChange={(event) => paymentForm.setData('reference', event.target.value)} error={paymentForm.errors.reference} />
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="purchase-payment-notes">
                                    Notas
                                    <textarea id="purchase-payment-notes" rows="3" value={paymentForm.data.notes} onChange={(event) => paymentForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                                <button disabled={paymentForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar pago
                                </button>
                            </form>
                        </Panel>
                    ) : null}
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-6">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Metodo" name="payment_method_id" value={filterForm.data.payment_method_id} onChange={(event) => filterForm.setData('payment_method_id', event.target.value)}>
                        <option value="">Todos</option>
                        {methods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('payments.purchase-payments.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Pagos a proveedores registrados">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Compra</th>
                                    <th className="px-4 py-3 font-medium">Proveedor</th>
                                    <th className="px-4 py-3 font-medium">Metodo</th>
                                    <th className="px-4 py-3 font-medium">Referencia</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={canManage ? 7 : 6} className="px-4 py-6 text-center text-sm text-slate-500">Sin pagos registrados.</td>
                                    </tr>
                                ) : payments.data.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(payment.paid_at)}</td>
                                        <td className="px-4 py-3">{payment.purchase?.document_number ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.purchase?.supplier?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.method?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.reference ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">Bs {decimalFormat.money(payment.amount ?? 0)}</td>
                                        {canManage ? (
                                            <td className="px-4 py-3 text-right">
                                                <IconButton icon="close" label="Anular" tone="danger" onClick={() => voidPayment(payment)} />
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={payments.links} />
                </div>
            </section>
        </AuthenticatedLayout>
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

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

async function voidPayment(payment) {
    const reason = await promptAction({
        title: 'Anular pago a proveedor',
        text: `Motivo para anular el pago ${payment.id}`,
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('payments.purchase-payments.void', payment.id), { reason }, { preserveScroll: true });
}
