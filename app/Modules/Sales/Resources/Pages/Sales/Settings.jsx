import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const catalogs = {
    sale_type: {
        title: 'Tipos de venta',
        description: 'Clasificacion usada en cotizaciones y notas.',
        columns: ['name', 'is_active'],
    },
    currency: {
        title: 'Monedas',
        description: 'Monedas y cambio fijo hacia bolivianos.',
        columns: ['name', 'code', 'symbol', 'exchange_rate_to_bob', 'is_base', 'is_active'],
    },
    advance_option: {
        title: 'Anticipos',
        description: 'Anticipos preconfigurados por porcentaje o monto fijo.',
        columns: ['name', 'type', 'percentage', 'amount', 'is_active'],
    },
    document_sequence: {
        title: 'Secuencias',
        description: 'Numeracion automatica por sucursal y tipo de documento.',
        columns: ['branch', 'document_type', 'name', 'prefix', 'next_number', 'padding', 'is_active'],
    },
};

export default function Settings({ saleTypes, currencies, advanceOptions, documentSequences, branches }) {
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(emptyForm());
    const currentCatalog = catalogs[data.kind];
    const rowsByKind = useMemo(() => ({
        sale_type: saleTypes,
        currency: currencies,
        advance_option: advanceOptions,
        document_sequence: documentSequences,
    }), [saleTypes, currencies, advanceOptions, documentSequences]);

    const submit = (event) => {
        event.preventDefault();

        if (editing) {
            put(route('sales.settings.update', { kind: editing.kind, setting: editing.id }), {
                preserveScroll: true,
                onSuccess: cancelEdit,
            });

            return;
        }

        post(route('sales.settings.store'), {
            preserveScroll: true,
            onSuccess: () => reset('name', 'code', 'symbol', 'percentage', 'amount'),
        });
    };

    const changeKind = (kind) => {
        clearErrors();
        setEditing(null);
        setData({
            ...emptyForm(),
            kind,
            branch_id: kind === 'document_sequence' ? branches[0]?.id ?? '' : '',
        });
    };

    const startEdit = (kind, row) => {
        clearErrors();
        setEditing({ kind, id: row.id });
        setData({
            kind,
            name: row.name ?? '',
            code: row.code ?? '',
            symbol: row.symbol ?? '',
            exchange_rate_to_bob: row.exchange_rate_to_bob ?? '1',
            is_base: Boolean(row.is_base),
            type: row.type ?? 'percentage',
            percentage: row.percentage ?? '',
            amount: row.amount ?? '',
            branch_id: row.branch_id ?? branches[0]?.id ?? '',
            document_type: row.document_type ?? 'sale_note',
            prefix: row.prefix ?? '',
            next_number: row.next_number ?? 1,
            padding: row.padding ?? 6,
            is_active: Boolean(row.is_active),
        });
    };

    const cancelEdit = () => {
        clearErrors();
        setEditing(null);
        setData(emptyForm());
    };

    const destroy = async (kind, row) => {
        if (await confirmAction({ title: `Eliminar ${row.name}?`, text: 'Esta configuracion dejara de estar disponible.', confirmButtonText: 'Eliminar' })) {
            router.delete(route('sales.settings.destroy', { kind, setting: row.id }), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title="Catalogos de ventas" />
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Catalogos de ventas" description="Gestiona tipos de venta, monedas y anticipos usados por cotizaciones y notas." />

                <form onSubmit={submit} className="mb-8 grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-3">
                    <SelectField label="Catalogo" name="kind" value={data.kind} onChange={(event) => changeKind(event.target.value)} error={errors.kind} disabled={Boolean(editing)}>
                        <option value="sale_type">Tipo de venta</option>
                        <option value="currency">Moneda</option>
                        <option value="advance_option">Anticipo</option>
                        <option value="document_sequence">Secuencia</option>
                    </SelectField>
                    <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    {data.kind === 'currency' ? (
                        <>
                            <FormField label="Codigo" name="code" value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} error={errors.code} required />
                            <FormField label="Simbolo" name="symbol" value={data.symbol} onChange={(event) => setData('symbol', event.target.value)} error={errors.symbol} required />
                            <FormField label="Cambio a bolivianos" name="exchange_rate_to_bob" type="number" step="0.000001" min="0.000001" value={data.exchange_rate_to_bob} onChange={(event) => setData('exchange_rate_to_bob', event.target.value)} error={errors.exchange_rate_to_bob} disabled={data.is_base} required />
                            <SelectField label="Moneda base" name="is_base" value={data.is_base ? '1' : '0'} onChange={(event) => setData('is_base', event.target.value === '1')} error={errors.is_base}>
                                <option value="0">No</option>
                                <option value="1">Si</option>
                            </SelectField>
                        </>
                    ) : null}
                    {data.kind === 'advance_option' ? (
                        <>
                            <SelectField label="Tipo de anticipo" name="type" value={data.type} onChange={(event) => setData('type', event.target.value)} error={errors.type} required>
                                <option value="percentage">Porcentaje</option>
                                <option value="amount">Monto fijo</option>
                            </SelectField>
                            {data.type === 'amount' ? (
                                <FormField label="Monto" name="amount" type="number" step="0.01" min="0" value={data.amount} onChange={(event) => setData('amount', event.target.value)} error={errors.amount} required />
                            ) : (
                                <FormField label="Porcentaje" name="percentage" type="number" step="0.01" min="0" max="100" value={data.percentage} onChange={(event) => setData('percentage', event.target.value)} error={errors.percentage} required />
                            )}
                        </>
                    ) : null}
                    {data.kind === 'document_sequence' ? (
                        <>
                            <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id} required>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <SelectField label="Documento" name="document_type" value={data.document_type} onChange={(event) => setData('document_type', event.target.value)} error={errors.document_type} required>
                                <option value="sale_note">Nota de venta</option>
                                <option value="quotation">Cotizacion</option>
                            </SelectField>
                            <FormField label="Prefijo" name="prefix" value={data.prefix} onChange={(event) => setData('prefix', event.target.value.toUpperCase())} error={errors.prefix} required />
                            <FormField label="Siguiente numero" name="next_number" type="number" min="1" value={data.next_number} onChange={(event) => setData('next_number', event.target.value)} error={errors.next_number} required />
                            <FormField label="Digitos" name="padding" type="number" min="1" max="12" value={data.padding} onChange={(event) => setData('padding', event.target.value)} error={errors.padding} required />
                        </>
                    ) : null}
                    <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active} disabled={data.kind === 'currency' && data.is_base}>
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </SelectField>
                    <div className="flex items-end gap-3">
                        <PrimaryButton disabled={processing}>{editing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        {editing ? (
                            <button type="button" onClick={cancelEdit} className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                                Cancelar
                            </button>
                        ) : null}
                    </div>
                    <p className="text-sm text-slate-500 dark:text-slate-400 sm:col-span-3">{currentCatalog.description}</p>
                </form>

                <Catalog kind="sale_type" rows={rowsByKind.sale_type.data} links={rowsByKind.sale_type.links} columns={catalogs.sale_type.columns} title={catalogs.sale_type.title} onEdit={startEdit} onDelete={destroy} />
                <Catalog kind="currency" rows={rowsByKind.currency.data} links={rowsByKind.currency.links} columns={catalogs.currency.columns} title={catalogs.currency.title} onEdit={startEdit} onDelete={destroy} />
                <Catalog kind="advance_option" rows={rowsByKind.advance_option.data} links={rowsByKind.advance_option.links} columns={catalogs.advance_option.columns} title={catalogs.advance_option.title} onEdit={startEdit} onDelete={destroy} />
                <Catalog kind="document_sequence" rows={rowsByKind.document_sequence.data} links={rowsByKind.document_sequence.links} columns={catalogs.document_sequence.columns} title={catalogs.document_sequence.title} onEdit={startEdit} onDelete={destroy} />
            </section>
        </AuthenticatedLayout>
    );
}

