import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

const DEFAULT_ITEM = {
    product_category_id: '',
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

export default function Form({
    documentType = 'sale_note',
    branches = [],
    saleTypes = [],
    currencies = [],
    advanceOptions = [],
    units = [],
    categories = [],
    products = [],
    coils = [],
    customers = [],
    sequencePreviews = {},
    quotations = [],
}) {
    const title = documentType === 'quotation' ? 'Nueva cotizacion' : 'Nueva nota de venta';
    const permissions = usePage().props.auth.permissions;
    const decimalFormat = useDecimalFormatter('sales');
    const canOverridePrices = permissions.includes('sales.prices.override');
    const catalogsReady = products.length > 0;
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
        requires_delivery: false,
        advance_amount_input: '',
        terms: '',
        internal_notes: '',
        items: [{ ...DEFAULT_ITEM }],
    });
    const selectedAdvance = advanceOptions.find((option) => String(option.id) === String(data.advance_option_id));

    const updateItem = (index, field, value) => {
        const items = data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item));

        setData('items', field === 'display_unit_label' ? mergeDuplicateItems(items, index, products) : items);
    };

    const selectProduct = (index, value) => {
        const product = products.find((item) => String(item.id) === String(value));

        const items = data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_category_id: product?.product_category_id ?? item.product_category_id,
            product_id: value,
            product_coil_id: '',
            description: product?.name ?? item.description,
            unit_label: productUnitSymbol(product),
            display_unit_label: productUnitSymbol(product),
            item_attributes: defaultItemAttributes(product),
            quantity_mode: 'direct',
            meters: '',
            price_mode: 'meter',
            price_per_ton: '',
            unit_price: productSalePrice(product),
        } : item));

        setData('items', mergeDuplicateItems(items, index, products));
    };
    const selectItemCategory = (index, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_category_id: value,
            product_id: '',
            product_coil_id: '',
            description: '',
            display_unit_label: '',
            item_attributes: [],
            unit_price: '0',
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
            requires_delivery: false,
            advance_amount_input: quotation.advance_option?.type === 'amount' ? quotation.advance_amount ?? '' : '',
            terms: quotation.terms ?? '',
            internal_notes: `Generada desde cotizacion ${quotation.receipt_number}`,
            items: (quotation.items ?? []).map((item) => saleItemFromQuotation(item, products, canOverridePrices)),
        });
    };

    const addItem = () => setData('items', [...data.items, { ...DEFAULT_ITEM }]);
    const removeItem = (index) => setData('items', data.items.filter((_, itemIndex) => itemIndex !== index));

    const submit = (event) => {
        event.preventDefault();
        transform((payload) => ({
            ...payload,
            items: payload.items.map((item) => prepareSaleItem(item, products, decimalFormat, units)),
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
                                            {quotation.receipt_number} - {quotation.customer_name} - {quotation.branch?.name} - Bs {decimalFormat.money(quotation.total)}
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
                        {documentType === 'sale_note' ? (
                            <SelectField label="Entrega" name="requires_delivery" value={data.requires_delivery ? '1' : '0'} onChange={(event) => setData('requires_delivery', event.target.value === '1')} error={errors.requires_delivery}>
                                <option value="0">Entrega inmediata, sin despacho</option>
                                <option value="1">Requiere despacho posterior</option>
                            </SelectField>
                        ) : null}
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
                            {advanceOptions.map((option) => <option key={option.id} value={option.id}>{advanceOptionLabel(option)}</option>)}
                        </SelectField>
                        {selectedAdvance?.type === 'amount' ? (
                            <FormField label="Monto de anticipo" name="advance_amount_input" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} min="0" value={data.advance_amount_input} onChange={(event) => setData('advance_amount_input', event.target.value)} error={errors.advance_amount_input} required />
                        ) : null}
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
                                    <SelectField label="Categoria" name={`items.${index}.product_category_id`} value={item.product_category_id} onChange={(event) => selectItemCategory(index, event.target.value)}>
                                        <option value="">Todas</option>
                                        {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                                    </SelectField>
                                    <SelectField label="Producto" name={`items.${index}.product_id`} value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)} error={errors[`items.${index}.product_id`]}>
                                        <option value="">Seleccionar</option>
                                        {productsForCategory(products, item.product_category_id, data.branch_id).map((product) => <option key={product.id} value={product.id}>{product.name} ({trackingLabel(product)})</option>)}
                                    </SelectField>
                                    {documentType === 'sale_note' && product?.inventory_tracking_mode === 'coil' ? (
                                        <SelectField label="Lote/unidad fisica" name={`items.${index}.product_coil_id`} value={item.product_coil_id} onChange={(event) => updateItem(index, 'product_coil_id', event.target.value)} error={errors[`items.${index}.product_coil_id`]}>
                                            <option value="">Seleccionar</option>
                                            {availableCoils(coils, data.branch_id, item.product_id).map((coil) => (
                                                <option key={coil.id} value={coil.id}>
                                                    {coil.barcode} - {formatProductQuantity(coil.available_meters, product, decimalFormat)}
                                                </option>
                                            ))}
                                        </SelectField>
                                    ) : null}
                                    <div className="sm:col-span-2">
                                        <FormField label="Descripcion" name={`items.${index}.description`} value={item.description} onChange={(event) => updateItem(index, 'description', event.target.value)} error={errors[`items.${index}.description`]} required />
                                    </div>
                                    {item.quantity_mode === 'weight' ? (
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                            <span className="block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Cantidad</span>
                                            Se calculara automaticamente desde el peso ingresado.
                                        </div>
                                    ) : (
                                        <FormField label="Cantidad" name={`items.${index}.display_quantity`} type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKindForItem(item, product, units)))} value={item.display_quantity} onChange={(event) => updateItem(index, 'display_quantity', event.target.value)} error={errors[`items.${index}.display_quantity`]} required />
                                    )}
                                    <SelectField label="Unidad" name={`items.${index}.display_unit_label`} value={item.display_unit_label || productUnitSymbol(product)} onChange={(event) => updateItem(index, 'display_unit_label', event.target.value)} error={errors[`items.${index}.display_unit_label`]} required>
                                        {documentUnits(units, product).map((unit) => (
                                            <option key={unit.symbol} value={unit.symbol}>{unit.name} ({unit.symbol})</option>
                                        ))}
                                    </SelectField>
                                    <SelectField label="Calculo opcional" name={`items.${index}.quantity_mode`} value={item.quantity_mode ?? 'direct'} onChange={(event) => updateItem(index, 'quantity_mode', event.target.value)}>
                                        <option value="direct">Sin calculo</option>
                                        <option value="length">Cantidad x largo</option>
                                        <option value="weight">Peso a metros</option>
                                    </SelectField>
                                    {item.quantity_mode === 'weight' ? (
                                        <>
                                            <FormField label="Peso" name={`items.${index}.weight`} type="number" step={decimalStep(decimalFormat.decimalsFor('weight'))} value={item.weight} placeholder={decimalFormat.fixed(0, 'weight')} onChange={(event) => updateItem(index, 'weight', event.target.value)} />
                                            <SelectField label="Unidad" name={`items.${index}.weight_unit`} value={item.weight_unit ?? 'ton'} onChange={(event) => updateItem(index, 'weight_unit', event.target.value)}>
                                                <option value="ton">Toneladas</option>
                                                <option value="kg">Kg</option>
                                            </SelectField>
                                        </>
                                    ) : item.quantity_mode === 'length' ? (
                                        <FormField label={`Largo por unidad (${productUnitSymbol(product)})`} name={`items.${index}.meters`} type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind(product)))} value={baseQuantityFieldValue(item)} onChange={(event) => updateItem(index, 'meters', event.target.value)} error={errors[`items.${index}.meters`]} required />
                                    ) : (
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                            Se guardara {decimalFormat.format(item.display_quantity || 0, quantityKindForItem(item, product, units))} {item.display_unit_label || productUnitSymbol(product)}
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
                                    <SelectField label="Precio en" name={`items.${index}.price_mode`} value={canOverridePrices ? (item.price_mode ?? 'meter') : 'meter'} onChange={(event) => updateItem(index, 'price_mode', event.target.value)} disabled={!canOverridePrices}>
                                        <option value="meter">{item.quantity_mode === 'direct' ? 'Precio por unidad' : 'Precio por metro'}</option>
                                        <option value="ton">Precio por tonelada</option>
                                    </SelectField>
                                    {canOverridePrices && item.price_mode === 'ton' ? (
                                        <FormField label="Precio/TON (Bs.)" name={`items.${index}.price_per_ton`} type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} value={item.price_per_ton} placeholder={`Bs. ${decimalFormat.money(0)}`} onChange={(event) => updateItem(index, 'price_per_ton', event.target.value)} />
                                    ) : (
                                        <FormField
                                            label={item.quantity_mode === 'direct' ? 'Precio/unidad' : 'Precio/metro'}
                                            name={`items.${index}.unit_price`}
                                            type="number"
                                            step={decimalStep(decimalFormat.decimalsFor('cost'))}
                                            value={canOverridePrices ? item.unit_price : productSalePrice(product)}
                                            onChange={(event) => updateItem(index, 'unit_price', event.target.value)}
                                            error={errors[`items.${index}.unit_price`]}
                                            disabled={!canOverridePrices}
                                            required
                                        />
                                    )}
                                    {!canOverridePrices ? (
                                        <p className="text-xs text-slate-500 sm:col-span-6">Precio bloqueado: se usa el precio de venta configurado en Productos.</p>
                                    ) : null}
                                    <FormField label="Desc." name={`items.${index}.discount_amount`} type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} value={item.discount_amount} onChange={(event) => updateItem(index, 'discount_amount', event.target.value)} error={errors[`items.${index}.discount_amount`]} required />
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-950 sm:col-span-6">
                                        <p className="text-slate-500 dark:text-slate-400">{item.quantity_mode === 'direct' ? 'Cantidad' : 'Equivalente'}: <span className="font-semibold text-emerald-600">{item.quantity_mode === 'direct' ? formatDocumentQuantity(summary.meters, item, product, units, decimalFormat) : `${decimalFormat.measure(summary.meters)} m`}</span></p>
                                        <p className="mt-1 text-slate-500 dark:text-slate-400">Subtotal: <span className="font-semibold text-slate-950 dark:text-slate-50">Bs {decimalFormat.money(summary.total)}</span></p>
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
                        <PrimaryButton disabled={processing || !catalogsReady}>
                            {catalogsReady ? 'Guardar documento' : 'Cargando productos...'}
                        </PrimaryButton>
                        <Link href={route('sales.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function prepareSaleItem(item, products, decimalFormat, units) {
    const product = selectedProduct(products, item);
    const summary = saleItemSummary(item, product);

    return {
        product_id: item.product_id,
        product_coil_id: item.product_coil_id,
        description: item.description,
        unit_label: productUnitSymbol(product),
        display_quantity: item.quantity_mode === 'weight'
            ? (summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : '1')
            : (item.display_quantity || '1'),
        display_unit_label: item.display_unit_label || productUnitSymbol(product),
        item_attributes: normalizedItemAttributes(item, product),
        calculation_mode: item.quantity_mode || 'direct',
        meters: item.quantity_mode === 'weight'
            ? (summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : '')
            : (summary.meters ? decimalFormat.fixed(summary.meters, item.quantity_mode === 'direct' ? quantityKindForItem(item, product, units) : 'measure') : item.display_quantity),
        unit_price: item.price_mode === 'ton'
            ? (summary.unitPrice ? decimalFormat.fixed(summary.unitPrice, 'cost') : '')
            : item.unit_price,
        discount_amount: item.discount_amount || '0',
    };
}

function saleItemFromQuotation(item, products, canOverridePrices) {
    const product = products.find((entry) => String(entry.id) === String(item.product_id));
    const calculationMode = item.calculation_mode ?? 'direct';
    const unitPrice = canOverridePrices ? (item.unit_price ?? productSalePrice(product)) : productSalePrice(product);

    return {
        ...DEFAULT_ITEM,
        product_category_id: product?.product_category_id ?? '',
        product_id: item.product_id ?? '',
        product_coil_id: item.product_coil_id ?? '',
        description: item.description ?? product?.name ?? '',
        unit_label: item.unit_label ?? productUnitSymbol(product),
        display_quantity: item.display_quantity ?? item.meters ?? '1',
        display_unit_label: item.display_unit_label ?? item.unit_label ?? productUnitSymbol(product),
        item_attributes: item.item_attributes ?? defaultItemAttributes(product),
        quantity_mode: calculationMode,
        meters: calculationMode === 'length' ? storedLengthPerUnit(item) : (item.meters ?? item.display_quantity ?? '1'),
        price_mode: 'meter',
        unit_price: unitPrice,
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
    const unit = typeof attribute.unit === 'string' ? attribute.unit : attribute.unit?.symbol;
    const label = `${attribute.name}${unit ? ` (${unit})` : ''}`;

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
            type: attribute.type ?? 'text',
            unit: attributeUnit(attribute),
        });

        return { ...item, item_attributes: next };
    }));
}

