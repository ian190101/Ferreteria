import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import { promptAction } from '@/Utils/alerts';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

export default function Index({ accounts, transactions, summary, branches, activeAccounts, cashSessions, users, filters }) {
    const canManage = usePage().props.auth.permissions.includes('banks.manage');
    const decimalFormat = useDecimalFormatter('finance');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        bank_account_id: filters.bank_account_id ?? '',
        cash_session_id: filters.cash_session_id ?? '',
        user_id: filters.user_id ?? '',
        status: filters.status ?? '',
        type: filters.type ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });
    const accountForm = useForm({
        branch_id: branches[0]?.id ?? '',
        name: '',
        bank_name: '',
        account_number: '',
        currency_code: 'BOB',
        opening_balance: '0',
        is_active: true,
    });
    const transactionForm = useForm({
        bank_account_id: activeAccounts[0]?.id ?? '',
        type: 'deposit',
        transacted_at: currentDateTimeLocal(),
        amount: '',
        reference: '',
        description: '',
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('banks.index'), { preserveScroll: true, preserveState: true });
    };

    const submitAccount = (event) => {
        event.preventDefault();
        accountForm.post(route('banks.accounts.store'), {
            preserveScroll: true,
            onSuccess: () => accountForm.reset('name', 'bank_name', 'account_number', 'opening_balance'),
        });
    };

    const submitTransaction = (event) => {
        event.preventDefault();
        transactionForm.post(route('banks.transactions.store'), {
            preserveScroll: true,
            onSuccess: () => transactionForm.reset('amount', 'reference', 'description'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Bancos</h2>}>
            <Head title="Bancos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Bancos" description="Cuentas bancarias, movimientos, saldo operativo y conciliacion por sucursal." />

                <div className="my-6 grid gap-4 sm:grid-cols-3">
                    <Metric label="Cuentas" value={summary.accounts_count} />
                    <Metric label="Saldo total" value={`Bs ${decimalFormat.money(summary.total_balance ?? 0)}`} />
                    <Metric label="Pendientes de conciliar" value={summary.pending_reconciliation} />
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Cuenta" name="bank_account_id" value={filterForm.data.bank_account_id} onChange={(event) => filterForm.setData('bank_account_id', event.target.value)}>
                        <option value="">Todas</option>
                        {activeAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}
                    </SelectField>
                    <SelectField label="Caja" name="cash_session_id" value={filterForm.data.cash_session_id} onChange={(event) => filterForm.setData('cash_session_id', event.target.value)}>
                        <option value="">Todas</option>
                        {cashSessions.map((session) => (
                            <option key={session.id} value={session.id}>
                                #{session.id} - {session.branch?.name ?? '-'} - {session.opener?.name ?? '-'}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField label="Usuario" name="user_id" value={filterForm.data.user_id} onChange={(event) => filterForm.setData('user_id', event.target.value)}>
                        <option value="">Todos</option>
                        {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                    </SelectField>
                    <SelectField label="Tipo" name="type" value={filterForm.data.type} onChange={(event) => filterForm.setData('type', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="deposit">Ingreso</option>
                        <option value="withdrawal">Egreso</option>
                        <option value="adjustment">Ajuste</option>
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="registered">Registrado</option>
                        <option value="void">Anulado</option>
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">Filtrar</button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('banks.index'))}>Limpiar</button>
                    </div>
                </form>

                {canManage ? (
                    <div className="mb-6 grid gap-6 lg:grid-cols-2">
                        <form onSubmit={submitAccount} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-white">Nueva cuenta</h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SelectField label="Sucursal" name="branch_id" value={accountForm.data.branch_id} onChange={(event) => accountForm.setData('branch_id', event.target.value)} error={accountForm.errors.branch_id}>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <FormField label="Alias" name="name" value={accountForm.data.name} onChange={(event) => accountForm.setData('name', event.target.value)} error={accountForm.errors.name} />
                                <FormField label="Banco" name="bank_name" value={accountForm.data.bank_name} onChange={(event) => accountForm.setData('bank_name', event.target.value)} error={accountForm.errors.bank_name} />
                                <FormField label="Nro. cuenta" name="account_number" value={accountForm.data.account_number} onChange={(event) => accountForm.setData('account_number', event.target.value)} error={accountForm.errors.account_number} />
                                <FormField label="Moneda" name="currency_code" value={accountForm.data.currency_code} onChange={(event) => accountForm.setData('currency_code', event.target.value.toUpperCase())} error={accountForm.errors.currency_code} />
                                <FormField label="Saldo inicial" name="opening_balance" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} value={accountForm.data.opening_balance} onChange={(event) => accountForm.setData('opening_balance', event.target.value)} error={accountForm.errors.opening_balance} />
                            </div>
                            <div className="mt-4">
                                <PrimaryButton disabled={accountForm.processing}>Crear cuenta</PrimaryButton>
                            </div>
                        </form>

                        <form onSubmit={submitTransaction} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-white">Nuevo movimiento</h3>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SelectField label="Cuenta" name="bank_account_id" value={transactionForm.data.bank_account_id} onChange={(event) => transactionForm.setData('bank_account_id', event.target.value)} error={transactionForm.errors.bank_account_id}>
                                    {activeAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.account_number})</option>)}
                                </SelectField>
                                <SelectField label="Tipo" name="type" value={transactionForm.data.type} onChange={(event) => transactionForm.setData('type', event.target.value)} error={transactionForm.errors.type}>
                                    <option value="deposit">Ingreso</option>
                                    <option value="withdrawal">Egreso</option>
                                    <option value="adjustment">Ajuste positivo</option>
                                </SelectField>
                                <FormField label="Fecha" name="transacted_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={transactionForm.errors.transacted_at} />
                                <FormField label="Monto" name="amount" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} value={transactionForm.data.amount} onChange={(event) => transactionForm.setData('amount', event.target.value)} error={transactionForm.errors.amount} />
                                <FormField label="Referencia" name="reference" value={transactionForm.data.reference} onChange={(event) => transactionForm.setData('reference', event.target.value)} error={transactionForm.errors.reference} />
                                <FormField label="Descripcion" name="description" value={transactionForm.data.description} onChange={(event) => transactionForm.setData('description', event.target.value)} error={transactionForm.errors.description} />
                            </div>
                            <div className="mt-4">
                                <PrimaryButton disabled={transactionForm.processing || activeAccounts.length === 0}>Registrar movimiento</PrimaryButton>
                            </div>
                        </form>
                    </div>
                ) : null}

                <div className="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Cuenta</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 text-right font-medium">Saldo</th>
                                <th className="px-4 py-3 text-right font-medium">Movs.</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {accounts.data.map((account) => (
                                <tr key={account.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-900 dark:text-slate-100">{account.name}</p>
                                        <p className="text-xs text-slate-500">{account.bank_name} · {account.account_number}</p>
                                    </td>
                                    <td className="px-4 py-3">{account.branch?.name ?? '-'}</td>
                                    <td className="px-4 py-3 text-right">{account.currency_code} {decimalFormat.money(account.current_balance ?? 0)}</td>
                                    <td className="px-4 py-3 text-right">{account.transactions_count}</td>
                                    <td className="px-4 py-3">{account.is_active ? 'Activa' : 'Inactiva'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3 text-right">
                                            <IconButton icon="power" label={account.is_active ? 'Desactivar' : 'Activar'} tone={account.is_active ? 'danger' : 'success'} onClick={() => toggleAccount(account)} />
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="border-t border-slate-200 p-4 dark:border-slate-800">
                        <Pagination links={accounts.links} />
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Cuenta</th>
                                <th className="px-4 py-3 font-medium">Usuario / Caja</th>
                                <th className="px-4 py-3 font-medium">Tipo</th>
                                <th className="px-4 py-3 text-right font-medium">Monto</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Referencia</th>
                                {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {transactions.data.length === 0 ? (
                                <tr>
                                    <td colSpan={canManage ? 8 : 7} className="px-4 py-6 text-center text-sm text-slate-500">Sin movimientos bancarios.</td>
                                </tr>
                            ) : transactions.data.map((transaction) => (
                                <tr key={transaction.id}>
                                    <td className="px-4 py-3">{formatDate(transaction.transacted_at)}</td>
                                    <td className="px-4 py-3">{transaction.account?.name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-slate-800 dark:text-slate-100">{transaction.user?.name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{transaction.cash_session_id ? `Caja #${transaction.cash_session_id}` : 'Sin caja vinculada'}</p>
                                    </td>
                                    <td className="px-4 py-3">{typeLabel(transaction.type)}</td>
                                    <td className="px-4 py-3 text-right">{transaction.account?.currency_code ?? 'BOB'} {decimalFormat.money(transaction.amount ?? 0)}</td>
                                    <td className="px-4 py-3">{transaction.status === 'void' ? 'Anulado' : (transaction.reconciled_at ? 'Conciliado' : 'Pendiente')}</td>
                                    <td className="px-4 py-3">{transaction.reference ?? '-'}</td>
                                    {canManage ? (
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-3">
                                                {transaction.status === 'registered' && !transaction.reconciled_at ? (
                                                    <IconButton icon="check" label="Conciliar" tone="success" onClick={() => router.patch(route('banks.transactions.reconcile', transaction.id), {}, { preserveScroll: true })} />
                                                ) : null}
                                                {transaction.status === 'registered' ? (
                                                    <IconButton icon="close" label="Anular" tone="danger" onClick={() => voidTransaction(transaction)} />
                                                ) : null}
                                            </div>
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="border-t border-slate-200 p-4 dark:border-slate-800">
                        <Pagination links={transactions.links} />
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Metric({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm text-slate-500 dark:text-slate-400">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{value}</p>
        </div>
    );
}

function toggleAccount(account) {
    router.put(route('banks.accounts.update', account.id), {
        branch_id: account.branch_id,
        name: account.name,
        bank_name: account.bank_name,
        account_number: account.account_number,
        currency_code: account.currency_code,
        is_active: !account.is_active,
    }, { preserveScroll: true });
}

async function voidTransaction(transaction) {
    const reason = await promptAction({
        title: 'Anular movimiento bancario',
        text: 'Motivo de anulacion',
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('banks.transactions.void', transaction.id), { reason }, { preserveScroll: true });
}

function typeLabel(type) {
    const labels = {
        deposit: 'Ingreso',
        withdrawal: 'Egreso',
        adjustment: 'Ajuste',
    };

    return labels[type] ?? type;
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString('es-BO') : '-';
}