function emptyForm() {
    return {
        kind: 'sale_type',
        name: '',
        code: '',
        symbol: '',
        exchange_rate_to_bob: '1',
        is_base: false,
        type: 'percentage',
        percentage: '',
        amount: '',
        branch_id: '',
        document_type: 'sale_note',
        prefix: '',
        next_number: 1,
        padding: 6,
        is_active: true,
    };
}

function Catalog({ kind, title, rows, columns, links, onEdit, onDelete }) {
    return (
        <div className="mb-8">
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <h3 className="font-semibold">{title}</h3>
                </div>
                <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                    <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <tr>
                            {columns.map((column) => <th key={column} className="px-4 py-3 font-medium">{label(column)}</th>)}
                            <th className="px-4 py-3 text-right font-medium">Acciones</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                {columns.map((column) => (
                                    <td key={column} className="px-4 py-3">{formatValue(column, row[column])}</td>
                                ))}
                                <td className="px-4 py-3">
                                    <div className="flex justify-end gap-3">
                                        <IconButton icon="edit" label="Editar" onClick={() => onEdit(kind, row)} />
                                        <IconButton icon="trash" label="Eliminar" tone="danger" onClick={() => onDelete(kind, row)} disabled={kind === 'currency' && row.is_base} />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-4">
                <Pagination links={links} />
            </div>
        </div>
    );
}

function label(column) {
    return {
        name: 'Nombre',
        code: 'Codigo',
        symbol: 'Simbolo',
        exchange_rate_to_bob: 'Cambio a Bs',
        is_base: 'Base',
        type: 'Tipo',
        percentage: 'Porcentaje',
        amount: 'Monto',
        branch: 'Sucursal',
        document_type: 'Documento',
        prefix: 'Prefijo',
        next_number: 'Siguiente',
        padding: 'Digitos',
        is_active: 'Estado',
    }[column] ?? column;
}

function formatValue(column, value) {
    if (column === 'is_active') {
        return value ? 'Activo' : 'Inactivo';
    }

    if (column === 'is_base') {
        return value ? 'Si' : 'No';
    }

    if (column === 'percentage') {
        return value === null || value === undefined ? '-' : `${Number(value ?? 0).toFixed(2)}%`;
    }

    if (column === 'amount') {
        return value === null || value === undefined ? '-' : `Bs ${Number(value ?? 0).toFixed(2)}`;
    }

    if (column === 'type') {
        return value === 'amount' ? 'Monto fijo' : 'Porcentaje';
    }

    if (column === 'exchange_rate_to_bob') {
        return Number(value ?? 0).toFixed(6);
    }

    if (column === 'branch') {
        return value?.name ?? '-';
    }

    if (column === 'document_type') {
        return value === 'quotation' ? 'Cotizacion' : 'Nota de venta';
    }

    return value ?? '-';
}
