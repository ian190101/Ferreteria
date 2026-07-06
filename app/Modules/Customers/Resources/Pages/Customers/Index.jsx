import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../Shared/Resources/Components/ActionLink';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ customers, types, typeCatalog, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('customers.manage');
    const [editingType, setEditingType] = useState(null);
    const filterForm = useForm({
        search: filters.search ?? '',
        customer_type_id: filters.customer_type_id ?? '',
        is_active: filters.is_active ?? '',
        per_page: filters.per_page ?? 15,
    });
    const typeForm = useForm({
        name: '',
        is_active: true,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('customers.index'), { preserveScroll: true, preserveState: true });
    };

    const submitType = (event) => {
        event.preventDefault();

        if (editingType) {
            typeForm.put(route('customers.types.update', editingType.id), {
                preserveScroll: true,
                onSuccess: cancelTypeEdit,
            });

            return;
        }

        typeForm.post(route('customers.types.store'), {
            preserveScroll: true,
            onSuccess: () => typeForm.reset(),
        });
    };

    const startTypeEdit = (type) => {
        setEditingType(type);
        typeForm.clearErrors();
        typeForm.setData({
            name: type.name,
            is_active: Boolean(type.is_active),
        });
    };

    const cancelTypeEdit = () => {
        setEditingType(null);
        typeForm.clearErrors();
        typeForm.setData({
            name: '',
            is_active: true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Clientes</h2>}>
            <Head title="Clientes" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Clientes" description="Gestion comercial de clientes, documentos, contactos y tipos reutilizables para cotizaciones y notas de venta." />
                    {canManage ? <ActionLink href={route('customers.create')}>Nuevo cliente</ActionLink> : null}
                </div>

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="Busqueda" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <SelectField label="Tipo" name="customer_type_id" value={filterForm.data.customer_type_id} onChange={(event) => filterForm.setData('customer_type_id', event.target.value)}>
                        <option value="">Todos</option>
                        {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="is_active" value={filterForm.data.is_active} onChange={(event) => filterForm.setData('is_active', event.target.value)}>
                        <option value="">Todos</option>
                        <option value="1">Activos</option>
                        <option value="0">Inactivos</option>
                    </SelectField>
                    <FormField label="Por pagina" name="per_page" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(event) => filterForm.setData('per_page', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('customers.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                {canManage ? (
                    <form onSubmit={submitType} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-[1fr_180px_auto]">
                        <FormField label={editingType ? 'Editar tipo de cliente' : 'Nuevo tipo de cliente'} name="name" value={typeForm.data.name} onChange={(event) => typeForm.setData('name', event.target.value)} error={typeForm.errors.name} required />
                        <SelectField label="Estado" name="type_is_active" value={typeForm.data.is_active ? '1' : '0'} onChange={(event) => typeForm.setData('is_active', event.target.value === '1')} error={typeForm.errors.is_active}>
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </SelectField>
                        <div className="flex items-end">
                            <button disabled={typeForm.processing} className="rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary" type="submit">
                                {editingType ? 'Actualizar tipo' : 'Agregar tipo'}
                            </button>
                            {editingType ? (
                                <button type="button" onClick={cancelTypeEdit} className="ms-3 rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700">
                                    Cancelar
                                </button>
                            ) : null}
                        </div>
                    </form>
                ) : null}

                {canManage ? (
                    <div className="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-900 dark:text-slate-100">Tipos de cliente</h3>
                            <p className="text-sm text-slate-500 dark:text-slate-400">Catalogo paginado usado para clasificar clientes y autocompletar documentos.</p>
                        </div>
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Nombre</th>
                                    <th className="px-4 py-3 text-right font-medium">Clientes</th>
                                    <th className="px-4 py-3 font-medium">Estado</th>
                                    <th className="px-4 py-3 text-right font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {typeCatalog.data.map((type) => (
                                    <tr key={type.id}>
                                        <td className="px-4 py-3 font-medium">{type.name}</td>
                                        <td className="px-4 py-3 text-right">{type.customers_count}</td>
                                        <td className="px-4 py-3">{type.is_active ? 'Activo' : 'Inactivo'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-3">
                                                <IconButton icon="edit" label="Editar" onClick={() => startTypeEdit(type)} />
                                                <IconButton
                                                    icon="power"
                                                    label="Desactivar"
                                                    tone="danger"
                                                    onClick={async () => {
                                                        if (await confirmAction({ title: 'Desactivar tipo de cliente', text: 'Este tipo dejara de estar disponible para nuevos clientes.', confirmButtonText: 'Desactivar' })) {
                                                            router.delete(route('customers.types.destroy', type.id), { preserveScroll: true });
                                                        }
                                                    }}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="px-4 py-3">
                            <Pagination links={typeCatalog.links} />
                        </div>
                    </div>
                ) : null}

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Cliente</th>
                                <th className="px-4 py-3 font-medium">Documento</th>
                                <th className="px-4 py-3 font-medium">Contacto</th>
                                <th className="px-4 py-3 font-medium">Tipo</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {customers.data.map((customer) => (
                                <tr key={customer.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-slate-900 dark:text-slate-100">{customer.name}</p>
                                        <p className="text-xs text-slate-500">{customer.email ?? customer.address ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">{customer.document_number ?? '-'}</td>
                                    <td className="px-4 py-3">{customer.phone ?? '-'}</td>
                                    <td className="px-4 py-3">{customer.type?.name ?? 'Sin tipo'}</td>
                                    <td className="px-4 py-3">{customer.is_active ? 'Activo' : 'Inactivo'}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                        <IconButton href={route('customers.statement', customer.id)} icon="eye" label="Estado" />
                                        {canManage ? (
                                            <>
                                            <IconButton href={route('customers.edit', customer.id)} icon="edit" label="Editar" />
                                            <IconButton
                                                icon="power"
                                                label="Desactivar"
                                                tone="danger"
                                                onClick={async () => {
                                                    if (await confirmAction({ title: 'Desactivar cliente', text: 'El cliente dejara de estar disponible para nuevas operaciones.', confirmButtonText: 'Desactivar' })) {
                                                        router.delete(route('customers.destroy', customer.id), { preserveScroll: true });
                                                    }
                                                }}
                                            />
                                            </>
                                        ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={customers.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
