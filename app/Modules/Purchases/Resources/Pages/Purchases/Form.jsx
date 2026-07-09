import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { useMemo } from 'react';

const DEFAULT_ITEM = {
    product_id: '',
    display_quantity: '1',
    display_unit_label: '',
    calculation_mode: 'direct',
    item_attributes: [],
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

export default function Form({ branches = [], suppliers = [], products = [] }) {
    const catalogsReady = products.length > 0;
    const decimalFormat = useDecimalFormatter('purchases');
    const { data, setData, post, processing, errors, transform } = useForm({
        branch_id: branches[0]?.id ?? '',
        supplier_id: '',
        document_number: '',
        purchase_date: new Date().toISOString().slice(0, 10),
        status: 'received',
        items: [{ ...DEFAULT_ITEM }],
    });

    const productMap = useMemo(() => new Map(products.map((product) => [String(product.id), product])), [products]);
    const updateItem = (index, field, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)));
    };
    const selectProduct = (index, value) => {
        const product = productMap.get(String(value));

        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_id: value,
            display_unit_label: productUnitSymbol(product),
            item_attributes: defaultItemAttributes(product),
            calculation_mode: 'direct',
            meters: item.display_quantity || '1',
        } : item)));
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
        post(route('purchases.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title="Nueva compra" />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Nueva compra" description="Ingresa la cantidad en la unidad real del producto. Usa calculo por largo o peso solo cuando corresponda." />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                        <FormField label="Documento" name="document_number" value={data.document_number} onChange={(event) => setData('document_number', event.target.value)} error={errors.document_number} required />
                        <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id}>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Proveedor" name="supplier_id" value={data.supplier_id} onChange={(event) => setData('supplier_id', event.target.value)} error={errors.supplier_id}>
                            <option value="">Sin proveedor</option>
                            {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                        </SelectField>
                        <FormField label="Fecha" name="purchase_date" type="date" value={data.purchase_date} onChange={(event) => setData('purchase_date', event.target.value)} error={errors.purchase_date} required />
                        <SelectField label="Estado" name="status" value={data.status} onChange={(event) => setData('status', event.target.value)} error={errors.status}>
                            <option value="received">Recibida e ingresar inventario</option>
                            <option value="draft">Borrador sin mover inventario</option>
                        </SelectField>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-base font-semibold text-slate-900 dark:text-white">Items</h3>
                            <button type="button" onClick={addItem} className="rounded-md border border-brand-primary px-3 py-2 text-sm text-brand-primary">Agregar item</button>
                        </div>

                        <div className="space-y-5">
                            {data.items.map((item, index) => {
                                const product = productMap.get(String(item.product_id));
                                const isCoil = product?.inventory_tracking_mode === 'coil';
                                const summary = purchaseItemSummary(item, product);

                                return (
                                    <div key={index} className="grid gap-3 border-t border-slate-100 pt-5 dark:border-slate-800 sm:grid-cols-7">
                                        <SelectField label="Producto" name={`items.${index}.product_id`} value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)} error={errors[`items.${index}.product_id`]}>
                                            <option value="">Seleccionar</option>
                                            {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.sku}) - {trackingLabel(product)}</option>)}
                                        </SelectField>
                                        <FormField label="Cantidad" name={`items.${index}.display_quantity`} type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind(product)))} value={item.display_quantity} onChange={(event) => updateItem(index, 'display_quantity', event.target.value)} error={errors[`items.${index}.display_quantity`]} required />
                                        <FormField label="Unidad" name={`items.${index}.display_unit_label`} value={item.display_unit_label || productUnitSymbol(product)} onChange={(event) => updateItem(index, 'display_unit_label', event.target.value)} error={errors[`items.${index}.display_unit_label`]} required />
                                        <SelectField label="Calculo opcional" name={`items.${index}.calculation_mode`} value={item.calculation_mode ?? 'direct'} onChange={(event) => updateItem(index, 'calculation_mode', event.target.value)}>
                                            <option value="direct">Sin calculo</option>
                                            <option value="length">Cantidad x largo</option>
                                            <option value="weight">Peso a metros</option>
                                        </SelectField>
                                        {item.calculation_mode === 'weight' ? (
                                            <>
                                                <FormField label="Peso" name={`items.${index}.kilograms`} type="number" step={decimalStep(decimalFormat.decimalsFor('weight'))} value={item.kilograms} onChange={(event) => updateItem(index, 'kilograms', event.target.value)} error={errors[`items.${index}.kilograms`]} />
                                                <SelectField label="Unidad peso" name={`items.${index}.weight_unit`} value={item.weight_unit ?? 'kg'} onChange={(event) => updateItem(index, 'weight_unit', event.target.value)} error={errors[`items.${index}.weight_unit`]}>
                                                    <option value="kg">Kg</option>
                                                    <option value="ton">Toneladas</option>
                                                </SelectField>
                                            </>
                                        ) : item.calculation_mode === 'length' ? (
                                            <FormField label={`Cantidad base (${productUnitSymbol(product)})`} name={`items.${index}.meters`} type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind(product)))} value={baseQuantityFieldValue(item, product, summary, decimalFormat)} placeholder={convertedMeters(item)} onChange={(event) => updateItem(index, 'meters', event.target.value)} error={errors[`items.${index}.meters`]} />
                                        ) : (
                                            <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                                Se guardara {formatProductQuantity(item.display_quantity || 0, product, decimalFormat)}
                                            </div>
                                        )}
                                        {productAttributes(product).length ? (
                                            <div className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950 sm:col-span-7 sm:grid-cols-4">
                                                {productAttributes(product).map((attribute) => (
                                                    <AttributeField
                                                        key={attribute.code}
                                                        attribute={attribute}
                                                        value={attributeValue(item, attribute)}
                                                        onChange={(value) => updateItemAttribute(index, attribute, value, data, setData)}
                                                    />
                                                ))}
                                            </div>
                                        ) : null}
                                        <SelectField label="Costo en" name={`items.${index}.cost_mode`} value={item.cost_mode ?? 'meter'} onChange={(event) => updateItem(index, 'cost_mode', event.target.value)}>
                                            <option value="meter">{item.calculation_mode === 'direct' ? 'Costo por unidad' : 'Costo por metro'}</option>
                                            {item.calculation_mode === 'weight' ? <option value="ton">Costo por tonelada</option> : null}
                                        </SelectField>
                                        {item.cost_mode === 'ton' && item.calculation_mode === 'weight' ? (
                                            <FormField label="Costo/TON (Bs.)" name={`items.${index}.cost_per_ton`} type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} value={item.cost_per_ton} placeholder={`Bs. ${decimalFormat.money(0)}`} onChange={(event) => updateItem(index, 'cost_per_ton', event.target.value)} />
                                        ) : (
                                            <FormField label={item.calculation_mode === 'direct' ? 'Costo/unidad' : 'Costo/metro'} name={`items.${index}.unit_cost`} type="number" step={decimalStep(decimalFormat.decimalsFor('cost'))} value={item.unit_cost} onChange={(event) => updateItem(index, 'unit_cost', event.target.value)} error={errors[`items.${index}.unit_cost`]} required />
                                        )}
                                        <FormField label="Lote" name={`items.${index}.lot_number`} value={item.lot_number} onChange={(event) => updateItem(index, 'lot_number', event.target.value)} error={errors[`items.${index}.lot_number`]} />
                                        <FormField label="Barcode lote/unidad" name={`items.${index}.coil_barcode`} value={item.coil_barcode} disabled={!isCoil} onChange={(event) => updateItem(index, 'coil_barcode', event.target.value)} error={errors[`items.${index}.coil_barcode`]} />
                                        <div className="sm:col-span-7">
                                            <div className="mb-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950">
                                                <p className="text-slate-500 dark:text-slate-400">{item.calculation_mode === 'direct' ? 'Cantidad' : 'Equivalente'}: <span className="font-semibold text-emerald-600">{item.calculation_mode === 'direct' ? formatProductQuantity(summary.meters, product, decimalFormat) : `${decimalFormat.measure(summary.meters)} m`}</span></p>
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
                        <PrimaryButton disabled={processing || !catalogsReady}>
                            {catalogsReady ? 'Registrar compra' : 'Cargando productos...'}
                        </PrimaryButton>
                        <Link href={route('purchases.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
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
        display_quantity: item.display_quantity || '1',
        display_unit_label: item.display_unit_label || productUnitSymbol(product),
        calculation_mode: item.calculation_mode || 'direct',
        item_attributes: normalizedItemAttributes(item, product),
        weight_unit: item.weight_unit,
        kilograms: item.calculation_mode === 'weight' ? item.kilograms : '',
        meters: summary.meters ? decimalFormat.fixed(summary.meters, item.calculation_mode === 'direct' ? quantityKind(product) : 'measure') : item.meters,
        unit_cost: summary.unitCost ? decimalFormat.fixed(summary.unitCost, 'cost') : item.unit_cost,
        lot_number: item.lot_number,
        coil_barcode: item.coil_barcode,
        description: item.description,
    };
}

