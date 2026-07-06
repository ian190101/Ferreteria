import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import { confirmAction, promptAction } from '@/Utils/alerts';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export default function Index({ payments, receivables, branches, methods, methodCatalog, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('payments.manage');
    const [editingMethod, setEditingMethod] = useState(null);
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        payment_method_id: filters.payment_method_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });
    const paymentForm = useForm({
        sale_id: receivables[0]?.id ?? '',
        payment_method_id: methods[0]?.id ?? '',
        paid_at: currentDateTimeLocal(),
        amount: receivables[0]?.balance_due ?? '',
        reference: '',
        notes: '',
    });
    const methodForm = useForm({
        name: '',
        code: '',
        requires_reference: false,
        is_active: true,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('payments.index'), { preserveScroll: true, preserveState: true });
    };

    const selectSale = (value) => {
        const sale = receivables.find((item) => String(item.id) === String(value));
        paymentForm.setData({
            ...paymentForm.data,
            sale_id: value,
            amount: sale?.balance_due ?? '',
        });
    };

    const submitPayment = (event) => {
        event.preventDefault();
        paymentForm.post(route('payments.store'), { preserveScroll: true });
    };

    const submitMethod = (event) => {
        event.preventDefault();

        if (editingMethod) {
            methodForm.put(route('payments.methods.update', editingMethod.id), {
                preserveScroll: true,
                onSuccess: cancelMethodEdit,
            });

            return;
        }

        methodForm.post(route('payments.methods.store'), {
            preserveScroll: true,
            onSuccess: () => methodForm.reset(),
        });
    };

    const startMethodEdit = (method) => {
        setEditingMethod(method);
        methodForm.clearErrors();
        methodForm.setData({
            name: method.name,
            code: method.code,
            requires_reference: Boolean(method.requires_reference),
            is_active: Boolean(method.is_active),
        });
    };

    const cancelMethodEdit = () => {
        setEditingMethod(null);
        methodForm.clearErrors();
        methodForm.setData({
            name: '',
            code: '',
            requires_reference: false,
            is_active: true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Pagos</h2>}>
            <Head title="Pagos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Pagos" description="Registro de abonos, saldos pendientes y metodos de pago para notas de venta." />

                <div className="mb-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <Panel title="Cuentas por cobrar">
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {receivables.length === 0 ? (
                                <div className="px-4 py-5 text-sm text-slate-500">
                                    <p>No hay notas de venta con saldo pendiente.</p>
                                    <p className="mt-1">Las cotizaciones no se pagan directamente: primero conviertelas a nota de venta desde Ventas.</p>
                                    <Link href={route('sales.index')} className="mt-3 inline-flex rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                                        Ir a ventas
                                    </Link>
                                </div>
                            ) : receivables.map((sale) => (
                                <div key={sale.id} className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                                    <div>
                                        <Link href={route('sales.show', sale.id)} className="font-semibold text-brand-primary hover:underline">
                                            {sale.receipt_number}
                                        </Link>
                                        <p className="text-sm text-slate-600 dark:text-slate-300">{sale.customer_name ?? 'Sin cliente'} · {sale.branch?.name ?? '-'}</p>
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
                        <Panel title="Registrar pago">
                            <form onSubmit={submitPayment} className="grid gap-4 p-4">
                                <SelectField label="Nota de venta" name="sale_id" value={paymentForm.data.sale_id} onChange={(event) => selectSale(event.target.value)} error={paymentForm.errors.sale_id} required>
                                    <option value="">Seleccionar</option>
                                    {receivables.map((sale) => (
                                        <option key={sale.id} value={sale.id}>
                                            {sale.receipt_number} - {sale.customer_name ?? 'Sin cliente'} - Saldo {sale.balance_due}
                                        </option>
                                    ))}
                                </SelectField>
                                <SelectField label="Metodo de pago" name="payment_method_id" value={paymentForm.data.payment_method_id} onChange={(event) => paymentForm.setData('payment_method_id', event.target.value)} error={paymentForm.errors.payment_method_id} required>
                                    <option value="">Seleccionar</option>
                                    {methods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                </SelectField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Fecha de pago" name="paid_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={paymentForm.errors.paid_at} />
                                    <FormField label="Monto" name="amount" type="number" step="0.01" min="0.01" value={paymentForm.data.amount} onChange={(event) => paymentForm.setData('amount', event.target.value)} error={paymentForm.errors.amount} required />
                                </div>
                                <FormField label="Referencia" name="reference" value={paymentForm.data.reference} onChange={(event) => paymentForm.setData('reference', event.target.value)} error={paymentForm.errors.reference} />
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                    Notas
                                    <textarea id="notes" rows="3" value={paymentForm.data.notes} onChange={(event) => paymentForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
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
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('payments.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                {canManage ? (
                    <form onSubmit={submitMethod} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-5">
                        <FormField label="Metodo" name="name" value={methodForm.data.name} onChange={(event) => methodForm.setData('name', event.target.value)} error={methodForm.errors.name} required />
                        <FormField label="Codigo" name="code" value={methodForm.data.code} onChange={(event) => methodForm.setData('code', event.target.value)} error={methodForm.errors.code} required />
                        <SelectField label="Referencia obligatoria" name="requires_reference" value={methodForm.data.requires_reference ? '1' : '0'} onChange={(event) => methodForm.setData('requires_reference', event.target.value === '1')} error={methodForm.errors.requires_reference}>
                            <option value="0">No</option>
                            <option value="1">Si</option>
                        </SelectField>
                        <SelectField label="Estado" name="method_is_active" value={methodForm.data.is_active ? '1' : '0'} onChange={(event) => methodForm.setData('is_active', event.target.value === '1')} error={methodForm.errors.is_active}>
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </SelectField>
                        <div className="flex items-end">
                            <button disabled={methodForm.processing} className="rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary" type="submit">
                                {editingMethod ? 'Actualizar metodo' : 'Agregar metodo'}
                            </button>
                            {editingMethod ? (
                                <button type="button" onClick={cancelMethodEdit} className="ms-3 rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                                    Cancelar
                                </button>
                            ) : null}
                        </div>
                    </form>
                ) : null}

                {canManage ? (
                    <Panel title="Metodos de pago">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Metodo</th>
                                        <th className="px-4 py-3 font-medium">Codigo</th>
                                        <th className="px-4 py-3 font-medium">Referencia</th>
                                        <th className="px-4 py-3 text-right font-medium">Pagos</th>
                                        <th className="px-4 py-3 font-medium">Estado</th>
                                        <th className="px-4 py-3 text-right font-medium">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {methodCatalog.data.map((method) => (
                                        <tr key={method.id}>
                                            <td className="px-4 py-3 font-medium">{method.name}</td>
                                            <td className="px-4 py-3">{method.code}</td>
                                            <td className="px-4 py-3">{method.requires_reference ? 'Obligatoria' : 'Opcional'}</td>
                                            <td className="px-4 py-3 text-right">{method.payments_count}</td>
                                            <td className="px-4 py-3">{method.is_active ? 'Activo' : 'Inactivo'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-3">
                                                    <IconButton icon="edit" label="Editar" onClick={() => startMethodEdit(method)} />
                                                    <IconButton
                                                        icon="power"
                                                        label="Desactivar"
                                                        tone="danger"
                                                        onClick={async () => {
                                                            if (await confirmAction({ title: 'Desactivar metodo de pago', text: 'El metodo dejara de estar disponible para nuevos pagos.', confirmButtonText: 'Desactivar' })) {
                                                                router.delete(route('payments.methods.destroy', method.id), { preserveScroll: true });
                                                            }
                                                        }}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="px-4 py-3">
                            <Pagination links={methodCatalog.links} />
                        </div>
                    </Panel>
                ) : null}

                <Panel title="Pagos registrados">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Documento</th>
                                    <th className="px-4 py-3 font-medium">Cliente</th>
                                    <th className="px-4 py-3 font-medium">Metodo</th>
                                    <th className="px-4 py-3 font-medium">Referencia</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.data.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(payment.paid_at)}</td>
                                        <td className="px-4 py-3">{payment.sale?.receipt_number ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.sale?.customer_name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.method?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.reference ?? '-'}</td>
                                        <td className="px-4 py-3 text-right">Bs {moneyFormatter.format(Number(payment.amount_bob ?? 0))}</td>
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
        title: 'Anular pago',
        text: `Motivo para anular el pago ${payment.id}`,
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('payments.void', payment.id), { reason }, { preserveScroll: true });
}
