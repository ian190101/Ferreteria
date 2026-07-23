import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { useDecimalFormatter } from '@/Utils/formatters';
import ProductFormFields, { buildProductFormData } from './ProductFormFields';

export default function Form({ product, thicknesses, categories, units, branches = [], attributeDefinitions = [] }) {
    const isEditing = Boolean(product);
    const decimalFormat = useDecimalFormatter('inventory');
    const { data, setData, post, put, processing, errors } = useForm(buildProductFormData({
        product,
        categories,
        units,
        branches,
    }));

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('inventory.products.update', product.id), { preserveScroll: true });
            return;
        }

        post(route('inventory.products.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title={isEditing ? 'Editar producto' : 'Nuevo producto'} />

            <section className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title={isEditing ? 'Editar producto' : 'Nuevo producto'}
                    description="Catalogo general para ferreteria: calaminas, herramientas, pinturas, tornilleria, cajas, paquetes y otros productos."
                />

                <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <ProductFormFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        thicknesses={thicknesses}
                        categories={categories}
                        units={units}
                        branches={branches}
                        attributeDefinitions={attributeDefinitions}
                        decimalFormat={decimalFormat}
                    />

                    <div className="mt-5 flex items-center gap-3">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('inventory.products.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                            Cancelar
                        </Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