function purchaseItemSummary(item, product) {
    const kgPerMeter = Number(product?.thickness?.kg_per_meter ?? 0);
    const kg = weightInKg(item.kilograms, item.weight_unit);
    const meters = item.calculation_mode === 'weight'
        ? (kgPerMeter && kg ? kg / kgPerMeter : 0)
        : baseQuantityFromItem(item, product);
    const tons = kg / 1000;
    const lineTotal = item.cost_mode === 'ton' && item.calculation_mode === 'weight'
        ? tons * Number(item.cost_per_ton || 0)
        : meters * Number(item.unit_cost || 0);

    return {
        meters,
        total: lineTotal,
        unitCost: meters > 0 ? lineTotal / meters : Number(item.unit_cost || 0),
    };
}

function AttributeField({ attribute, value, onChange }) {
    const label = `${attribute.name}${attribute.unit ? ` (${attribute.unit.symbol})` : ''}`;

    if (attribute.type === 'select') {
        return (
            <SelectField label={label} name={`attribute_${attribute.code}`} value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
                <option value="">-</option>
                {(attribute.options ?? []).map((option) => <option key={option} value={option}>{option}</option>)}
            </SelectField>
        );
    }

    if (attribute.type === 'boolean') {
        return (
            <SelectField label={label} name={`attribute_${attribute.code}`} value={String(value ?? '')} onChange={(event) => onChange(event.target.value)}>
                <option value="">-</option>
                <option value="1">Si</option>
                <option value="0">No</option>
            </SelectField>
        );
    }

    return (
        <FormField
            label={label}
            name={`attribute_${attribute.code}`}
            type={attribute.type === 'number' ? 'number' : 'text'}
            step={attribute.type === 'number' ? '0.01' : undefined}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

function updateItemAttribute(index, attribute, value, data, setData) {
    setData('items', data.items.map((item, itemIndex) => {
        if (itemIndex !== index) return item;

        const next = [...(item.item_attributes ?? [])].filter((entry) => entry.code !== attribute.code);

        next.push({
            code: attribute.code,
            name: attribute.name,
            value,
            unit: attribute.unit?.symbol ?? '',
        });

        return { ...item, item_attributes: next };
    }));
}

function defaultItemAttributes(product) {
    return productAttributes(product).map((attribute) => ({
        code: attribute.code,
        name: attribute.name,
        value: product?.attributes?.[attribute.code] ?? '',
        unit: attribute.unit?.symbol ?? '',
    }));
}

function normalizedItemAttributes(item, product) {
    const current = new Map((item.item_attributes ?? []).map((attribute) => [attribute.code, attribute]));

    return productAttributes(product).map((attribute) => {
        const currentValue = current.get(attribute.code)?.value;

        return {
            code: attribute.code,
            name: attribute.name,
            value: currentValue ?? product?.attributes?.[attribute.code] ?? '',
            unit: attribute.unit?.symbol ?? '',
        };
    });
}

function productAttributes(product) {
    return product?.product_category?.attributes ?? product?.productCategory?.attributes ?? [];
}

function attributeValue(item, attribute) {
    return (item.item_attributes ?? []).find((entry) => entry.code === attribute.code)?.value ?? '';
}

function baseQuantityFromItem(item, product) {
    const quantity = Number(item.display_quantity || 0);
    const length = Number(attributeValue(item, { code: 'largo' }) || product?.attributes?.largo || 0);

    if (item.calculation_mode === 'length' && length > 0) {
        return quantity * length;
    }

    return quantity || Number(item.meters || 0);
}

function baseQuantityFieldValue(item, product, summary, decimalFormat) {
    if (item.calculation_mode === 'length' && Number(attributeValue(item, { code: 'largo' }) || product?.attributes?.largo || 0) > 0) {
        return summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : '';
    }

    return summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : item.meters;
}

function productUnitSymbol(product) {
    return product?.unit?.symbol ?? product?.base_unit ?? 'unidad';
}

function quantityKind(product) {
    const unit = String(productUnitSymbol(product)).toLowerCase();

    if (['m', 'metro', 'metros'].includes(unit)) return 'measure';
    if (['kg', 'lb'].includes(unit)) return 'weight';

    return 'quantity';
}

function formatProductQuantity(value, product, decimalFormat) {
    const unit = productUnitSymbol(product);

    return `${decimalFormat.format(value, quantityKind(product))} ${unit}`;
}

function trackingLabel(product) {
    return product?.inventory_tracking_mode === 'coil'
        ? 'Individual por lote/unidad'
        : 'Global por sucursal';
}

function weightInKg(weight, unit) {
    const value = Number(weight || 0);

    return unit === 'ton' ? value * 1000 : value;
}
