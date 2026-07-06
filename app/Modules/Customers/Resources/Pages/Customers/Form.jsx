import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ customer, types }) {
    const isEditing = Boolean(customer);
    const { data, setData, post, put, processing, errors } = useForm({
        customer_type_id: customer?.customer_type_id ?? '',
        name: customer?.name ?? '',
        document_number: customer?.document_number ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        address: customer?.address ?? '',
        is_active: customer?.is_active ?? true,
    });

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('customers.update', customer.id), { preserveScroll: true });
            return;
        }

        post(route('customers.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Clientes</h2>}>
            <Head title={isEditing ? 'Editar cliente' : 'Nuevo cliente'} />

            <section className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={isEditing ? 'Editar cliente' : 'Nuevo cliente'} description="Datos comerciales usados para autocompletar cotizaciones y notas de venta sin perder el historico impreso." />

                <form onSubmit={submit} className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <SelectField label="Tipo" name="customer_type_id" value={data.customer_type_id ?? ''} onChange={(event) => setData('customer_type_id', event.target.value || null)} error={errors.customer_type_id}>
                        <option value="">Sin tipo</option>
                        {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </SelectField>
                    <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    <FormField label="Documento / NIT / CI" name="document_number" value={data.document_number} onChange={(event) => setData('document_number', event.target.value)} error={errors.document_number} />
                    <FormField label="Telefono" name="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} error={errors.phone} />
                    <FormField label="Correo electronico" name="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} />
                    <div className="sm:col-span-2">
                        <FormField label="Direccion" name="address" value={data.address} onChange={(event) => setData('address', event.target.value)} error={errors.address} />
                    </div>

                    <div className="flex items-center gap-3 sm:col-span-2">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar cliente' : 'Guardar cliente'}</PrimaryButton>
                        <Link href={route('customers.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
