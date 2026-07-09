import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';

export default function Form({ product, thicknesses, categories, units }) {
    const isEditing = Boolean(product);
    const decimalFormat = useDecimalFormatter('inventory');
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
        custom_attributes: product?.custom_attributes ?? [],
        default_width: product?.default_width ?? '',
        purchase_price: product?.purchase_price ?? '0',
        sale_price: product?.sale_price ?? '0',
        minimum_stock_meters: product?.minimum_stock_meters ?? '0',
        is_active: product?.is_active ?? true,
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
            inventory_tracking_mode: category?.default_tracking_mode ?? data.inventory_tracking_mode,
            thickness_id: category?.requires_thickness ? data.thickness_id : '',
            attributes: normalizeAttributesForCategory(category, data.attributes),
        });
    };

    const selectUnit = (unitId) => {
        const unit = units.find((item) => Number(item.id) === Number(unitId));

        setData({
            ...data,
            product_unit_id: unitId,
            base_unit: unit?.symbol ?? data.base_unit,
        });
    };

    const setAttribute = (code, value) => {
        setData('attributes', {
            ...data.attributes,
            [code]: value,
        });
    };
    const addCustomAttribute = () => setData('custom_attributes', [
        ...(data.custom_attributes ?? []),
        { code: '', name: '', value: '', unit: '' },
    ]);
    const updateCustomAttribute = (index, field, value) => {
        setData('custom_attributes', (data.custom_attributes ?? []).map((attribute, attributeIndex) => (
            attributeIndex === index ? { ...attribute, [field]: value } : attribute
        )));
    };
    const removeCustomAttribute = (index) => {
        setData('custom_attributes', (data.custom_attributes ?? []).filter((_, attributeIndex) => attributeIndex !== index));
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
                    <SelectField
                        label="Modo de rastreo"
                        name="inventory_tracking_mode"
                        value={data.inventory_tracking_mode}
                        onChange={(event) => setData('inventory_tracking_mode', event.target.value)}
                        error={errors.inventory_tracking_mode}
                    >
                        <option value="global">Global por sucursal</option>
                        <option value="coil">Individual por lote/unidad fisica</option>
                    </SelectField>
                    <SelectField label="Unidad base" name="product_unit_id" value={data.product_unit_id} onChange={(event) => selectUnit(event.target.value)} error={errors.product_unit_id} required>
                        <option value="">Seleccione unidad</option>
                        {units.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name} ({unit.symbol})
                            </option>
                        ))}
                    </SelectField>
                    <FormField label="Ancho por defecto" name="default_width" type="number" step={decimalStep(decimalFormat.decimalsFor('measure'))} value={data.default_width} onChange={(event) => setData('default_width', event.target.value)} error={errors.default_width} />
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
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950">
                            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Caracteristicas de {selectedCategory?.name ?? 'categoria'}</h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">Estos campos cambian segun la categoria seleccionada.</p>
                                </div>
                                <Link href={route('inventory.products.catalogs.index')} className="text-sm font-semibold text-brand-primary hover:underline">
                                    Gestionar categorias
                                </Link>
                            </div>

                            {selectedCategory?.attributes?.length ? (
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    {selectedCategory.attributes.map((attribute) => (
                                        <AttributeField
                                            key={attribute.id}
                                            attribute={attribute}
                                            value={data.attributes?.[attribute.code] ?? ''}
                                            error={errors[`attributes.${attribute.code}`]}
                                            onChange={(value) => setAttribute(attribute.code, value)}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                    Esta categoria aun no tiene caracteristicas configuradas.
                                </p>
                            )}
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
                                        <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                                            <FormField label="Nombre" name={`custom_attributes.${index}.name`} value={attribute.name ?? ''} onChange={(event) => updateCustomAttribute(index, 'name', event.target.value)} error={errors[`custom_attributes.${index}.name`]} />
                                            <FormField label="Codigo" name={`custom_attributes.${index}.code`} value={attribute.code ?? ''} onChange={(event) => updateCustomAttribute(index, 'code', event.target.value)} error={errors[`custom_attributes.${index}.code`]} placeholder="Automatico si se deja vacio" />
                                            <FormField label="Valor" name={`custom_attributes.${index}.value`} value={attribute.value ?? ''} onChange={(event) => updateCustomAttribute(index, 'value', event.target.value)} error={errors[`custom_attributes.${index}.value`]} />
                                            <div className="flex gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <FormField label="Unidad" name={`custom_attributes.${index}.unit`} value={attribute.unit ?? ''} onChange={(event) => updateCustomAttribute(index, 'unit', event.target.value)} error={errors[`custom_attributes.${index}.unit`]} />
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

function normalizeAttributesForCategory(category, currentAttributes) {
    if (!category?.attributes?.length) {
        return {};
    }

    return category.attributes.reduce((values, attribute) => ({
        ...values,
        [attribute.code]: currentAttributes?.[attribute.code] ?? '',
    }), {});
}

function AttributeField({ attribute, value, error, onChange }) {
    const label = `${attribute.name}${attribute.is_required ? ' *' : ''}${attribute.unit ? ` (${attribute.unit.symbol})` : ''}`;

    if (attribute.type === 'boolean') {
        return (
            <label className="flex min-h-[4.25rem] items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(event) => onChange(event.target.checked)}
                    className="h-5 w-5 rounded border-slate-300 text-brand-primary focus:ring-brand-primary"
                />
                <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
            </label>
        );
    }

    if (attribute.type === 'select') {
        return (
            <SelectField label={label} name={`attributes.${attribute.code}`} value={value} onChange={(event) => onChange(event.target.value)} error={error} required={attribute.is_required}>
                <option value="">Seleccione</option>
                {(attribute.options ?? []).map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </SelectField>
        );
    }

    return (
        <FormField
            label={label}
            name={`attributes.${attribute.code}`}
            type={attribute.type === 'number' ? 'number' : 'text'}
            step={attribute.type === 'number' ? '0.001' : undefined}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            error={error}
            required={attribute.is_required}
        />
    );
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
