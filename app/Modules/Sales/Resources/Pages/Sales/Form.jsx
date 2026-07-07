import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';

const moneyFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const numberFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
});

const DEFAULT_ITEM = {
    product_id: '',
    product_coil_id: '',
    description: '',
    unit_label: 'M',
    display_quantity: '1',
    display_unit_label: '',
    item_attributes: [],
    quantity_mode: 'direct',
    weight_unit: 'ton',
    weight: '',
    meters: '1',
    price_mode: 'meter',
    price_per_ton: '',
    unit_price: '0',
    discount_amount: '0',
};

export default function Form({ documentType, branches, saleTypes, currencies, advanceOptions, products, coils, customers, sequencePreviews, quotations = [] }) {
    const title = documentType === 'quotation' ? 'Nueva cotizacion' : 'Nueva nota de venta';
    const { data, setData, post, processing, errors, transform } = useForm({
        document_type: documentType,
        source_quotation_id: '',
        branch_id: branches[0]?.id ?? '',
        sale_type_id: saleTypes[0]?.id ?? '',
        currency_id: currencies[0]?.id ?? '',
        customer_id: '',
        advance_option_id: '',
        receipt_number: '',
        customer_name: '',
        customer_document: '',
        customer_contact: '',
        sold_at: '',
        terms: '',
        internal_notes: '',
        items: [{ ...DEFAULT_ITEM }],
    });

    const updateItem = (index, field, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)));
    };

    const selectProduct = (index, value) => {
        const product = products.find((item) => String(item.id) === String(value));

        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_id: value,
            product_coil_id: '',
            description: product?.name ?? item.description,
            unit_label: productUnitSymbol(product),
            display_unit_label: productUnitSymbol(product),
            item_attributes: defaultItemAttributes(product),
            quantity_mode: 'direct',
            meters: item.display_quantity || '1',
        } : item)));
    };

    const selectCustomer = (value) => {
        const customer = customers.find((item) => String(item.id) === String(value));

        setData({
            ...data,
            customer_id: value,
            customer_name: customer?.name ?? data.customer_name,
            customer_document: customer?.document_number ?? data.customer_document,
            customer_contact: customer?.phone ?? data.customer_contact,
        });
    };

    const selectQuotation = (value) => {
        const quotation = quotations.find((item) => String(item.id) === String(value));

        if (!quotation) {
            setData('source_quotation_id', '');

            return;
        }

        setData({
            ...data,
            source_quotation_id: value,
            branch_id: quotation.branch_id ?? data.branch_id,
            sale_type_id: quotation.sale_type_id ?? data.sale_type_id,
            currency_id: quotation.currency_id ?? data.currency_id,
            customer_id: quotation.customer_id ?? '',
            advance_option_id: quotation.advance_option_id ?? '',
            receipt_number: '',
            customer_name: quotation.customer_name ?? '',
            customer_document: quotation.customer_document ?? '',
            customer_contact: quotation.customer_contact ?? '',
            terms: quotation.terms ?? '',
            internal_notes: `Generada desde cotizacion ${quotation.receipt_number}`,
            items: (quotation.items ?? []).map((item) => saleItemFromQuotation(item, products)),
        });
    };

    const addItem = () => setData('items', [...data.items, { ...DEFAULT_ITEM }]);
    const removeItem = (index) => setData('items', data.items.filter((_, itemIndex) => itemIndex !== index));

    const submit = (event) => {
        event.preventDefault();
        transform((payload) => ({
            ...payload,
            items: payload.items.map((item) => prepareSaleItem(item, products)),
        }));
        post(route('sales.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title={title} />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={title} description="Completa los datos que se imprimiran en el formato de cotizacion o nota de venta." />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-3">
                        {documentType === 'sale_note' ? (
                            <div className="sm:col-span-3">
                                <SelectField label="Crear desde cotizacion" name="source_quotation_id" value={data.source_quotation_id} onChange={(event) => selectQuotation(event.target.value)} error={errors.source_quotation_id}>
                                    <option value="">Nota nueva sin cotizacion</option>
                                    {quotations.map((quotation) => (
                                        <option key={quotation.id} value={quotation.id}>
                                            {quotation.receipt_number} - {quotation.customer_name} - {quotation.branch?.name} - Bs {moneyFormatter.format(Number(quotation.total ?? 0))}
                                        </option>
                                    ))}
                                </SelectField>
                                <p className="mt-1 text-xs text-slate-500">Al seleccionar una cotizacion vigente se precargan sus datos e items para emitir la nota de venta.</p>
                            </div>
                        ) : null}
                        <div>
                            <FormField label="Numero" name="receipt_number" value={data.receipt_number} onChange={(event) => setData('receipt_number', event.target.value)} error={errors.receipt_number} placeholder={nextPreview(sequencePreviews, data.branch_id, data.document_type)} />
                            <p className="mt-1 text-xs text-slate-500">Vacio usa automatico: {nextPreview(sequencePreviews, data.branch_id, data.document_type)}</p>
                        </div>
                        <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id}>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <SelectField label="Tipo de venta" name="sale_type_id" value={data.sale_type_id} onChange={(event) => setData('sale_type_id', event.target.value)} error={errors.sale_type_id}>
                            {saleTypes.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                        </SelectField>
                        <SelectField label="Cliente registrado" name="customer_id" value={data.customer_id} onChange={(event) => selectCustomer(event.target.value)} error={errors.customer_id}>
                            <option value="">Cliente manual</option>
                            {customers.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.document_number ? `${customer.document_number} - ` : ''}{customer.name}
                                </option>
                            ))}
                        </SelectField>
                        <FormField label="Cliente" name="customer_name" value={data.customer_name} onChange={(event) => setData('customer_name', event.target.value)} error={errors.customer_name} required />
                        <FormField label="Documento cliente" name="customer_document" value={data.customer_document} onChange={(event) => setData('customer_document', event.target.value)} error={errors.customer_document} />
                        <FormField label="Contacto" name="customer_contact" value={data.customer_contact} onChange={(event) => setData('customer_contact', event.target.value)} error={errors.customer_contact} />
                        <SelectField label="Moneda" name="currency_id" value={data.currency_id} onChange={(event) => setData('currency_id', event.target.value)} error={errors.currency_id}>
                            {currencies.map((currency) => (
                                <option key={currency.id} value={currency.id}>
                                    {currency.name} ({currency.code}) - 1 {currency.code} = {currency.exchange_rate_to_bob} Bs
                                </option>
                            ))}
                        </SelectField>
                        <SelectField label="Anticipo" name="advance_option_id" value={data.advance_option_id} onChange={(event) => setData('advance_option_id', event.target.value)} error={errors.advance_option_id}>
                            <option value="">Sin anticipo</option>
                            {advanceOptions.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
                        </SelectField>
                        <FormField label="Fecha" name="sold_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" />
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-base font-semibold text-slate-900 dark:text-white">Items</h3>
                            <button type="button" onClick={addItem} className="rounded-md border border-brand-primary px-3 py-2 text-sm text-brand-primary">Agregar item</button>
                        </div>
                        <div className="space-y-4">
                            {data.items.map((item, index) => {
                                const product = selectedProduct(products, item);
                                const summary = saleItemSummary(item, product);

                                return (
                                <div key={index} className="grid gap-3 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-6">
                                    <SelectField label="Producto" name={`items.${index}.product_id`} value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)} error={errors[`items.${index}.product_id`]}>
                                        <option value="">Seleccionar</option>
                                        {products.map((product) => <option key={product.id} value={product.id}>{product.name} ({product.inventory_tracking_mode === 'coil' ? 'Bobina' : 'Global'})</option>)}
                                    </SelectField>
                                    {documentType === 'sale_note' && product?.inventory_tracking_mode === 'coil' ? (
                                        <SelectField label="Bobina" name={`items.${index}.product_coil_id`} value={item.product_coil_id} onChange={(event) => updateItem(index, 'product_coil_id', event.target.value)} error={errors[`items.${index}.product_coil_id`]}>
                                            <option value="">Seleccionar</option>
                                            {availableCoils(coils, data.branch_id, item.product_id).map((coil) => (
                                                <option key={coil.id} value={coil.id}>
                                                    {coil.barcode} - {coil.available_meters} m
                                                </option>
                                            ))}
                                        </SelectField>
                                    ) : null}
                                    <div className="sm:col-span-2">
                                        <FormField label="Descripcion" name={`items.${index}.description`} value={item.description} onChange={(event) => updateItem(index, 'description', event.target.value)} error={errors[`items.${index}.description`]} required />
                                    </div>
                                    <FormField label="Cantidad" name={`items.${index}.display_quantity`} type="number" step="0.001" value={item.display_quantity} onChange={(event) => updateItem(index, 'display_quantity', event.target.value)} error={errors[`items.${index}.display_quantity`]} required />
                                    <FormField label="Unidad" name={`items.${index}.display_unit_label`} value={item.display_unit_label || productUnitSymbol(product)} onChange={(event) => updateItem(index, 'display_unit_label', event.target.value)} error={errors[`items.${index}.display_unit_label`]} required />
                                    <SelectField label="Calculo opcional" name={`items.${index}.quantity_mode`} value={item.quantity_mode ?? 'direct'} onChange={(event) => updateItem(index, 'quantity_mode', event.target.value)}>
                                        <option value="direct">Sin calculo</option>
                                        <option value="length">Cantidad x largo</option>
                                        <option value="weight">Peso a metros</option>
                                    </SelectField>
                                    {item.quantity_mode === 'weight' ? (
                                        <>
                                            <FormField label="Peso" name={`items.${index}.weight`} type="number" step="0.001" value={item.weight} placeholder="0.000" onChange={(event) => updateItem(index, 'weight', event.target.value)} />
                                            <SelectField label="Unidad" name={`items.${index}.weight_unit`} value={item.weight_unit ?? 'ton'} onChange={(event) => updateItem(index, 'weight_unit', event.target.value)}>
                                                <option value="ton">Toneladas</option>
                                                <option value="kg">Kg</option>
                                            </SelectField>
                                        </>
                                    ) : item.quantity_mode === 'length' ? (
                                        <FormField label="Metraje total (m)" name={`items.${index}.meters`} type="number" step="0.001" value={baseQuantityFieldValue(item, product, summary)} onChange={(event) => updateItem(index, 'meters', event.target.value)} error={errors[`items.${index}.meters`]} required />
                                    ) : (
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                            Se guardara {numberFormatter.format(Number(item.display_quantity || 0))} {item.display_unit_label || productUnitSymbol(product)}
                                        </div>
                                    )}
                                    {productAttributes(product).length ? (
                                        <div className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950 sm:col-span-6 sm:grid-cols-4">
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
                                    <SelectField label="Precio en" name={`items.${index}.price_mode`} value={item.price_mode ?? 'meter'} onChange={(event) => updateItem(index, 'price_mode', event.target.value)}>
                                        <option value="meter">{item.quantity_mode === 'direct' ? 'Precio por unidad' : 'Precio por metro'}</option>
                                        <option value="ton">Precio por tonelada</option>
                                    </SelectField>
                                    {item.price_mode === 'ton' ? (
                                        <FormField label="Precio/TON (Bs.)" name={`items.${index}.price_per_ton`} type="number" step="0.01" value={item.price_per_ton} placeholder="Bs. 0.00" onChange={(event) => updateItem(index, 'price_per_ton', event.target.value)} />
                                    ) : (
                                        <FormField label={item.quantity_mode === 'direct' ? 'Precio/unidad' : 'Precio/metro'} name={`items.${index}.unit_price`} type="number" step="0.0001" value={item.unit_price} onChange={(event) => updateItem(index, 'unit_price', event.target.value)} error={errors[`items.${index}.unit_price`]} required />
                                    )}
                                    <FormField label="Desc." name={`items.${index}.discount_amount`} type="number" step="0.01" value={item.discount_amount} onChange={(event) => updateItem(index, 'discount_amount', event.target.value)} error={errors[`items.${index}.discount_amount`]} required />
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950 sm:col-span-6">
                                        <p className="text-slate-500 dark:text-slate-400">{item.quantity_mode === 'direct' ? 'Cantidad' : 'Equivalente'}: <span className="font-semibold text-emerald-600">{item.quantity_mode === 'direct' ? `${numberFormatter.format(summary.meters)} ${item.display_unit_label || productUnitSymbol(product)}` : `${numberFormatter.format(summary.meters)} m`}</span></p>
                                        <p className="mt-1 text-slate-500 dark:text-slate-400">Subtotal: <span className="font-semibold text-slate-950 dark:text-slate-50">Bs {moneyFormatter.format(summary.total)}</span></p>
                                        {item.quantity_mode === 'weight' && !product?.thickness?.kg_per_meter ? (
                                            <p className="mt-1 text-xs text-red-600">Este producto necesita espesor con kg/m para convertir peso a metros.</p>
                                        ) : null}
                                    </div>
                                    {data.items.length > 1 ? <button type="button" onClick={() => removeItem(index)} className="text-left text-sm text-red-600 sm:col-span-6">Quitar item</button> : null}
                                </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="terms">Texto final / notas impresas</label>
                        <textarea id="terms" rows="4" value={data.terms} onChange={(event) => setData('terms', event.target.value)} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" />
                    </div>

                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>Guardar documento</PrimaryButton>
                        <Link href={route('sales.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function prepareSaleItem(item, products) {
    const product = selectedProduct(products, item);
    const summary = saleItemSummary(item, product);

    return {
        product_id: item.product_id,
        product_coil_id: item.product_coil_id,
        description: item.description,
        unit_label: productUnitSymbol(product),
        display_quantity: item.display_quantity || '1',
        display_unit_label: item.display_unit_label || productUnitSymbol(product),
        item_attributes: normalizedItemAttributes(item, product),
        calculation_mode: item.quantity_mode || 'direct',
        meters: item.quantity_mode === 'weight'
            ? (summary.meters ? summary.meters.toFixed(3) : '')
            : (summary.meters ? summary.meters.toFixed(3) : item.display_quantity),
        unit_price: item.price_mode === 'ton'
            ? (summary.unitPrice ? summary.unitPrice.toFixed(4) : '')
            : item.unit_price,
        discount_amount: item.discount_amount || '0',
    };
}

function saleItemFromQuotation(item, products) {
    const product = products.find((entry) => String(entry.id) === String(item.product_id));
    const calculationMode = item.calculation_mode ?? 'direct';

    return {
        ...DEFAULT_ITEM,
        product_id: item.product_id ?? '',
        product_coil_id: item.product_coil_id ?? '',
        description: item.description ?? product?.name ?? '',
        unit_label: item.unit_label ?? productUnitSymbol(product),
        display_quantity: item.display_quantity ?? item.meters ?? '1',
        display_unit_label: item.display_unit_label ?? item.unit_label ?? productUnitSymbol(product),
        item_attributes: item.item_attributes ?? defaultItemAttributes(product),
        quantity_mode: calculationMode,
        meters: item.meters ?? item.display_quantity ?? '1',
        price_mode: 'meter',
        unit_price: item.unit_price ?? '0',
        discount_amount: item.discount_amount ?? '0',
    };
}

function saleItemSummary(item, product) {
    const kgPerMeter = Number(product?.thickness?.kg_per_meter ?? 0);
    const discount = Number(item.discount_amount || 0);
    const meters = item.quantity_mode === 'weight'
        ? metersFromWeight(item.weight, item.weight_unit, kgPerMeter)
        : baseQuantityFromItem(item, product);
    const tons = kgPerMeter && meters ? (meters * kgPerMeter) / 1000 : weightInKg(item.weight, item.weight_unit) / 1000;
    const lineBeforeDiscount = item.price_mode === 'ton'
        ? tons * Number(item.price_per_ton || 0)
        : meters * Number(item.unit_price || 0);
    const unitPrice = meters > 0 ? lineBeforeDiscount / meters : Number(item.unit_price || 0);

    return {
        meters,
        unitPrice,
        total: Math.max(lineBeforeDiscount - discount, 0),
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
            step={attribute.type === 'number' ? '0.001' : undefined}
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

    if (item.quantity_mode === 'length' && length > 0) {
        return quantity * length;
    }

    return quantity || Number(item.meters || 0);
}

function productUnitSymbol(product) {
    return product?.unit?.symbol ?? product?.base_unit ?? 'unidad';
}

function baseQuantityFieldValue(item, product, summary) {
    if (item.quantity_mode === 'length' && Number(attributeValue(item, { code: 'largo' }) || product?.attributes?.largo || 0) > 0) {
        return summary.meters ? summary.meters.toFixed(3) : '';
    }

    return summary.meters ? summary.meters.toFixed(3) : item.meters;
}

function metersFromWeight(weight, unit, kgPerMeter) {
    const kg = weightInKg(weight, unit);

    return kg > 0 && kgPerMeter > 0 ? kg / kgPerMeter : 0;
}

function weightInKg(weight, unit) {
    const value = Number(weight || 0);

    return unit === 'ton' ? value * 1000 : value;
}

function selectedProduct(products, item) {
    return products.find((product) => String(product.id) === String(item.product_id));
}

function availableCoils(coils, branchId, productId) {
    return coils.filter((coil) => String(coil.branch_id) === String(branchId) && String(coil.product_id) === String(productId));
}

function nextPreview(sequencePreviews, branchId, documentType) {
    return sequencePreviews?.[branchId]?.[documentType] ?? (documentType === 'quotation' ? 'COT-000001' : 'NV-000001');
}
