import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const CASH_DENOMINATIONS = [
    { key: 'bill_200', type: 'bill', label: 'Billete de 200 Bs', shortLabel: '200', value: 200, gradient: 'from-emerald-500 to-teal-700' },
    { key: 'bill_100', type: 'bill', label: 'Billete de 100 Bs', shortLabel: '100', value: 100, gradient: 'from-red-500 to-rose-700' },
    { key: 'bill_50', type: 'bill', label: 'Billete de 50 Bs', shortLabel: '50', value: 50, gradient: 'from-violet-500 to-indigo-700' },
    { key: 'bill_20', type: 'bill', label: 'Billete de 20 Bs', shortLabel: '20', value: 20, gradient: 'from-orange-400 to-amber-700' },
    { key: 'bill_10', type: 'bill', label: 'Billete de 10 Bs', shortLabel: '10', value: 10, gradient: 'from-sky-500 to-blue-700' },
    { key: 'coin_5', type: 'coin', label: 'Moneda de 5 Bs', shortLabel: '5', value: 5, gradient: 'from-slate-300 to-slate-500' },
    { key: 'coin_2', type: 'coin', label: 'Moneda de 2 Bs', shortLabel: '2', value: 2, gradient: 'from-zinc-300 to-zinc-500' },
    { key: 'coin_1', type: 'coin', label: 'Moneda de 1 Bs', shortLabel: '1', value: 1, gradient: 'from-stone-300 to-stone-500' },
    { key: 'coin_050', type: 'coin', label: 'Moneda de 0.50 ctvs', shortLabel: '0.50', value: 0.5, gradient: 'from-amber-200 to-yellow-500' },
    { key: 'coin_020', type: 'coin', label: 'Moneda de 20 ctvs', shortLabel: '0.20', value: 0.2, gradient: 'from-neutral-300 to-neutral-500' },
    { key: 'coin_010', type: 'coin', label: 'Moneda de 10 ctvs', shortLabel: '0.10', value: 0.1, gradient: 'from-gray-300 to-gray-500' },
];

const emptyCashCount = () => CASH_DENOMINATIONS.reduce((counts, denomination) => ({
    ...counts,
    [denomination.key]: 0,
}), {});