function defaultItemAttributes(product) {
    return productAttributes(product).map((attribute) => ({
        code: attribute.code,
        name: attribute.name,
        value: product?.attributes?.[attribute.code] ?? attribute.value ?? '',
        type: attribute.type ?? 'text',
        unit: attributeUnit(attribute),
    }));
}

function normalizedItemAttributes(item, product) {
    const current = new Map((item.item_attributes ?? []).map((attribute) => [attribute.code, attribute]));

    return productAttributes(product).map((attribute) => {
        const currentValue = current.get(attribute.code)?.value;

        return {
            code: attribute.code,
            name: attribute.name,
            value: currentValue ?? product?.attributes?.[attribute.code] ?? attribute.value ?? '',
            type: attribute.type ?? 'text',
            unit: attributeUnit(attribute),
        };
    });
}

function productAttributes(product) {
    return (product?.custom_attributes ?? []).map((attribute) => ({
        ...attribute,
        type: ['text', 'number', 'boolean'].includes(attribute.type) ? attribute.type : 'text',
        options: [],
        is_required: false,
    })).filter((attribute, index, attributes) => (
        attribute?.code && attributes.findIndex((entry) => entry.code === attribute.code) === index
    ));
}

function attributeValue(item, attribute) {
    return (item.item_attributes ?? []).find((entry) => entry.code === attribute.code)?.value ?? '';
}

