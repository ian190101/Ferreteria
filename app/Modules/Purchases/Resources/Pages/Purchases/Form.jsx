import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import ProductFormFields, { buildProductFormData } from '../../../../Inventory/Resources/Pages/Inventory/Products/ProductFormFields';
import { Head, Link, useForm } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { useMemo, useState } from 'react';

const DEFAULT_ITEM = {
    product_mode: 'existing',
    product_category_id: '',
    product_id: '',
    new_product: {},
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

export default function Form({ branches = [], suppliers = [], units = [], categories = [], thicknesses = [], products = [], attributeDefinitions = [], workflow = {} }) {
    const catalogsReady = categories.length > 0 && units.length > 0;
    const decimalFormat = useDecimalFormatter('purchases');
    const [barcodeQuery, setBarcodeQuery] = useState('');
    const [barcodeMessage, setBarcodeMessage] = useState('');
    const supplierHidden = workflow?.supplierHidden;
    const supplierRequired = workflow?.supplierRequired;
    const supplierModeLabel = supplierHidden ? 'oculto' : (supplierRequired ? 'obligatorio' : 'opcional');
    const { data, setData, post, processing, errors, transform } = useForm({
        branch_id: branches[0]?.id ?? '',
        supplier_id: '',
        document_number: '',
        purchase_date: new Date().toISOString().slice(0, 10),
        status: 'received',
        items: [newDefaultItem(categories, units, branches)],
    });

    const productMap = useMemo(() => new Map(products.map((product) => [String(product.id), product])), [products]);
    const updateItem = (index, field, value) => {
        const items = data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item));

        setData('items', field === 'display_unit_label' ? mergeDuplicateItems(items, index, productMap) : items);
    };
    const selectProduct = (index, value) => {
        const product = productMap.get(String(value));

        const items = data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_category_id: product?.product_category_id ?? item.product_category_id,
            product_id: value,
            display_unit_label: productUnitSymbol(product),
            item_attributes: defaultItemAttributes(product),
            calculation_mode: 'direct',
            meters: '',
        } : item));

        setData('items', mergeDuplicateItems(items, index, productMap));
    };
    const selectItemCategory = (index, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? {
            ...item,
            product_category_id: value,
            product_id: '',
            display_unit_label: '',
            item_attributes: [],
            meters: '',
            unit_cost: '0',
            lot_number: '',
            coil_barcode: '',
            description: '',
        } : item)));
    };
    const setProductMode = (index, mode) => {
        if (mode === 'new' && workflow?.allowCreateProduct === false) {
            return;
        }

        setData('items', data.items.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            return {
                ...item,
                product_mode: mode,
                product_id: mode === 'new' ? '' : item.product_id,
                display_unit_label: mode === 'new' ? (item.new_product?.base_unit ?? item.display_unit_label) : item.display_unit_label,
                new_product: item.new_product?.name ? item.new_product : buildProductFormData({ categories, units, branches }),
            };
        }));
    };
    const setNewProductData = (index, fieldOrData, value) => {
        setData('items', data.items.map((item, itemIndex) => {
            if (itemIndex !== index) {
                return item;
            }

            const currentProduct = item.new_product ?? {};
            const nextProduct = typeof fieldOrData === 'string'
                ? {
                    ...currentProduct,
                    [fieldOrData]: typeof value === 'function' ? value(currentProduct[fieldOrData]) : value,
                }
                : fieldOrData;

            return {
                ...item,
                product_category_id: nextProduct.product_category_id ?? item.product_category_id,
                display_unit_label: nextProduct.base_unit ?? item.display_unit_label,
                description: item.description || nextProduct.name || '',
                new_product: nextProduct,
            };
        }));
    };
    const addItem = () => setData('items', [...data.items, newDefaultItem(categories, units, branches)]);
    const removeItem = (index) => setData('items', data.items.filter((_, itemIndex) => itemIndex !== index));
    const addProductByBarcode = () => {
        const code = barcodeQuery.trim();

        if (!code) {
            setBarcodeMessage('Ingresa o escanea un codigo de barras.');

            return;
        }

        const product = products.find((entry) => String(entry.barcode ?? '').trim() === code || String(entry.sku ?? '').trim() === code);

        if (!product) {
            setBarcodeMessage(`No se encontro ningun producto con el codigo ${code}.`);

            return;
        }

        if (!productAvailableForBranch(product, data.branch_id)) {
            setBarcodeMessage(`El producto ${product.name} no esta habilitado para la sucursal seleccionada.`);

            return;
        }

        setData('items', addOrIncrementProductItem(data.items, product, units, categories, branches));
        setBarcodeQuery('');
        setBarcodeMessage(`${product.name} agregado a la compra.`);
    };
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
            items: payload.items.map((item) => preparePurchaseItem(item, products, decimalFormat, units, categories, thicknesses)),
        }));
        post(route('purchases.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title="Nueva compra" />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Nueva compra" description="Ingresa la cantidad en la unidad real del producto. Usa calculo por largo o peso solo cuando corresponda." />

                <div className="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    Perfil activo de compras: {workflow.barcodeEntryEnabled ? 'entrada rapida por barcode habilitada' : 'compra tradicional'}; proveedor {supplierModeLabel}; {workflow.allowCreateProduct ? 'puedes crear productos desde compras' : 'solo productos ya registrados'}; {workflow.productPolicy?.unitEquivalencesEnabled === false ? 'sin equivalencias de unidades' : 'con equivalencias de unidades'}.
                </div>

                <form onSubmit={submit} className="space-y-6">
                    {workflow.barcodeEntryEnabled ? (
                        <section className="rounded-lg border border-brand-primary/25 bg-brand-primary/5 p-5 shadow-sm dark:border-brand-primary/30 dark:bg-brand-primary/10">
                            <div className="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                                <FormField
                                    label="Entrada rapida por codigo"
                                    name="purchase_barcode"
                                    value={barcodeQuery}
                                    onChange={(event) => setBarcodeQuery(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            addProductByBarcode();
                                        }
                                    }}
                                    placeholder="Escanea barcode o escribe SKU"
                                />
                                <button
                                    type="button"
                                    onClick={addProductByBarcode}
                                    className="rounded-md bg-brand-primary px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
                                >
                                    Agregar por codigo
                                </button>
                            </div>
                            <p className="mt-2 text-xs text-slate-600 dark:text-slate-300">
                                El lector puede enviar Enter automaticamente. Si el producto ya esta en la compra con la misma unidad, se suma una unidad.
                            </p>
                            {barcodeMessage ? <p className="mt-2 text-sm font-medium text-slate-800 dark:text-slate-100">{barcodeMessage}</p> : null}
                        </section>
                    ) : null}

                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                        <FormField label="Documento" name="document_number" value={data.document_number} onChange={(event) => setData('document_number', event.target.value)} error={errors.document_number} required />
                        <SelectField label="Sucursal" name="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value)} error={errors.branch_id} helpTitle="Sucursal de ingreso" helpTooltip="Todo stock que ingrese por esta compra quedara disponible en esta sucursal, salvo que la compra quede como borrador." helpText="La sucursal seleccionada recibira el stock cuando la compra quede como recibida.">
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        {!supplierHidden ? (
                            <SelectField label={supplierRequired ? 'Proveedor' : 'Proveedor opcional'} name="supplier_id" value={data.supplier_id} onChange={(event) => setData('supplier_id', event.target.value)} error={errors.supplier_id} required={supplierRequired}>
                                <option value="">{supplierRequired ? 'Seleccionar proveedor' : 'Sin proveedor'}</option>
                                {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                            </SelectField>
                        ) : (
                            <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                El perfil activo oculta proveedores para compras rapidas.
                            </div>
                        )}
                        <FormField label="Fecha" name="purchase_date" type="date" value={data.purchase_date} onChange={(event) => setData('purchase_date', event.target.value)} error={errors.purchase_date} required />
                        <SelectField label="Estado" name="status" value={data.status} onChange={(event) => setData('status', event.target.value)} error={errors.status} helpTitle="Estado de compra" helpTooltip="Recibida aumenta stock y registra movimiento de inventario. Borrador sirve para guardar la compra sin afectar cantidades." helpText="Recibida mueve inventario inmediatamente. Borrador guarda la compra sin aumentar stock.">
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
                                const product = item.product_mode === 'new'
                                    ? draftProductFromItem(item, categories, units, thicknesses)
                                    : productMap.get(String(item.product_id));
                                const isCoil = product?.inventory_tracking_mode === 'coil';
                                const summary = purchaseItemSummary(item, product);

                                return (
                                    <div key={index} className="grid gap-3 border-t border-slate-100 pt-5 dark:border-slate-800 sm:grid-cols-7">
                                        <SelectField label="Tipo de item" name={`items.${index}.product_mode`} value={item.product_mode ?? 'existing'} onChange={(event) => setProductMode(index, event.target.value)} helpTitle="Producto de compra" helpTooltip="Si el producto ya existe, seleccionalo para mantener historial y stock. Si no existe, crea el producto desde aqui sin abrir otra pantalla." helpText="Usa producto existente si ya esta creado. Usa producto nuevo si llego mercaderia que aun no existe en inventario.">
                                            <option value="existing">Producto existente</option>
                                            {workflow?.allowCreateProduct === false ? null : <option value="new">Producto nuevo</option>}
                                        </SelectField>
                                        {item.product_mode === 'new' ? (
                                            <section className="rounded-2xl border border-brand-primary/20 bg-brand-primary/5 p-4 dark:border-brand-primary/30 dark:bg-brand-primary/10 sm:col-span-7">
                                                <div className="mb-4">
                                                    <h4 className="text-sm font-semibold text-slate-950 dark:text-white">Datos del producto nuevo</h4>
                                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                        Es el mismo formulario de Nuevo producto, integrado aqui para registrar compra e inventario en una sola accion.
                                                    </p>
                                                </div>
                                                <ProductFormFields
                                                    data={item.new_product}
                                                    setData={(fieldOrData, value) => setNewProductData(index, fieldOrData, value)}
                                                    errors={nestedErrors(errors, `items.${index}.new_product.`)}
                                                    thicknesses={thicknesses}
                                                    categories={categories}
                                                    units={units}
                                                    branches={branches}
                                                    attributeDefinitions={attributeDefinitions}
                                                    decimalFormat={decimalFormat}
                                                    productPolicy={workflow.productPolicy}
                                                    compact
                                                />
                                            </section>
                                        ) : (
                                            <>
                                                <SelectField label="Categoria" name={`items.${index}.product_category_id`} value={item.product_category_id} onChange={(event) => selectItemCategory(index, event.target.value)}>
                                                    <option value="">Todas</option>
                                                    {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                                                </SelectField>
                                                <SelectField label="Producto" name={`items.${index}.product_id`} value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)} error={errors[`items.${index}.product_id`]}>
                                                    <option value="">Seleccionar</option>
                                                    {productsForCategory(products, item.product_category_id, data.branch_id).map((product) => <option key={product.id} value={product.id}>{product.name} ({product.sku}) - {trackingLabel(product)}</option>)}
                                                </SelectField>
                                            </>
                                        )}
                                        {item.calculation_mode === 'weight' ? (
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
                                        <SelectField label="Calculo opcional" name={`items.${index}.calculation_mode`} value={item.calculation_mode ?? 'direct'} onChange={(event) => updateItem(index, 'calculation_mode', event.target.value)} helpTitle="Calculo de ingreso" helpTooltip="Usa calculo solo cuando el documento de compra viene en otra unidad. Ejemplo: toneladas compradas que deben convertirse a metros por espesor." helpText="Sin calculo ingresa cantidad directa. Cantidad x largo registra piezas por medida. Peso a metros convierte kg o toneladas segun el espesor.">
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
                                            <FormField label={`Largo por unidad (${productUnitSymbol(product)})`} name={`items.${index}.meters`} type="number" step={decimalStep(decimalFormat.decimalsFor(quantityKind(product)))} value={baseQuantityFieldValue(item)} placeholder={convertedMeters(item)} onChange={(event) => updateItem(index, 'meters', event.target.value)} error={errors[`items.${index}.meters`]} />
                                        ) : (
                                            <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300">
                                                Se guardara {decimalFormat.format(item.display_quantity || 0, quantityKindForItem(item, product, units))} {item.display_unit_label || productUnitSymbol(product)}
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
                                                <p className="text-slate-500 dark:text-slate-400">{item.calculation_mode === 'direct' ? 'Cantidad' : 'Equivalente'}: <span className="font-semibold text-emerald-600">{item.calculation_mode === 'direct' ? formatDocumentQuantity(summary.meters, item, product, units, decimalFormat) : `${decimalFormat.measure(summary.meters)} m`}</span></p>
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
                            {catalogsReady ? 'Registrar compra' : 'Cargando catalogos...'}
                        </PrimaryButton>
                        <Link href={route('purchases.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function preparePurchaseItem(item, products, decimalFormat, units, categories, thicknesses) {
    const product = products.find((product) => String(product.id) === String(item.product_id))
        ?? draftProductFromItem(item, categories, units, thicknesses);
    const summary = purchaseItemSummary(item, product);

    return {
        product_id: item.product_mode === 'new' ? '' : item.product_id,
        new_product: item.product_mode === 'new' ? {
            ...item.new_product,
            purchase_price: summary.unitCost ? decimalFormat.fixed(summary.unitCost, 'cost') : item.new_product?.purchase_price,
        } : undefined,
        display_quantity: item.calculation_mode === 'weight'
            ? (summary.meters ? decimalFormat.fixed(summary.meters, 'measure') : '1')
            : (item.display_quantity || '1'),
        display_unit_label: item.display_unit_label || productUnitSymbol(product),
        calculation_mode: item.calculation_mode || 'direct',
        item_attributes: normalizedItemAttributes(item, product),
        weight_unit: item.weight_unit,
        kilograms: item.calculation_mode === 'weight' ? item.kilograms : '',
        meters: summary.meters ? decimalFormat.fixed(summary.meters, item.calculation_mode === 'direct' ? quantityKindForItem(item, product, units) : 'measure') : item.meters,
        unit_cost: summary.unitCost ? decimalFormat.fixed(summary.unitCost, 'cost') : item.unit_cost,
        lot_number: item.lot_number,
        coil_barcode: item.coil_barcode,
        description: item.description,
    };
}

function newDefaultItem(categories = [], units = [], branches = []) {
    return {
        ...DEFAULT_ITEM,
        new_product: buildProductFormData({ categories, units, branches }),
        item_attributes: [],
    };
}

function addOrIncrementProductItem(items, product, units = [], categories = [], branches = []) {
    const unit = productUnitSymbol(product);
    const duplicateIndex = items.findIndex((item) => (
        item.product_mode !== 'new'
        && String(item.product_id) === String(product.id)
        && String(item.display_unit_label || unit) === String(unit)
        && (item.calculation_mode ?? 'direct') === 'direct'
    ));

    if (duplicateIndex >= 0) {
        return items.map((item, index) => index === duplicateIndex
            ? { ...item, display_quantity: String(Number(item.display_quantity || 0) + 1) }
            : item);
    }

    const nextItem = {
        ...newDefaultItem(categories, units, branches),
        product_mode: 'existing',
        product_category_id: product.product_category_id ?? '',
        product_id: product.id,
        display_quantity: '1',
        display_unit_label: unit,
        calculation_mode: 'direct',
        item_attributes: defaultItemAttributes(product),
        meters: '',
        unit_cost: String(product.purchase_price ?? 0),
        description: product.name ?? '',
    };

    const emptyIndex = items.findIndex((item) => (
        item.product_mode !== 'new'
        && !item.product_id
        && !item.description
        && Number(item.display_quantity || 1) === 1
    ));

    if (emptyIndex >= 0) {
        return items.map((item, index) => (index === emptyIndex ? nextItem : item));
    }

    return [...items, nextItem];
}

function nestedErrors(errors, prefix) {
    return Object.fromEntries(
        Object.entries(errors ?? {})
            .filter(([key]) => key.startsWith(prefix))
            .map(([key, value]) => [key.slice(prefix.length), value])
    );
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

    if (item.calculation_mode === 'length' && length > 0) {
        return quantity * length;
    }

    return quantity ? quantity * unitFactorToBase(item.display_unit_label, product) : Number(item.meters || 0);
}

function baseQuantityFieldValue(item) {
    return item.meters ?? '';
}

function productUnitSymbol(product) {
    return product?.unit?.symbol ?? product?.base_unit ?? 'unidad';
}

function draftProductFromItem(item, categories = [], units = [], thicknesses = []) {
    const payload = item.new_product ?? {};
    const category = categories.find((entry) => Number(entry.id) === Number(payload.product_category_id || item.product_category_id));
    const defaultUnitId = category?.default_unit_id ?? category?.defaultUnit?.id ?? category?.default_unit?.id;
    const unit = units.find((entry) => Number(entry.id) === Number(payload.product_unit_id || defaultUnitId));
    const thickness = thicknesses.find((entry) => Number(entry.id) === Number(payload.thickness_id));
    const symbol = unit?.symbol ?? item.display_unit_label ?? 'unidad';

    return {
        id: null,
        product_category_id: payload.product_category_id || item.product_category_id,
        product_unit_id: unit?.id ?? null,
        name: payload.name ?? '',
        sku: payload.sku ?? '',
        inventory_tracking_mode: payload.inventory_tracking_mode ?? category?.default_tracking_mode ?? 'global',
        base_unit: symbol,
        allowed_units: [symbol],
        unit,
        thickness,
        custom_attributes: [],
        attributes: {},
    };
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

    return existing ? selected : [{ id: `product-${productSymbol}`, name: productSymbol, symbol: productSymbol, kind: quantityKind(product) }, ...selected];
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

function mergeDuplicateItems(items, changedIndex, productMap) {
    const changed = items[changedIndex];

    if (!changed?.product_id) {
        return items;
    }

    const changedProduct = productMap.get(String(changed.product_id));
    const changedUnit = changed.display_unit_label || productUnitSymbol(changedProduct);
    const duplicateIndex = items.findIndex((item, index) => {
        if (index === changedIndex) return false;

        const product = productMap.get(String(item.product_id));

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
        ? 'Stock por sucursal + lote/unidad'
        : 'Stock por sucursal';
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

function weightInKg(weight, unit) {
    const value = Number(weight || 0);

    return unit === 'ton' ? value * 1000 : value;
}
