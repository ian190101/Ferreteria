import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

export default function Form({ product, thicknesses, categories, units, branches = [] }) {
    const isEditing = Boolean(product);
    const decimalFormat = useDecimalFormatter('inventory');
    const initialBranchIds = (product?.branch_stocks ?? product?.branchStocks ?? [])
        .filter((stock) => stock.is_enabled)
        .map((stock) => Number(stock.branch_id));
    const isGlobal = !isEditing || (branches.length > 0 && branches.every((branch) => initialBranchIds.includes(Number(branch.id))));
    const firstCategory = categories[0] ?? null;
    const initialCategory = product?.product_category_id
        ? categories.find((category) => Number(category.id) === Number(product.product_category_id))
        : firstCategory;
    const initialUnit = product?.product_unit_id
        ? units.find((unit) => Number(unit.id) === Number(product.product_unit_id))
        : units.find((unit) => Number(unit.id) === Number(initialCategory?.default_unit_id));
    const { data, setData, post, put, processing, errors } = useForm({
        thickness_id: product?.thickness_id ?? '',
        name: product?.name ?? '',
        product_category_id: product?.product_category_id ?? initialCategory?.id ?? '',
        product_unit_id: product?.product_unit_id ?? initialUnit?.id ?? '',
        category: product?.category ?? initialCategory?.name ?? 'Ferreteria general',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        inventory_tracking_mode: product?.inventory_tracking_mode ?? initialCategory?.default_tracking_mode ?? 'global',
        base_unit: product?.base_unit ?? initialUnit?.symbol ?? 'unidad',
        attributes: product?.attributes ?? {},
        custom_attributes: normalizeCustomAttributes(product?.custom_attributes ?? []),
        allowed_units: normalizeAllowedUnits(product?.allowed_units, initialUnit),
        unit_conversions: normalizeUnitConversions(product?.unit_conversions ?? product?.unitConversions ?? []),
        purchase_price: product?.purchase_price ?? '0',
        sale_price: product?.sale_price ?? '0',
        minimum_stock_meters: product?.minimum_stock_meters ?? '0',
        is_active: product?.is_active ?? true,
        branch_scope: isGlobal ? 'global' : 'specific',
        branch_ids: isGlobal ? branches.map((branch) => Number(branch.id)) : initialBranchIds,
    });
    const selectedCategory = categories.find((category) => Number(category.id) === Number(data.product_category_id));
    const selectedUnit = units.find((unit) => Number(unit.id) === Number(data.product_unit_id));
    const profit = Math.max(Number(data.sale_price || 0) - Number(data.purchase_price || 0), 0);

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('inventory.products.update', product.id), { preserveScroll: true });
            return;
        }

        post(route('inventory.products.store'), { preserveScroll: true });
    };

    const generateSku = () => {
        const base = normalizeCode(data.name || 'PRODUCTO').slice(0, 24) || 'PRODUCTO';

        setData('sku', `${base}-${timestampCode()}`);
    };

    const generateBarcode = () => {
        setData('barcode', `779${timestampCode()}${Math.floor(100 + Math.random() * 900)}`);
    };

    const selectCategory = (categoryId) => {
        const category = categories.find((item) => Number(item.id) === Number(categoryId));
        const unit = units.find((item) => Number(item.id) === Number(category?.default_unit_id));

        setData({
            ...data,
            product_category_id: categoryId,
            product_unit_id: unit?.id ?? data.product_unit_id,
            category: category?.name ?? data.category,
            base_unit: unit?.symbol ?? data.base_unit,
            allowed_units: normalizeAllowedUnits(data.allowed_units, unit),
            inventory_tracking_mode: category?.default_tracking_mode ?? data.inventory_tracking_mode,
            thickness_id: category?.requires_thickness ? data.thickness_id : '',
        });
    };

    const selectUnit = (unitId) => {
        const unit = units.find((item) => Number(item.id) === Number(unitId));

        setData({
            ...data,
            product_unit_id: unitId,
            base_unit: unit?.symbol ?? data.base_unit,
            allowed_units: normalizeAllowedUnits(data.allowed_units, unit),
            unit_conversions: (data.unit_conversions ?? []).filter((row) => Number(row.product_unit_id) !== Number(unitId)),
        });
    };

    const addCustomAttribute = () => setData('custom_attributes', [
        ...(data.custom_attributes ?? []),
        { code: '', name: '', type: 'text', value: '', has_unit: false, unit: '' },
    ]);
    const updateCustomAttribute = (index, field, value) => {
        setData('custom_attributes', (data.custom_attributes ?? []).map((attribute, attributeIndex) => (
            attributeIndex === index ? { ...attribute, [field]: value } : attribute
        )));
    };
    const removeCustomAttribute = (index) => {
        setData('custom_attributes', (data.custom_attributes ?? []).filter((_, attributeIndex) => attributeIndex !== index));
    };
    const setBranchScope = (scope) => {
        setData({
            ...data,
            branch_scope: scope,
            branch_ids: scope === 'global' ? branches.map((branch) => Number(branch.id)) : data.branch_ids,
        });
    };
    const toggleBranch = (branchId) => {
        const id = Number(branchId);
        const current = (data.branch_ids ?? []).map((value) => Number(value));

        setData('branch_ids', current.includes(id)
            ? current.filter((value) => value !== id)
            : [...current, id]);
    };
    const toggleAllowedUnit = (symbol) => {
        const baseSymbol = selectedUnit?.symbol ?? data.base_unit;

        if (symbol === baseSymbol) {
            return;
        }

        const current = new Set(data.allowed_units ?? []);

        if (current.has(symbol)) {
            current.delete(symbol);
        } else {
            current.add(symbol);
        }

        setData('allowed_units', normalizeAllowedUnits([...current], selectedUnit));
    };
    const addUnitConversion = () => setData('unit_conversions', [
        ...(data.unit_conversions ?? []),
        { product_unit_id: '', factor_to_base: '1', is_active: true },
    ]);
    const updateUnitConversion = (index, field, value) => {
        setData('unit_conversions', (data.unit_conversions ?? []).map((row, rowIndex) => (
            rowIndex === index ? { ...row, [field]: value } : row
        )));
    };
    const removeUnitConversion = (index) => {
        setData('unit_conversions', (data.unit_conversions ?? []).filter((_, rowIndex) => rowIndex !== index));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title={isEditing ? 'Editar producto' : 'Nuevo producto'} />

            <section className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title={isEditing ? 'Editar producto' : 'Nuevo producto'}
                    description="Catalogo general para ferreteria: calaminas, herramientas, pinturas, tornilleria, cajas, paquetes y otros productos."
                />

                <form onSubmit={submit} className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                    <SelectField label="Categoria" name="product_category_id" value={data.product_category_id} onChange={(event) => selectCategory(event.target.value)} error={errors.product_category_id} required>
                        <option value="">Seleccione una categoria</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </SelectField>
                    <GeneratedField
                        label="SKU"
                        name="sku"
                        value={data.sku}
                        onChange={(event) => setData('sku', event.target.value)}
                        error={errors.sku}
                        onGenerate={generateSku}
                    />
                    <GeneratedField
                        label="Barcode"
                        name="barcode"
                        value={data.barcode}
                        onChange={(event) => setData('barcode', event.target.value)}
                        error={errors.barcode}
                        onGenerate={generateBarcode}
                    />
                    <SelectField label="Espesor" name="thickness_id" value={data.thickness_id} onChange={(event) => setData('thickness_id', event.target.value)} error={errors.thickness_id}>
                        <option value="">{selectedCategory?.requires_thickness ? 'Seleccione espesor' : 'Sin espesor'}</option>
                        {thicknesses.map((thickness) => (
                            <option key={thickness.id} value={thickness.id}>
                                {thickness.name}
                            </option>
                        ))}
                    </SelectField>
                    <div className="sm:col-span-2">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Control de inventario</h3>
                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        Todos los productos se controlan por sucursal automaticamente. Activa el rastreo por lote/unidad fisica solo cuando necesites identificar una caja, rollo, lote, vencimiento o pieza especifica.
                                    </p>
                                </div>
                                <details className="group relative">
                                    <summary className="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-full border border-brand-primary text-sm font-bold text-brand-primary transition hover:bg-brand-primary hover:text-white" title="Ayuda sobre rastreo de inventario">
                                        ?
                                    </summary>
                                    <div className="absolute right-0 z-20 mt-2 w-80 rounded-lg border border-slate-200 bg-white p-4 text-xs leading-relaxed text-slate-600 shadow-xl dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                                        <p className="font-semibold text-slate-900 dark:text-white">Como funciona</p>
                                        <p className="mt-2"><strong>Stock por sucursal:</strong> siempre esta activo. Sirve para saber cuanto stock hay en cada tienda o almacen.</p>
                                        <p className="mt-2"><strong>Rastreo por lote/unidad fisica:</strong> es adicional. Usalo para productos con vencimiento, lotes, rollos, bobinas, cables por metro, mangueras o unidades fisicas que se venden por partes.</p>
                                        <p className="mt-2">No lo actives para productos simples como focos, cascos o guantes si solo necesitas saber la cantidad disponible por sucursal.</p>
                                    </div>
                                </details>
                            </div>
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                <div className="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                                    <p className="font-semibold">Stock por sucursal</p>
                                    <p className="mt-1 text-xs">Siempre activo para este producto.</p>
                                </div>
                                <SelectField
                                    label="Rastreo adicional"
                                    name="inventory_tracking_mode"
                                    value={data.inventory_tracking_mode}
                                    onChange={(event) => setData('inventory_tracking_mode', event.target.value)}
                                    error={errors.inventory_tracking_mode}
                                >
                                    <option value="global">No, solo stock por sucursal</option>
                                    <option value="coil">Si, tambien por lote/unidad fisica</option>
                                </SelectField>
                            </div>
                            {data.inventory_tracking_mode === 'coil' ? (
                                <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                                    Al vender o recibir este producto, el sistema pedira seleccionar o registrar el lote/unidad fisica correspondiente.
                                </p>
                            ) : (
                                <p className="mt-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                                    Recomendado para productos simples: focos, cascos, guantes, herramientas comunes y articulos sin vencimiento ni rollos.
                                </p>
                            )}
                        </div>
                    </div>
                    <SelectField label="Unidad base" name="product_unit_id" value={data.product_unit_id} onChange={(event) => selectUnit(event.target.value)} error={errors.product_unit_id} required>
                        <option value="">Seleccione unidad</option>
                        {units.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name} ({unit.symbol})
                            </option>
                        ))}
                    </SelectField>
                    <div className="sm:col-span-2">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Unidades para venta y compra</h3>
                            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">La unidad base siempre queda habilitada. Agrega otras formas comerciales como caja, unidad, kg, bolsa o paquete.</p>
                            <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                {units.map((unit) => {
                                    const isBase = unit.symbol === (selectedUnit?.symbol ?? data.base_unit);
                                    const checked = (data.allowed_units ?? []).includes(unit.symbol) || isBase;

                                    return (
                                        <label key={unit.id} className="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                disabled={isBase}
                                                onChange={() => toggleAllowedUnit(unit.symbol)}
                                                className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary disabled:opacity-60"
                                            />
                                            <span>{unit.name} ({unit.symbol}){isBase ? ' - base' : ''}</span>
                                        </label>
                                    );
                                })}
                            </div>
                            {errors.allowed_units ? <p className="mt-2 text-sm text-red-600">{errors.allowed_units}</p> : null}
                        </div>
                    </div>
                    <div className="sm:col-span-2">
                        <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Equivalencias de unidades</h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">Define cuanto descuenta del stock base cada unidad comercial. Ejemplo: 1 caja = 12 {selectedUnit?.symbol ?? data.base_unit}.</p>
                                </div>
                                <button type="button" onClick={addUnitConversion} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                                    Agregar equivalencia
                                </button>
                            </div>
                            {(data.unit_conversions ?? []).length ? (
                                <div className="mt-4 space-y-3">
                                    {data.unit_conversions.map((row, index) => (
                                        <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-[1fr_1fr_auto]">
                                            <SelectField label="Unidad comercial" name={`unit_conversions.${index}.product_unit_id`} value={row.product_unit_id ?? ''} onChange={(event) => updateUnitConversion(index, 'product_unit_id', event.target.value)} error={errors[`unit_conversions.${index}.product_unit_id`]}>
                                                <option value="">Seleccione unidad</option>
                                                {units
                                                    .filter((unit) => Number(unit.id) !== Number(data.product_unit_id))
                                                    .map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}
                                            </SelectField>
                                            <FormField label={`Equivale a (${selectedUnit?.symbol ?? data.base_unit})`} name={`unit_conversions.${index}.factor_to_base`} type="number" step="0.000001" min="0.000001" value={row.factor_to_base ?? '1'} onChange={(event) => updateUnitConversion(index, 'factor_to_base', event.target.value)} error={errors[`unit_conversions.${index}.factor_to_base`]} />
                                            <button type="button" onClick={() => removeUnitConversion(index)} className="self-end rounded-md border border-red-200 px-3 py-2 text-sm text-red-600 dark:border-red-900/60">
                                                Quitar
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    Sin equivalencias adicionales. La venta o compra en unidad base descuenta 1 a 1.
                                </p>
                            )}
                        </div>
                    </div>
                    <FormField label="Precio compra" name="purchase_price" type="number" step={decimalStep(decimalFormat.decimalsFor('cost'))} min="0" value={data.purchase_price} onChange={(event) => setData('purchase_price', event.target.value)} error={errors.purchase_price} required />
                    <FormField label="Precio venta" name="sale_price" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} min="0" value={data.sale_price} onChange={(event) => setData('sale_price', event.target.value)} error={errors.sale_price} required />
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100">
                        <p className="text-xs font-semibold uppercase tracking-wide">Ganancia estimada</p>
                        <p className="mt-1 text-xl font-bold">Bs {decimalFormat.money(profit)}</p>
                        <p className="mt-1 text-xs">Por {selectedUnit?.symbol ?? data.base_unit ?? 'unidad'} antes de descuentos.</p>
                    </div>
                    <FormField
                        label={`Stock minimo (${unitLabel(data.base_unit)})`}
                        name="minimum_stock_meters"
                        type="number"
                        step={decimalStep(decimalFormat.decimalsFor(data.base_unit === 'm' ? 'measure' : data.base_unit === 'kg' ? 'weight' : 'quantity'))}
                        value={data.minimum_stock_meters}
                        onChange={(event) => setData('minimum_stock_meters', event.target.value)}
                        error={errors.minimum_stock_meters}
                        required
                    />
                    <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </SelectField>

                    <div className="sm:col-span-2">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <div className="flex flex-col gap-1">
                                <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Disponibilidad por sucursal</h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Define en que sucursales se podra comprar, vender, ajustar o reservar este producto. No borra stock existente, solo habilita o deshabilita su uso.</p>
                            </div>
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                                    <input type="radio" name="branch_scope" value="global" checked={data.branch_scope === 'global'} onChange={() => setBranchScope('global')} className="h-4 w-4 text-brand-primary focus:ring-brand-primary" />
                                    <span>
                                        <span className="block font-semibold text-slate-900 dark:text-slate-100">Todas las sucursales permitidas</span>
                                        <span className="text-xs text-slate-500">Global para el alcance del usuario.</span>
                                    </span>
                                </label>
                                <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                                    <input type="radio" name="branch_scope" value="specific" checked={data.branch_scope === 'specific'} onChange={() => setBranchScope('specific')} className="h-4 w-4 text-brand-primary focus:ring-brand-primary" />
                                    <span>
                                        <span className="block font-semibold text-slate-900 dark:text-slate-100">Solo sucursales seleccionadas</span>
                                        <span className="text-xs text-slate-500">Elige una o varias sucursales.</span>
                                    </span>
                                </label>
                            </div>
                            {data.branch_scope === 'specific' ? (
                                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                                    {branches.map((branch) => (
                                        <label key={branch.id} className="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={(data.branch_ids ?? []).map((id) => Number(id)).includes(Number(branch.id))}
                                                onChange={() => toggleBranch(branch.id)}
                                                className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary"
                                            />
                                            <span>{branch.name}</span>
                                        </label>
                                    ))}
                                </div>
                            ) : null}
                            {errors.branch_ids ? <p className="mt-2 text-sm text-red-600">{errors.branch_ids}</p> : null}
                            {errors.branch_scope ? <p className="mt-2 text-sm text-red-600">{errors.branch_scope}</p> : null}
                        </div>
                    </div>

                    <div className="sm:col-span-2">
                        <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Caracteristicas propias del producto</h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">Se aplican solo a este producto y aparecen en ventas, cotizaciones y compras.</p>
                                </div>
                                <button type="button" onClick={addCustomAttribute} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                                    Agregar caracteristica
                                </button>
                            </div>

                            {(data.custom_attributes ?? []).length ? (
                                <div className="mt-4 space-y-3">
                                    {data.custom_attributes.map((attribute, index) => (
                                        <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-6">
                                            <FormField label="Nombre" name={`custom_attributes.${index}.name`} value={attribute.name ?? ''} onChange={(event) => updateCustomAttribute(index, 'name', event.target.value)} error={errors[`custom_attributes.${index}.name`]} />
                                            <FormField label="Codigo" name={`custom_attributes.${index}.code`} value={attribute.code ?? ''} onChange={(event) => updateCustomAttribute(index, 'code', event.target.value)} error={errors[`custom_attributes.${index}.code`]} placeholder="Automatico si se deja vacio" />
                                            <SelectField label="Tipo" name={`custom_attributes.${index}.type`} value={attribute.type ?? 'text'} onChange={(event) => updateCustomAttribute(index, 'type', event.target.value)} error={errors[`custom_attributes.${index}.type`]}>
                                                <option value="text">Texto</option>
                                                <option value="number">Numerico</option>
                                                <option value="boolean">Si/No</option>
                                            </SelectField>
                                            {attribute.type === 'boolean' ? (
                                                <SelectField label="Valor" name={`custom_attributes.${index}.value`} value={String(attribute.value ?? '')} onChange={(event) => updateCustomAttribute(index, 'value', event.target.value)} error={errors[`custom_attributes.${index}.value`]}>
                                                    <option value="">Sin definir</option>
                                                    <option value="1">Si</option>
                                                    <option value="0">No</option>
                                                </SelectField>
                                            ) : (
                                                <FormField label="Valor" name={`custom_attributes.${index}.value`} type={attribute.type === 'number' ? 'number' : 'text'} step={attribute.type === 'number' ? '0.01' : undefined} value={attribute.value ?? ''} onChange={(event) => updateCustomAttribute(index, 'value', event.target.value)} error={errors[`custom_attributes.${index}.value`]} />
                                            )}
                                            <SelectField label="Usa unidad" name={`custom_attributes.${index}.has_unit`} value={attribute.has_unit ? '1' : '0'} onChange={(event) => updateCustomAttribute(index, 'has_unit', event.target.value === '1')} error={errors[`custom_attributes.${index}.has_unit`]}>
                                                <option value="0">No</option>
                                                <option value="1">Si</option>
                                            </SelectField>
                                            <div className="flex gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <SelectField label="Unidad" name={`custom_attributes.${index}.unit`} value={attribute.unit ?? ''} onChange={(event) => updateCustomAttribute(index, 'unit', event.target.value)} error={errors[`custom_attributes.${index}.unit`]} disabled={!attribute.has_unit}>
                                                        <option value="">Sin unidad</option>
                                                        {units.map((unit) => <option key={unit.id} value={unit.symbol}>{unit.name} ({unit.symbol})</option>)}
                                                    </SelectField>
                                                </div>
                                                <button type="button" onClick={() => removeCustomAttribute(index)} className="self-end rounded-md border border-red-200 px-3 py-2 text-sm text-red-600 dark:border-red-900/60">
                                                    Quitar
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    No hay caracteristicas propias agregadas para este producto.
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-3 sm:col-span-2">
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

function unitLabel(unit) {
    return {
        m: 'metros',
        unidad: 'unidades',
        caja: 'cajas',
        paquete: 'paquetes',
        kg: 'kg',
        ton: 'toneladas',
        lt: 'litros',
        galon: 'galones',
        rollo: 'rollos',
    }[unit] ?? unit;
}

function normalizeAllowedUnits(savedUnits, baseUnit) {
    const baseSymbol = typeof baseUnit === 'string' ? baseUnit : baseUnit?.symbol;

    return [...new Set([...(savedUnits ?? []), baseSymbol].filter(Boolean))];
}

function normalizeCustomAttributes(attributes) {
    return (attributes ?? []).map((attribute) => ({
        code: attribute.code ?? '',
        name: attribute.name ?? '',
        type: ['text', 'number', 'boolean'].includes(attribute.type) ? attribute.type : 'text',
        value: attribute.value ?? '',
        has_unit: Boolean(attribute.has_unit ?? attribute.unit),
        unit: attribute.unit ?? '',
    }));
}

function normalizeUnitConversions(conversions) {
    return (conversions ?? []).map((conversion) => ({
        product_unit_id: conversion.product_unit_id ?? conversion.unit?.id ?? '',
        factor_to_base: conversion.factor_to_base ?? '1',
        is_active: conversion.is_active ?? true,
    }));
}

function GeneratedField({ label, name, value, onChange, error, onGenerate }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between gap-3">
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{label}</span>
                <button
                    type="button"
                    onClick={onGenerate}
                    className="rounded-full border border-brand-primary/30 px-3 py-1 text-xs font-semibold text-brand-primary transition hover:bg-brand-primary hover:text-white"
                >
                    Generar automatico
                </button>
            </div>
            <FormField label="" name={name} value={value} onChange={onChange} error={error} placeholder="Se puede generar automaticamente" />
        </div>
    );
}

function normalizeCode(value) {
    return String(value)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function timestampCode() {
    const now = new Date();
    const parts = [
        String(now.getFullYear()).slice(2),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
        String(now.getHours()).padStart(2, '0'),
        String(now.getMinutes()).padStart(2, '0'),
        String(now.getSeconds()).padStart(2, '0'),
    ];

    return parts.join('');
}
