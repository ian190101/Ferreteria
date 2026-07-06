import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ supplier }) {
    const isEditing = Boolean(supplier);
    const { data, setData, post, put, processing, errors } = useForm({
        name: supplier?.name ?? '',
        tax_id: supplier?.tax_id ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        is_active: supplier?.is_active ?? true,
    });

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('purchases.suppliers.update', supplier.id), { preserveScroll: true });
            return;
        }

        post(route('purchases.suppliers.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title={isEditing ? 'Editar proveedor' : 'Nuevo proveedor'} />

            <section className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={isEditing ? 'Editar proveedor' : 'Nuevo proveedor'} description="Datos basicos del proveedor para documentos de compra." />

                <form onSubmit={submit} className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    <FormField label="NIT/CI" name="tax_id" value={data.tax_id} onChange={(event) => setData('tax_id', event.target.value)} error={errors.tax_id} />
                    <FormField label="Telefono" name="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} error={errors.phone} />
                    <FormField label="Correo electronico" name="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} />
                    <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </SelectField>
                    <div className="flex items-end gap-3">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('purchases.suppliers.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
