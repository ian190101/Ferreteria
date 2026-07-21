import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ thickness }) {
    const isEditing = Boolean(thickness);
    const decimalFormat = useDecimalFormatter('inventory');
    const { data, setData, post, put, processing, errors } = useForm({
        name: thickness?.name ?? '',
        millimeters: thickness?.millimeters ?? '',
        kg_per_meter: thickness?.kg_per_meter ?? '',
        is_active: thickness?.is_active ?? true,
    });
    const metersPerKg = Number(data.kg_per_meter) > 0 ? decimalFormat.exchangeRate(1 / Number(data.kg_per_meter)) : '';

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('inventory.thicknesses.update', thickness.id), { preserveScroll: true });
            return;
        }

        post(route('inventory.thicknesses.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title={isEditing ? 'Editar espesor' : 'Nuevo espesor'} />

            <section className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title={isEditing ? 'Editar espesor' : 'Nuevo espesor'}
                    description="Configura el peso real de cada metro. El sistema calcula metros dividiendo el peso ingresado entre kg/m."
                />

                <form onSubmit={submit} className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    <FormField label="Milimetros" name="millimeters" type="number" step={decimalStep(decimalFormat.decimalsFor('measure'))} value={data.millimeters} onChange={(event) => setData('millimeters', event.target.value)} error={errors.millimeters} required />
                    <FormField
                        label="Peso por metro (kg/m)"
                        name="kg_per_meter"
                        type="number"
                        step={decimalStep(decimalFormat.decimalsFor('exchange_rate'))}
                        value={data.kg_per_meter}
                        placeholder="Ej. 3.13"
                        onChange={(event) => setData('kg_per_meter', event.target.value)}
                        error={errors.kg_per_meter}
                        required
                    />
                    <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </SelectField>
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300 sm:col-span-2">
                        Formula: metros = kg / kg por metro. {metersPerKg ? `Equivale a ${metersPerKg} metros por kg.` : 'Ingresa kg/m para ver el equivalente.'}
                    </div>

                    <div className="flex items-center gap-3 sm:col-span-2">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('inventory.thicknesses.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                            Cancelar
                        </Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