function baseQuantityFromItem(item, product) {
    const quantity = Number(item.display_quantity || 0);
    const length = Number(item.meters || 0);

    if (item.quantity_mode === 'length' && length > 0) {
        return quantity * length;
    }

    return quantity ? quantity * unitFactorToBase(item.display_unit_label, product) : Number(item.meters || 0);
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

function quantityKindForItem(item, product, units) {
    const unit = units.find((entry) => entry.symbol === (item.display_unit_label || productUnitSymbol(product)));

    return unit ? precisionKind(unit.kind) : quantityKind(product);
}

function documentUnits(units, product) {
    const productSymbol = productUnitSymbol(product);
    const conversionSymbols = (product?.unit_conversions ?? product?.unitConversions ?? [])
        .filter((conversion) => conversion.is_active !== false)
        .map((conversion) => conversion.unit?.symbol)
        .filter(Boolean);
    const allowedSymbols = conversionSymbols.length
        ? [productSymbol, ...conversionSymbols]
        : (product?.allowed_units?.length ? product.allowed_units : [productSymbol]);
    const selected = units.filter((unit) => allowedSymbols.includes(unit.symbol));
    const existing = selected.some((unit) => unit.symbol === productSymbol);
    const options = existing ? selected : [{ id: `product-${productSymbol}`, name: productSymbol, symbol: productSymbol, kind: quantityKind(product) }, ...selected];

    return options;
}

function unitFactorToBase(symbol, product) {
    const baseSymbol = productUnitSymbol(product);

    if (!symbol || symbol === baseSymbol) {
        return 1;
    }

    const conversion = (product?.unit_conversions ?? product?.unitConversions ?? [])
        .find((row) => row.is_active !== false && row.unit?.symbol === symbol);

    return Number(conversion?.factor_to_base || 1);
}

function attributeUnit(attribute) {
    return typeof attribute.unit === 'string' ? attribute.unit : attribute.unit?.symbol ?? '';
}

function mergeDuplicateItems(items, changedIndex, products) {
    const changed = items[changedIndex];

    if (!changed?.product_id) {
        return items;
    }

    const changedProduct = selectedProduct(products, changed);
    const changedUnit = changed.display_unit_label || productUnitSymbol(changedProduct);
    const duplicateIndex = items.findIndex((item, index) => {
        if (index === changedIndex) return false;

        const product = selectedProduct(products, item);

        return String(item.product_id) === String(changed.product_id)
            && String(item.display_unit_label || productUnitSymbol(product)) === String(changedUnit);
    });

    if (duplicateIndex < 0) {
        return items;
    }

    return items
        .map((item, index) => index === duplicateIndex
            ? { ...item, display_quantity: String(Number(item.display_quantity || 0) + Number(changed.display_quantity || 0)) }
            : item)
        .filter((_, index) => index !== changedIndex);
}

function precisionKind(kind) {
    return {
        cantidad: 'quantity',
        longitud: 'measure',
        medida: 'measure',
        peso: 'weight',
    }[String(kind ?? '').toLowerCase()] ?? 'quantity';
}

function formatProductQuantity(value, product, decimalFormat) {
    const unit = productUnitSymbol(product);

    return `${decimalFormat.format(value, quantityKind(product))} ${unit}`;
}

function formatDocumentQuantity(value, item, product, units, decimalFormat) {
    const unit = item.display_unit_label || productUnitSymbol(product);

    return `${decimalFormat.format(value, quantityKindForItem(item, product, units))} ${unit}`;
}

function trackingLabel(product) {
    return product?.inventory_tracking_mode === 'coil'
        ? 'Individual por lote/unidad'
        : 'Global por sucursal';
}

function productSalePrice(product) {
    return product?.sale_price ?? '0';
}

function advanceOptionLabel(option) {
    if (option.type === 'amount') {
        return `${option.name} - Bs ${Number(option.amount ?? 0).toFixed(2)}`;
    }

    return `${option.name} - ${Number(option.percentage ?? 0).toFixed(2)}%`;
}

function baseQuantityFieldValue(item) {
    return item.meters ?? '';
}

function storedLengthPerUnit(item) {
    const total = Number(item.meters || 0);
    const quantity = Number(item.display_quantity || 0);

    return total > 0 && quantity > 0 ? String(total / quantity) : '';
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

function productsForCategory(products, categoryId, branchId) {
    return products.filter((product) => (
        productAvailableForBranch(product, branchId)
        && (!categoryId || Number(product.product_category_id) === Number(categoryId))
    ));
}

function productAvailableForBranch(product, branchId) {
    if (!branchId) {
        return true;
    }

    const stocks = product.branch_stocks ?? product.branchStocks ?? [];

    return stocks.some((stock) => Number(stock.branch_id) === Number(branchId) && Boolean(stock.is_enabled));
}

function availableCoils(coils, branchId, productId) {
    return coils.filter((coil) => String(coil.branch_id) === String(branchId) && String(coil.product_id) === String(productId));
}

function nextPreview(sequencePreviews, branchId, documentType) {
    return sequencePreviews?.[branchId]?.[documentType] ?? (documentType === 'quotation' ? 'COT-000001' : 'NV-000001');
}
