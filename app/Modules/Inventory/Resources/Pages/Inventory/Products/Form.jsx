import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { useDecimalFormatter } from '@/Utils/formatters';
import ProductFormFields, { buildProductFormData } from './ProductFormFields';
import ProductLotsPanel from '../../../Components/ProductLotsPanel';

export default function Form({ product, thicknesses, categories, units, branches = [], attributeDefinitions = [], serviceTypes = [], productPolicy = {}, lotOverview = null }) {
    const isEditing = Boolean(product);
    const decimalFormat = useDecimalFormatter('inventory');
    const { data, setData, post, put, transform, processing, errors } = useForm(buildProductFormData({
        product,
        categories,
        units,
        branches,
    }));

    const submit = (event) => {
        event.preventDefault();
        transform((current) => {
            if (productPolicy.lotsEnabled) return { ...current, lot_registrations: current.requires_lot ? current.lot_registrations : [] };
            const payload = { ...current };
            delete payload.lot_registrations;
            if (isEditing) delete payload.requires_lot;
            else payload.requires_lot = false;
            return payload;
        });

        if (isEditing) {
            put(route('inventory.products.update', product.id), { preserveScroll: true });
            return;
        }

        post(route('inventory.products.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title={isEditing ? 'Editar producto' : 'Nuevo producto'} />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title={isEditing ? 'Editar producto' : 'Nuevo producto'}
                    description={productPolicy.catalogMode === 'barcode_retail'
                        ? 'Catalogo retail optimizado para codigo de barras, POS y venta rapida.'
                        : productPolicy.catalogMode === 'services'
                            ? 'Catalogo de servicios o items sin stock obligatorio.'
                            : 'Catalogo general para productos, cajas, paquetes, unidades y medidas.'}
                />

                <div className="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    Perfil activo: {productPolicy.barcodeRequired ? 'barcode obligatorio' : 'barcode autogenerado si se deja vacio'}; {productPolicy.unitEquivalencesEnabled ? 'equivalencias habilitadas' : 'equivalencias desactivadas'}; {productPolicy.allowServiceItems ? 'permite items de servicio' : 'solo productos de inventario'}; {productPolicy.imagesEnabled ? 'imagenes habilitadas' : 'imagenes desactivadas'}; {productPolicy.variantsEnabled ? 'variantes habilitadas' : 'variantes desactivadas'}.
                </div>

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
                        serviceTypes={serviceTypes}
                        decimalFormat={decimalFormat}
                        productPolicy={productPolicy}
                    />

                    <div className="mt-5 flex items-center gap-3">
                        <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                        <Link href={route('inventory.products.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                            Cancelar
                        </Link>
                    </div>
                </form>
                {lotOverview ? <ProductLotsPanel overview={lotOverview} /> : null}
            </section>
        </AuthenticatedLayout>
    );
}
