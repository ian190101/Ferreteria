import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

export default function Index({ payments, workers = [], branches = [], paymentMethods = [] }) {
    const canManage = usePage().props.auth.permissions.includes('payroll.manage');
    const form = useForm({
        worker_id: workers[0]?.id ?? '',
        branch_id: workers[0]?.branch_id ?? branches[0]?.id ?? '',
        payment_method_id: paymentMethods[0]?.id ?? '',
        period_from: '',
        period_to: '',
        amount: workers[0]?.salary_amount ?? '',
        reference: '',
        notes: '',
    });

    const selectWorker = (workerId) => {
        const worker = workers.find((item) => Number(item.id) === Number(workerId));
        form.setData({
            ...form.data,
            worker_id: workerId,
            branch_id: worker?.branch_id ?? form.data.branch_id,
            amount: worker?.salary_amount ?? form.data.amount,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Sueldos</h2>}>
            <Head title="Pago de sueldos" />
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Pago de sueldos" description="Registra pagos al personal. Cada pago se enlaza automaticamente con gastos para reflejarlo en reportes financieros." />

                {canManage ? (
                    <form onSubmit={(event) => { event.preventDefault(); form.post(route('human-resources.payroll.store'), { preserveScroll: true, onSuccess: () => form.reset('reference', 'notes') }); }} className="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-4">
                        <SelectField label="Trabajador" name="worker_id" value={form.data.worker_id} onChange={(event) => selectWorker(event.target.value)} error={form.errors.worker_id} helpTooltip="El trabajador puede estar vinculado a un usuario o ser solo personal registrado para planilla." required>
                            {workers.map((worker) => <option key={worker.id} value={worker.id}>{worker.name} - {worker.position ?? 'Sin cargo'}</option>)}
                        </SelectField>
                        <SelectField label="Sucursal" name="branch_id" value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)} error={form.errors.branch_id} required>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Metodo de pago" name="payment_method_id" value={form.data.payment_method_id} onChange={(event) => form.setData('payment_method_id', event.target.value)} error={form.errors.payment_method_id}>
                            <option value="">Sin metodo</option>
                            {paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                        </SelectField>
                        <FormField label="Monto" name="amount" type="number" step="0.01" min="0.01" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} error={form.errors.amount} required />
                        <FormField label="Desde" name="period_from" type="date" value={form.data.period_from} onChange={(event) => form.setData('period_from', event.target.value)} error={form.errors.period_from} />
                        <FormField label="Hasta" name="period_to" type="date" value={form.data.period_to} onChange={(event) => form.setData('period_to', event.target.value)} error={form.errors.period_to} />
                        <FormField label="Referencia" name="reference" value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} error={form.errors.reference} />
                        <FormField label="Notas" name="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} error={form.errors.notes} />
                        <div className="md:col-span-2 xl:col-span-4"><button className="rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white" disabled={form.processing}>Registrar sueldo</button></div>
                    </form>
                ) : null}

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th className="px-4 py-3">Trabajador</th><th className="px-4 py-3">Sucursal</th><th className="px-4 py-3">Periodo</th><th className="px-4 py-3">Metodo</th><th className="px-4 py-3 text-right">Monto</th><th className="px-4 py-3">Estado</th>{canManage ? <th className="px-4 py-3 text-right">Acciones</th> : null}</tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {payments.data.map((payment) => (
                                    <tr key={payment.id}>
                                        <td className="px-4 py-3">{payment.worker?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.period_from ?? '-'} / {payment.period_to ?? '-'}</td>
                                        <td className="px-4 py-3">{payment.payment_method?.name ?? '-'}</td>
                                        <td className="px-4 py-3 text-right font-semibold">Bs {Number(payment.amount ?? 0).toFixed(2)}</td>
                                        <td className="px-4 py-3">{payment.status === 'paid' ? 'Pagado' : 'Anulado'}</td>
                                        {canManage ? (
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end">
                                                    {payment.status === 'paid' ? (
                                                        <IconButton
                                                            icon="power"
                                                            label="Anular pago"
                                                            tone="danger"
                                                            onClick={async () => {
                                                                if (await confirmAction({
                                                                    title: 'Anular pago de sueldo',
                                                                    text: 'Se anulara el gasto y el movimiento bancario relacionado si corresponde.',
                                                                    confirmButtonText: 'Anular',
                                                                })) {
                                                                    router.patch(route('human-resources.payroll.void', payment.id), { reason: 'Anulacion desde planilla' }, { preserveScroll: true });
                                                                }
                                                            }}
                                                        />
                                                    ) : null}
                                                </div>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-4 py-3"><Pagination links={payments.links} /></div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
