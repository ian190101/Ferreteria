import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

export default function Form({ branches, products }) {
    const decimalFormat = useDecimalFormatter('inventory');
    const { data, setData, post, processing, errors } = useForm({
        branch_id: '',
        product_id: '',
        barcode: '',
        lot_number: '',
        initial_kg: '',
        initial_meters: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('inventory.coils.store'), { preserveScroll: true });
    };
    const selectedProduct = products.find((product) => String(product.id) === String(data.product_id));
    const selectedUnit = selectedProduct?.unit?.symbol ?? selectedProduct?.base_unit ?? 'unidad';
    const quantityKind = selectedUnit === 'm' ? 'measure' : ['kg', 'lb'].includes(String(selectedUnit).toLowerCase()) ? 'weight' : 'quantity';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Nuevo lote/unidad fisica" />

            <section className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Nuevo lote/unidad fisica" description="Registra un lote, caja, paquete, rollo o pieza con barcode y cantidad inicial en la unidad del producto." />

                <form onSubmit={submit} className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id} required>
                        <option value="">Seleccionar sucursal</option>
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField label="Producto" name="product_id" value={data.product_id} onChange={(event) => setData('product_id', event.target.value)} error={errors.product_id} required>
                        <option value="">Seleccionar producto con rastreo individual</option>
                        {products.map((product) => (
                            <option key={product.id} value={product.id}>
                                {product.name} ({product.sku}) - {product.unit?.symbol ?? product.base_unit ?? 'unidad'}
                            </option>
                        ))}
                    </SelectField>
                    <FormField label="Barcode" name="barcode" value={data.barcode} onChange={(event) => setData('barcode', event.target.value)} error={errors.barcode} required />
                    <FormField label="Numero de lote" name="lot_number" value={data.lot_number} onChange={(event) => setData('lot_number', event.target.value)} error={errors.lot_number} required />
                    <FormField label="Peso inicial kg (opcional)" name="initial_kg" type="number" step={decimalStep(decimalFormat.decimalsFor('weight'))} value={data.initial_kg} onChange={(event) => setData('initial_kg', event.target.value)} error={errors.initial_kg} />
                    <FormField label={`Cantidad inicial (${selectedUnit})`} name="initial_meters" type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind))} value={data.initial_meters} onChange={(event) => setData('initial_meters', event.target.value)} error={errors.initial_meters} required />

                    <div className="flex items-center gap-3 sm:col-span-2">
                        <PrimaryButton disabled={processing}>Registrar lote/unidad</PrimaryButton>
                        <Link href={route('inventory.coils.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                            Cancelar
                        </Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}
