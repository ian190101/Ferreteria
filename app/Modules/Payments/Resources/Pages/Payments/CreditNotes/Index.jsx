import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
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

export default function Index({ creditNotes, branches, receivables, returns, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('credit-notes.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        sale_id: filters.sale_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const creditForm = useForm({
        sale_id: receivables[0]?.id ?? '',
        sale_return_id: '',
        credit_number: `NC-${new Date().getFullYear()}-${String(Date.now()).slice(-6)}`,
        issued_at: currentDateTimeLocal(),
        amount: receivables[0]?.balance_due ?? '',
        reason: '',
        notes: '',
    });

    const selectedSale = receivables.find((sale) => String(sale.id) === String(creditForm.data.sale_id));
    const availableReturns = returns.filter((saleReturn) => String(saleReturn.sale_id) === String(creditForm.data.sale_id));
    const selectedReturn = availableReturns.find((saleReturn) => String(saleReturn.id) === String(creditForm.data.sale_return_id));

    const selectSale = (value) => {
        const sale = receivables.find((item) => String(item.id) === String(value));

        creditForm.setData({
            ...creditForm.data,
            sale_id: value,
            sale_return_id: '',
            amount: sale?.balance_due ?? '',
        });
    };

    const selectReturn = (value) => {
        const saleReturn = returns.find((item) => String(item.id) === String(value));
        const maxAmount = Math.min(Number(selectedSale?.balance_due ?? 0), Number(saleReturn?.available_amount ?? 0));

        creditForm.setData({
            ...creditForm.data,
            sale_return_id: value,
            amount: value ? maxAmount.toFixed(2) : selectedSale?.balance_due ?? '',
        });
    };

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('payments.credit-notes.index'), { preserveScroll: true, preserveState: true });
    };

    const submitCredit = (event) => {
        event.preventDefault();
        creditForm.post(route('payments.credit-notes.store'), {
            preserveScroll: true,
            onSuccess: () => creditForm.reset('sale_return_id', 'reason', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Pagos</h2>}>
            <Head title="Notas de credito" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Notas de credito" description="Ajustes comerciales que reducen cuentas por cobrar sin registrarse como pagos reales de caja o banco." />

                <div className="mb-6 grid gap-6 xl:grid-cols-[1fr_1fr]">
                    <Panel title="Cuentas con saldo">
                        <div className="divide-y divide-slate-100 dark:divide-slate-800">
                            {receivables.length === 0 ? (
                                <p className="px-4 py-5 text-sm text-slate-500">No hay notas con saldo pendiente.</p>
                            ) : receivables.map((sale) => (
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
                        <Panel title="Emitir nota de credito">
                            <form onSubmit={submitCredit} className="grid gap-4 p-4">
                                <SelectField label="Nota de venta" name="sale_id" value={creditForm.data.sale_id} onChange={(event) => selectSale(event.target.value)} error={creditForm.errors.sale_id} required>
                                    <option value="">Seleccionar</option>
                                    {receivables.map((sale) => (
                                        <option key={sale.id} value={sale.id}>
                                            {sale.receipt_number} - {sale.customer_name ?? 'Sin cliente'} - Saldo {sale.balance_due}
                                        </option>
                                    ))}
                                </SelectField>
                                <SelectField label="Devolucion vinculada" name="sale_return_id" value={creditForm.data.sale_return_id} onChange={(event) => selectReturn(event.target.value)} error={creditForm.errors.sale_return_id}>
                                    <option value="">Sin devolucion</option>
                                    {availableReturns.map((saleReturn) => (
                                        <option key={saleReturn.id} value={saleReturn.id}>
                                            {saleReturn.return_number} - Disponible Bs {moneyFormatter.format(Number(saleReturn.available_amount))}
                                        </option>
                                    ))}
                                </SelectField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Numero" name="credit_number" value={creditForm.data.credit_number} onChange={(event) => creditForm.setData('credit_number', event.target.value)} error={creditForm.errors.credit_number} required />
                                    <FormField label="Fecha" name="issued_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={creditForm.errors.issued_at} />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField label="Monto" name="amount" type="number" step="0.01" min="0.01" max={selectedReturn?.available_amount ?? selectedSale?.balance_due ?? undefined} value={creditForm.data.amount} onChange={(event) => creditForm.setData('amount', event.target.value)} error={creditForm.errors.amount} required />
                                    <FormField label="Motivo" name="reason" value={creditForm.data.reason} onChange={(event) => creditForm.setData('reason', event.target.value)} error={creditForm.errors.reason} required />
                                </div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                    Notas
                                    <textarea id="notes" rows="3" value={creditForm.data.notes} onChange={(event) => creditForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                                <button disabled={creditForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Guardar nota de credito
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
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('payments.credit-notes.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Notas emitidas">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Nota credito</th>
                                    <th className="px-4 py-3 font-medium">Venta</th>
                                    <th className="px-4 py-3 font-medium">Devolucion</th>
                                    <th className="px-4 py-3 font-medium">Motivo</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    <th className="px-4 py-3 font-medium">Usuario</th>
                                    {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {creditNotes.data.map((creditNote) => (
                                    <tr key={creditNote.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(creditNote.issued_at)}</td>
                                        <td className="px-4 py-3 font-medium">{creditNote.credit_number}</td>
                                        <td className="px-4 py-3">
                                            <p>{creditNote.sale?.receipt_number ?? '-'}</p>
                                            <p className="text-xs text-slate-500">{creditNote.sale?.customer_name ?? '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">{creditNote.sale_return?.return_number ?? '-'}</td>
                                        <td className="px-4 py-3">{creditNote.reason}</td>
                                        <td className="px-4 py-3 text-right">Bs {moneyFormatter.format(Number(creditNote.amount_bob ?? 0))}</td>
                                        <td className="px-4 py-3">{creditNote.user?.name ?? '-'}</td>
                                        {canManage ? (
                                            <td className="px-4 py-3 text-right">
                                                <button type="button" className="text-red-600 hover:underline" onClick={() => voidCreditNote(creditNote)}>
                                                    Anular
                                                </button>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                                {creditNotes.data.length === 0 ? (
                                    <tr>
                                        <td className="px-4 py-6 text-center text-slate-500" colSpan={canManage ? 8 : 7}>
                                            No hay notas de credito registradas.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={creditNotes.links} />
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

async function voidCreditNote(creditNote) {
    const reason = await promptAction({
        title: 'Anular nota de credito',
        text: `Motivo para anular ${creditNote.credit_number}`,
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('payments.credit-notes.void', creditNote.id), { reason }, { preserveScroll: true });
}