export default function Index({ sessions, openSessions, branches, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('cash.manage');
    const canViewBanks = permissions.includes('banks.view') || permissions.includes('banks.manage');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        status: filters.status ?? '',
        per_page: filters.per_page ?? 15,
    });
    const openForm = useForm({
        branch_id: branches[0]?.id ?? '',
        opened_at: currentDateTimeLocal(),
        opening_amount: '0',
        opening_notes: '',
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('cash.index'), { preserveScroll: true, preserveState: true });
    };

    const submitOpen = (event) => {
        event.preventDefault();
        openForm.post(route('cash.open'), {
            preserveScroll: true,
            onSuccess: () => openForm.reset('opening_amount', 'opening_notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Caja</h2>}>
            <Head title="Caja" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Caja" description="Apertura y cierre de caja por sucursal con calculo automatico de efectivo esperado." />

                {canManage ? (
                    <div className="mb-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
                        <Panel title="Abrir caja">
                            <form onSubmit={submitOpen} className="grid gap-4 p-4">
                                <SelectField label="Sucursal" name="branch_id" value={openForm.data.branch_id} onChange={(event) => openForm.setData('branch_id', event.target.value)} error={openForm.errors.branch_id} required>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <FormField label="Fecha de apertura" name="opened_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" />
                                <FormField label="Monto inicial" name="opening_amount" type="number" step="0.01" min="0" value={openForm.data.opening_amount} onChange={(event) => openForm.setData('opening_amount', event.target.value)} error={openForm.errors.opening_amount} required />
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="opening_notes">
                                    Notas
                                    <textarea id="opening_notes" rows="3" value={openForm.data.opening_notes} onChange={(event) => openForm.setData('opening_notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                </label>
                                <button disabled={openForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                    Abrir caja
                                </button>
                            </form>
                        </Panel>

                        <Panel title="Cajas abiertas">
                            <div className="divide-y divide-slate-100 dark:divide-slate-800">
                                {openSessions.length === 0 ? (
                                    <p className="px-4 py-5 text-sm text-slate-500">No hay cajas abiertas.</p>
                                ) : openSessions.map((session) => (
                                    <CloseSessionForm key={session.id} session={session} canViewBanks={canViewBanks} />
                                ))}
                            </div>
                        </Panel>
                    </div>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="open">Abierta</option>
                        <option value="closed">Cerrada</option>
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('cash.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Historial de cajas">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 font-medium">Apertura</th>
                                    <th className="px-4 py-3 font-medium">Cierre</th>
                                    <th className="px-4 py-3 text-right font-medium">Inicial</th>
                                    <th className="px-4 py-3 text-right font-medium">Ingresos</th>
                                    <th className="px-4 py-3 text-right font-medium">Gastos</th>
                                    <th className="px-4 py-3 text-right font-medium">Esperado</th>
                                    <th className="px-4 py-3 text-right font-medium">Contado</th>
                                    <th className="px-4 py-3 text-right font-medium">Diferencia</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {sessions.data.map((session) => (
                                    <tr key={session.id}>
                                        <td className="px-4 py-3">{session.branch?.name ?? '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(session.opened_at)}</td>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(session.closed_at)}</td>
                                        <MoneyCell value={session.opening_amount} />
                                        <MoneyCell value={session.cash_income_amount} />
                                        <MoneyCell value={session.cash_expense_amount} />
                                        <MoneyCell value={session.expected_cash_amount} />
                                        <MoneyCell value={session.counted_cash_amount} />
                                        <MoneyCell value={session.difference_amount} />
                                        <td className="px-4 py-3">{session.status === 'open' ? 'Abierta' : 'Cerrada'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={sessions.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function CloseSessionForm({ session, canViewBanks }) {
    const closeForm = useForm({
        closed_at: currentDateTimeLocal(),
        cash_count: emptyCashCount(),
        closing_notes: '',
    });
    const countedTotal = CASH_DENOMINATIONS.reduce((total, denomination) => (
        total + Number(closeForm.data.cash_count[denomination.key] ?? 0) * denomination.value
    ), 0);
    const expected = Number(session.current_expected_cash_amount ?? session.expected_cash_amount ?? session.opening_amount ?? 0);
    const difference = countedTotal - expected;
    const bankIncome = Number(session.current_bank_income_amount ?? 0);
    const bankExpense = Number(session.current_bank_expense_amount ?? 0);
    const hasBankMovements = bankIncome > 0 || bankExpense > 0;

    const updateCount = (key, value) => {
        const count = Math.max(Number.parseInt(value || '0', 10) || 0, 0);

        closeForm.setData('cash_count', {
            ...closeForm.data.cash_count,
            [key]: count,
        });
    };

    const closeSession = (event) => {
        event.preventDefault();
        closeForm.put(route('cash.close', session.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={closeSession} className="grid gap-5 px-4 py-5">
            <div className="grid gap-4 xl:grid-cols-[1fr_220px] xl:items-start">
                <div>
                    <p className="font-semibold text-slate-900 dark:text-slate-100">{session.branch?.name ?? '-'}</p>
                    <p className="text-sm text-slate-500">Apertura: {formatDate(session.opened_at)} - Inicial Bs {moneyFormatter.format(Number(session.opening_amount ?? 0))}</p>
                    <p className="text-sm text-slate-500">Ingresos efectivo Bs {moneyFormatter.format(Number(session.current_cash_income_amount ?? 0))} - Gastos efectivo Bs {moneyFormatter.format(Number(session.current_cash_expense_amount ?? 0))}</p>
                    <p className="text-xs text-slate-500">Responsable: {session.opener?.name ?? '-'}</p>
                </div>
                <FormField label="Fecha cierre" name="closed_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={closeForm.errors.closed_at} />
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {CASH_DENOMINATIONS.map((denomination) => (
                    <DenominationCounter
                        key={denomination.key}
                        denomination={denomination}
                        count={closeForm.data.cash_count[denomination.key] ?? 0}
                        error={closeForm.errors[`cash_count.${denomination.key}`]}
                        onChange={(value) => updateCount(denomination.key, value)}
                    />
                ))}
            </div>

            <div className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/50 lg:grid-cols-3">
                <CashMetric label="Esperado en caja" value={expected} />
                <CashMetric label="Efectivo contado" value={countedTotal} strong />
                <CashMetric label="Diferencia" value={difference} tone={difference < 0 ? 'danger' : difference > 0 ? 'warning' : 'success'} />
            </div>

            {hasBankMovements ? (
                <div className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p className="font-semibold">QR / Banco detectado en esta caja</p>
                            <p className="mt-1">Ingresos Bs {moneyFormatter.format(bankIncome)} - Egresos Bs {moneyFormatter.format(bankExpense)}. Se incluyen en reportes, pero no se suman al efectivo fisico.</p>
                        </div>
                        {canViewBanks ? (
                            <button
                                type="button"
                                onClick={() => router.get(route('banks.index'), { branch_id: session.branch_id, status: 'registered' })}
                                className="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white"
                            >
                                Conciliar con QR/Banco
                            </button>
                        ) : null}
                    </div>
                </div>
            ) : null}

            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor={`closing_notes_${session.id}`}>
                Notas de cierre
                <textarea id={`closing_notes_${session.id}`} rows="3" value={closeForm.data.closing_notes} onChange={(event) => closeForm.setData('closing_notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                {closeForm.errors.closing_notes ? <span className="mt-1 block text-sm text-red-600">{closeForm.errors.closing_notes}</span> : null}
            </label>

            <div className="flex justify-end">
                <button disabled={closeForm.processing} className="rounded-md border border-brand-primary px-5 py-2.5 text-sm font-semibold text-brand-primary transition hover:bg-brand-primary hover:text-white disabled:cursor-not-allowed disabled:opacity-60" type="submit">
                    Cerrar caja
                </button>
            </div>
        </form>
    );
}

function DenominationCounter({ denomination, count, error, onChange }) {
    const subtotal = Number(count ?? 0) * denomination.value;

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-3">
                <MoneyVisual denomination={denomination} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{denomination.label}</p>
                    <p className="text-xs text-slate-500">Subtotal Bs {moneyFormatter.format(subtotal)}</p>
                </div>
            </div>
            <label className="mt-3 block text-xs font-semibold uppercase tracking-wide text-slate-500" htmlFor={denomination.key}>
                Cantidad
                <input
                    id={denomination.key}
                    type="number"
                    min="0"
                    step="1"
                    inputMode="numeric"
                    value={count}
                    onChange={(event) => onChange(event.target.value)}
                    className="mt-1 block h-11 w-full rounded-xl border-slate-300 bg-slate-50 text-right text-base font-semibold text-slate-950 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                />
            </label>
            {error ? <p className="mt-1 text-xs text-red-600">{error}</p> : null}
        </div>
    );
}

function MoneyVisual({ denomination }) {
    if (denomination.type === 'coin') {
        return (
            <div className={`flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-gradient-to-br ${denomination.gradient} p-1 shadow-inner ring-1 ring-black/10`}>
                <div className="flex h-full w-full items-center justify-center rounded-full border border-white/50 bg-white/20 text-center text-sm font-black text-white shadow-inner">
                    {denomination.shortLabel}
                </div>
            </div>
        );
    }

    return (
        <div className={`relative h-14 w-24 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br ${denomination.gradient} p-2 text-white shadow-sm ring-1 ring-black/10`}>
            <div className="absolute inset-x-2 top-2 h-2 rounded-full bg-white/25" />
            <div className="absolute bottom-2 left-2 right-2 flex items-end justify-between">
                <span className="text-[10px] font-semibold uppercase tracking-wide">Bs</span>
                <span className="text-2xl font-black leading-none">{denomination.shortLabel}</span>
            </div>
            <div className="absolute left-3 top-5 h-5 w-5 rounded-full border border-white/40" />
        </div>
    );
}

function CashMetric({ label, value, tone = 'neutral', strong = false }) {
    const toneClass = {
        neutral: 'text-slate-900 dark:text-slate-100',
        success: 'text-emerald-600 dark:text-emerald-300',
        warning: 'text-amber-600 dark:text-amber-300',
        danger: 'text-red-600 dark:text-red-300',
    }[tone];

    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`${toneClass} ${strong ? 'text-2xl' : 'text-xl'} mt-1 font-bold`}>Bs {moneyFormatter.format(Number(value ?? 0))}</p>
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

function MoneyCell({ value }) {
    return <td className="px-4 py-3 text-right">Bs {moneyFormatter.format(Number(value ?? 0))}</td>;
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
