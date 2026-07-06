import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import { confirmAction, promptAction } from '@/Utils/alerts';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export default function Index({ expenses, summary, branches, categories, categoryCatalog, paymentMethods, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('expenses.manage');
    const [editingCategory, setEditingCategory] = useState(null);
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        expense_category_id: filters.expense_category_id ?? '',
        status: filters.status ?? 'registered',
        from: filters.from ?? '',
        to: filters.to ?? '',
        per_page: filters.per_page ?? 15,
    });
    const expenseForm = useForm({
        branch_id: branches[0]?.id ?? '',
        expense_category_id: categories[0]?.id ?? '',
        payment_method_id: paymentMethods[0]?.id ?? '',
        spent_at: currentDateTimeLocal(),
        description: '',
        amount: '',
        reference: '',
        status: 'registered',
        notes: '',
    });
    const categoryForm = useForm({
        name: '',
        code: '',
        is_active: true,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('expenses.index'), { preserveScroll: true, preserveState: true });
    };

    const submitExpense = (event) => {
        event.preventDefault();
        expenseForm.post(route('expenses.store'), {
            preserveScroll: true,
            onSuccess: () => expenseForm.reset('description', 'amount', 'reference', 'notes'),
        });
    };

    const submitCategory = (event) => {
        event.preventDefault();

        if (editingCategory) {
            categoryForm.put(route('expenses.categories.update', editingCategory.id), {
                preserveScroll: true,
                onSuccess: cancelCategoryEdit,
            });

            return;
        }

        categoryForm.post(route('expenses.categories.store'), {
            preserveScroll: true,
            onSuccess: () => categoryForm.reset(),
        });
    };

    const startCategoryEdit = (category) => {
        setEditingCategory(category);
        categoryForm.clearErrors();
        categoryForm.setData({
            name: category.name,
            code: category.code,
            is_active: Boolean(category.is_active),
        });
    };

    const cancelCategoryEdit = () => {
        setEditingCategory(null);
        categoryForm.clearErrors();
        categoryForm.setData({
            name: '',
            code: '',
            is_active: true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Gastos</h2>}>
            <Head title="Gastos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Gastos" description="Registro de egresos operativos por sucursal, categoria, metodo de pago y rango de fechas." />

                <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <MetricCard title="Total del filtro" value={`Bs ${moneyFormatter.format(Number(summary.total_amount ?? 0))}`} />
                    <MetricCard title="Registros" value={summary.count ?? 0} />
                </div>

                {canManage ? (
                    <div className="mb-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                        <Panel title="Registrar gasto">
                            <form onSubmit={submitExpense} className="grid gap-4 p-4 sm:grid-cols-2">
                                <SelectField label="Sucursal" name="branch_id" value={expenseForm.data.branch_id} onChange={(event) => expenseForm.setData('branch_id', event.target.value)} error={expenseForm.errors.branch_id} required>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <SelectField label="Categoria" name="expense_category_id" value={expenseForm.data.expense_category_id} onChange={(event) => expenseForm.setData('expense_category_id', event.target.value)} error={expenseForm.errors.expense_category_id} required>
                                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                                </SelectField>
                                <SelectField label="Metodo de pago" name="payment_method_id" value={expenseForm.data.payment_method_id} onChange={(event) => expenseForm.setData('payment_method_id', event.target.value)} error={expenseForm.errors.payment_method_id}>
                                    <option value="">Sin metodo</option>
                                    {paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                </SelectField>
                                <FormField label="Fecha" name="spent_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={expenseForm.errors.spent_at} />
                                <div className="sm:col-span-2">
                                    <FormField label="Descripcion" name="description" value={expenseForm.data.description} onChange={(event) => expenseForm.setData('description', event.target.value)} error={expenseForm.errors.description} required />
                                </div>
                                <FormField label="Monto" name="amount" type="number" step="0.01" min="0.01" value={expenseForm.data.amount} onChange={(event) => expenseForm.setData('amount', event.target.value)} error={expenseForm.errors.amount} required />
                                <FormField label="Referencia" name="reference" value={expenseForm.data.reference} onChange={(event) => expenseForm.setData('reference', event.target.value)} error={expenseForm.errors.reference} />
                                <div className="sm:col-span-2">
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">
                                        Notas
                                        <textarea id="notes" rows="3" value={expenseForm.data.notes} onChange={(event) => expenseForm.setData('notes', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                                    </label>
                                </div>
                                <div className="sm:col-span-2">
                                    <button disabled={expenseForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                        Guardar gasto
                                    </button>
                                </div>
                            </form>
                        </Panel>

                        <Panel title={editingCategory ? 'Editar categoria' : 'Nueva categoria'}>
                            <form onSubmit={submitCategory} className="grid gap-4 p-4">
                                <FormField label="Nombre" name="name" value={categoryForm.data.name} onChange={(event) => categoryForm.setData('name', event.target.value)} error={categoryForm.errors.name} required />
                                <FormField label="Codigo" name="code" value={categoryForm.data.code} onChange={(event) => categoryForm.setData('code', event.target.value)} error={categoryForm.errors.code} required />
                                <SelectField label="Estado" name="category_is_active" value={categoryForm.data.is_active ? '1' : '0'} onChange={(event) => categoryForm.setData('is_active', event.target.value === '1')} error={categoryForm.errors.is_active}>
                                    <option value="1">Activo</option>
                                    <option value="0">Inactivo</option>
                                </SelectField>
                                <button disabled={categoryForm.processing} className="rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary" type="submit">
                                    {editingCategory ? 'Actualizar categoria' : 'Agregar categoria'}
                                </button>
                                {editingCategory ? (
                                    <button type="button" onClick={cancelCategoryEdit} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                                        Cancelar
                                    </button>
                                ) : null}
                            </form>
                        </Panel>
                    </div>
                ) : null}

                {canManage ? (
                    <Panel title="Categorias de gasto">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Nombre</th>
                                        <th className="px-4 py-3 font-medium">Codigo</th>
                                        <th className="px-4 py-3 text-right font-medium">Gastos</th>
                                        <th className="px-4 py-3 font-medium">Estado</th>
                                        <th className="px-4 py-3 text-right font-medium">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {categoryCatalog.data.map((category) => (
                                        <tr key={category.id}>
                                            <td className="px-4 py-3 font-medium">{category.name}</td>
                                            <td className="px-4 py-3">{category.code}</td>
                                            <td className="px-4 py-3 text-right">{category.expenses_count}</td>
                                            <td className="px-4 py-3">{category.is_active ? 'Activo' : 'Inactivo'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex justify-end gap-3">
                                                    <IconButton icon="edit" label="Editar" onClick={() => startCategoryEdit(category)} />
                                                    <IconButton
                                                        icon="power"
                                                        label="Desactivar"
                                                        tone="danger"
                                                        onClick={async () => {
                                                            if (await confirmAction({ title: 'Desactivar categoria', text: 'La categoria dejara de estar disponible para nuevos gastos.', confirmButtonText: 'Desactivar' })) {
                                                                router.delete(route('expenses.categories.destroy', category.id), { preserveScroll: true });
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
                            <Pagination links={categoryCatalog.links} />
                        </div>
                    </Panel>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-7">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Categoria" name="expense_category_id" value={filterForm.data.expense_category_id} onChange={(event) => filterForm.setData('expense_category_id', event.target.value)}>
                        <option value="">Todas</option>
                        {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="registered">Registrado</option>
                        <option value="void">Anulado</option>
                        <option value="">Todos</option>
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('expenses.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <Panel title="Gastos registrados">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Fecha</th>
                                    <th className="px-4 py-3 font-medium">Descripcion</th>
                                    <th className="px-4 py-3 font-medium">Categoria</th>
                                    <th className="px-4 py-3 font-medium">Sucursal</th>
                                    <th className="px-4 py-3 font-medium">Metodo</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                    <th className="px-4 py-3 text-right font-medium">Monto</th>
                                    {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {expenses.data.map((expense) => (
                                    <tr key={expense.id}>
                                        <td className="whitespace-nowrap px-4 py-3">{formatDate(expense.spent_at)}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{expense.description}</p>
                                            <p className="text-xs text-slate-500">{expense.reference ?? '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">{expense.category?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{expense.branch?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{expense.payment_method?.name ?? '-'}</td>
                                        <td className="px-4 py-3">{expense.status === 'registered' ? 'Registrado' : 'Anulado'}</td>
                                        <td className="px-4 py-3 text-right">Bs {moneyFormatter.format(Number(expense.amount ?? 0))}</td>
                                        {canManage ? (
                                            <td className="px-4 py-3 text-right">
                                                {expense.status === 'registered' ? (
                                                    <IconButton icon="close" label="Anular" tone="danger" onClick={() => voidExpense(expense)} />
                                                ) : (
                                                    <span className="text-slate-400">Anulado</span>
                                                )}
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <div className="mt-6">
                    <Pagination links={expenses.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function MetricCard({ title, value }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{title}</p>
            <p className="mt-3 text-2xl font-semibold text-slate-900 dark:text-slate-100">{value}</p>
        </article>
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

async function voidExpense(expense) {
    const reason = await promptAction({
        title: 'Anular gasto',
        text: `Motivo para anular "${expense.description}"`,
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('expenses.void', expense.id), { reason }, { preserveScroll: true });
}
