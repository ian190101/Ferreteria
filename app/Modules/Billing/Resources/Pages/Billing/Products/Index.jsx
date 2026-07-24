import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, useForm } from '@inertiajs/react';

export default function Index({ products, unitMeasures = [] }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Homologacion SIAT</h2>}>
            <Head title="Homologacion SIAT" />
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Homologacion de productos" description="Cada producto que se factura debe tener actividad economica, codigo producto SIN y unidad de medida SIAT." />
                <div className="space-y-4">
                    {products.data.map((product) => (
                        <ProductMappingForm key={product.id} product={product} unitMeasures={unitMeasures} />
                    ))}
                </div>
                <Pagination links={products.links} />
            </section>
        </AuthenticatedLayout>
    );
}

function ProductMappingForm({ product, unitMeasures }) {
    const mapping = product.siat_mapping ?? product.siatMapping ?? {};
    const form = useForm({
        economic_activity_code: mapping.economic_activity_code ?? '',
        sin_product_code: mapping.sin_product_code ?? '',
        unit_measure_code: mapping.unit_measure_code ?? unitMeasures[0]?.code ?? '',
        fiscal_description: mapping.fiscal_description ?? product.name,
        is_invoiceable: mapping.is_invoiceable ?? true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('billing.products.store', product.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="font-semibold text-slate-950 dark:text-white">{product.name}</h3>
                    <p className="text-sm text-slate-500">SKU: {product.sku ?? '-'} | Unidad interna: {product.unit?.symbol ?? '-'}</p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${mapping.id ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'}`}>
                    {mapping.id ? 'Homologado' : 'Pendiente'}
                </span>
            </div>
            <div className="grid gap-3 md:grid-cols-5">
                <FormField label="Actividad economica" type="number" value={form.data.economic_activity_code} onChange={(event) => form.setData('economic_activity_code', event.target.value)} required />
                <FormField label="Codigo producto SIN" type="number" value={form.data.sin_product_code} onChange={(event) => form.setData('sin_product_code', event.target.value)} required />
                <SelectField label="Unidad SIAT" value={form.data.unit_measure_code} onChange={(event) => form.setData('unit_measure_code', event.target.value)} required>
                    {unitMeasures.map((unit) => <option key={unit.code} value={unit.code}>{unit.code} - {unit.description}</option>)}
                    {unitMeasures.length === 0 ? <option value="58">58 - UNIDAD</option> : null}
                </SelectField>
                <FormField label="Descripcion fiscal" value={form.data.fiscal_description} onChange={(event) => form.setData('fiscal_description', event.target.value)} />
                <div className="flex items-end gap-2">
                    <label className="flex h-12 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm dark:border-slate-700">
                        <input type="checkbox" checked={form.data.is_invoiceable} onChange={(event) => form.setData('is_invoiceable', event.target.checked)} />
                        Facturable
                    </label>
                    <button className="h-12 rounded-xl bg-brand-primary px-4 text-sm font-semibold text-white" disabled={form.processing}>Guardar</button>
                </div>
            </div>
        </form>
    );
}
