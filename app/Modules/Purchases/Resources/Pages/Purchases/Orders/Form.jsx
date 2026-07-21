import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, useForm } from '@inertiajs/react';

const DEFAULT_ITEM = {
    product_id: '',
    weight_unit: 'kg',
    kilograms: '',
    meters: '',
    cost_mode: 'meter',
    cost_per_ton: '',
    unit_cost: '0',
    lot_number: '',
    coil_barcode: '',
    description: '',
};

export default function Form({ branches, suppliers, products }) {
    const decimalFormat = useDecimalFormatter('purchases');
    const { data, setData, post, processing, errors, transform } = useForm({
        branch_id: branches[0]?.id ?? '',
        supplier_id: '',
        order_number: '',
        ordered_at: new Date().toISOString().slice(0, 10),
        expected_at: '',
        status: 'draft',
        notes: '',
        items: [{ ...DEFAULT_ITEM }],
    });

    const productMap = new Map(products.map((product) => [String(product.id), product]));
    const updateItem = (index, field, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)));
    };
    const addItem = () => setData('items', [...data.items, { ...DEFAULT_ITEM }]);
    const removeItem = (index) => setData('items', data.items.filter((_, itemIndex) => itemIndex !== index));
    const convertedMeters = (item) => {
        const product = productMap.get(String(item.product_id));
        const kgPerMeter = Number(product?.thickness?.kg_per_meter ?? 0);
        const enteredWeight = Number(item.kilograms || 0);
        const kg = item.weight_unit === 'ton' ? enteredWeight * 1000 : enteredWeight;

        return item.meters || (kgPerMeter && kg ? decimalFormat.fixed(kg / kgPerMeter, 'measure') : '');
    };

    const submit = (event) => {
        event.preventDefault();
        transform((payload) => ({
            ...payload,
            items: payload.items.map((item) => preparePurchaseItem(item, products, decimalFormat)),
        }));
        post(route('purchases.orders.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ordenes de compra</h2>}>
            <Head title="Nueva orden de compra" />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Nueva orden de compra" description="Planifica mercaderia por peso en kg o toneladas; el sistema convierte a metros segun el espesor del producto." />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                        <FormField label="Nro. orden" name="order_number" value={data.order_number} onChange={(event) => setData('order_number', event.target.value)} error={errors.order_number} required />
                        <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id}>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Proveedor" name="supplier_id" value={data.supplier_id} onChange={(event) => setData('supplier_id', event.target.value)} error={errors.supplier_id}>
                            <option value="">Sin proveedor</option>
                            {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                        </SelectField>
                        <FormField label="Fecha orden" name="ordered_at" type="date" value={data.ordered_at} onChange={(event) => setData('ordered_at', event.target.value)} error={errors.ordered_at} required />
                        <FormField label="Fecha esperada" name="expected_at" type="date" value={data.expected_at} onChange={(event) => setData('expected_at', event.target.value)} error={errors.expected_at} />
                        <SelectField label="Estado" name="status" value={data.status} onChange={(event) => setData('status', event.target.value)} error={errors.status}>
                            <option value="draft">Borrador</option>
                            <option value="approved">Aprobada</option>
                        </SelectField>
                        <div className="sm:col-span-2">
                            <FormField label="Notas" name="notes" value={data.notes} onChange={(event) => setData('notes', event.target.value)} error={errors.notes} />
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-base font-semibold text-slate-900 dark:text-white">Items solicitados</h3>
                            <button type="button" onClick={addItem} className="rounded-md border border-brand-primary px-3 py-2 text-sm text-brand-primary">Agregar item</button>
                        </div>

                        <div className="space-y-5">
                            {data.items.map((item, index) => {
                                const product = productMap.get(String(item.product_id));
                                const isCoil = product?.inventory_tracking_mode === 'coil';
                                const summary = purchaseItemSummary(item, product);

                                return (
                                    <div key={index} className="grid gap-3 border-t border-slate-100 pt-5 dark:border-slate-800 sm:grid-cols-7">
                                        <SelectField label="Producto" name={`items.${index}.product_id`} value={item.product_id} onChange={(event) => updateItem(index, 'product_id', event.target.value)} error={errors[`items.${index}.product_id`]}>
                                            <option value="">Seleccionar</option>
                                            {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.sku})</option>)}
                                        </SelectField>
                                        <FormField label="Peso" name={`items.${index}.kilograms`} type="number" step={decimalStep(decimalFormat.decimalsFor('weight'))} value={item.kilograms} onChange={(event) => updateItem(index, 'kilograms', event.target.value)} error={errors[`items.${index}.kilograms`]} />
                                        <SelectField label="Unidad" name={`items.${index}.weight_unit`} value={item.weight_unit ?? 'kg'} onChange={(event) => updateItem(index, 'weight_unit', event.target.value)} error={errors[`items.${index}.weight_unit`]}>
                                            <option value="kg">Kg</option>
                                            <option value="ton">Toneladas</option>
                                        </SelectField>
                                        <FormField label="Metros" name={`items.${index}.meters`} type="number" step={decimalStep(decimalFormat.decimalsFor('measure'))} value={item.meters} placeholder={convertedMeters(item)} onChange={(event) => updateItem(index, 'meters', event.target.value)} error={errors[`items.${index}.meters`]} />
                                        <SelectField label="Costo en" name={`items.${index}.cost_mode`} value={item.cost_mode ?? 'meter'} onChange={(event) => updateItem(index, 'cost_mode', event.target.value)}>
                                            <option value="meter">Costo por metro</option>
                                            <option value="ton">Costo por tonelada</option>
                                        </SelectField>
                                        {item.cost_mode === 'ton' ? (
                                            <FormField label="Costo/TON (Bs.)" name={`items.${index}.cost_per_ton`} type="number" step={decimalStep(decimalFormat.decimalsFor('cost'))} value={item.cost_per_ton} placeholder={`Bs. ${decimalFormat.cost(0)}`} onChange={(event) => updateItem(index, 'cost_per_ton', event.target.value)} />
                                        ) : (
                                            <FormField label="Costo/metro" name={`items.${index}.unit_cost`} type="number" step={decimalStep(decimalFormat.decimalsFor('cost'))} value={item.unit_cost} onChange={(event) => updateItem(index, 'unit_cost', event.target.value)} error={errors[`items.${index}.unit_cost`]} required />
                                        )}
                                        <FormField label="Lote" name={`items.${index}.lot_number`} value={item.lot_number} onChange={(event) => updateItem(index, 'lot_number', event.target.value)} error={errors[`items.${index}.lot_number`]} />
                                        <FormField label="Barcode bobina" name={`items.${index}.coil_barcode`} value={item.coil_barcode} disabled={!isCoil} onChange={(event) => updateItem(index, 'coil_barcode', event.target.value)} error={errors[`items.${index}.coil_barcode`]} />
                                        <div className="sm:col-span-7">
                                            <div className="mb-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950">
                                                <p className="text-slate-500 dark:text-slate-400">Equivalente: <span className="font-semibold text-emerald-600">{decimalFormat.measure(summary.meters)} m</span></p>
                                                <p className="mt-1 text-slate-500 dark:text-slate-400">Subtotal costo: <span className="font-semibold text-slate-950 dark:text-slate-50">Bs {decimalFormat.money(summary.total)}</span></p>
                                            </div>
                                            <FormField label="Descripcion" name={`items.${index}.description`} value={item.description} onChange={(event) => updateItem(index, 'description', event.target.value)} error={errors[`items.${index}.description`]} />
                                        </div>
                                        {data.items.length > 1 ? <button type="button" onClick={() => removeItem(index)} className="self-end text-left text-sm text-red-600">Quitar item</button> : null}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>Guardar orden</PrimaryButton>
                        <Link href={route('purchases.orders.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function preparePurchaseItem(item, products, decimalFormat) {
    const product = products.find((product) => String(product.id) === String(item.product_id));
    const summary = purchaseItemSummary(item, product);

    return {
        product_id: item.product_id,
        weight_unit: item.weight_unit,
        kilograms: item.kilograms,
        meters: summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : item.meters,
        unit_cost: summary.unitCost ? decimalFormat.fixed(summary.unitCost, 'cost') : item.unit_cost,
        lot_number: item.lot_number,
        coil_barcode: item.coil_barcode,
        description: item.description,
    };
}

function purchaseItemSummary(item, product) {
    const kgPerMeter = Number(product?.thickness?.kg_per_meter ?? 0);
    const kg = weightInKg(item.kilograms, item.weight_unit);
    const meters = Number(item.meters || 0) || (kgPerMeter && kg ? kg / kgPerMeter : 0);
    const tons = kg / 1000;
    const lineTotal = item.cost_mode === 'ton'
        ? tons * Number(item.cost_per_ton || 0)
        : meters * Number(item.unit_cost || 0);

    return {
        meters,
        total: lineTotal,
        unitCost: meters > 0 ? lineTotal / meters : Number(item.unit_cost || 0),
    };
}

function weightInKg(weight, unit) {
    const value = Number(weight || 0);

    return unit === 'ton' ? value * 1000 : value;
}
