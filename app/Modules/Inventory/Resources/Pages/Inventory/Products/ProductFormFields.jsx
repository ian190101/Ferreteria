import FormField from '../../../../../Shared/Resources/Components/FormField';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import ContextHelp from '../../../../../Shared/Resources/Components/ContextHelp';
import { decimalStep } from '@/Utils/formatters';
import { useState } from 'react';

export function buildProductFormData({ product = null, categories = [], units = [], branches = [] }) {
    const initialBranchIds = (product?.branch_stocks ?? product?.branchStocks ?? [])
        .filter((stock) => stock.is_enabled)
        .map((stock) => Number(stock.branch_id));
    const isEditing = Boolean(product);
    const isGlobal = !isEditing || (branches.length > 0 && branches.every((branch) => initialBranchIds.includes(Number(branch.id))));
    const firstCategory = categories[0] ?? null;
    const initialCategory = product?.product_category_id
        ? categories.find((category) => Number(category.id) === Number(product.product_category_id))
        : firstCategory;
    const initialUnit = product?.product_unit_id
        ? units.find((unit) => Number(unit.id) === Number(product.product_unit_id))
        : units.find((unit) => Number(unit.id) === Number(initialCategory?.default_unit_id));

    return {
        thickness_id: product?.thickness_id ?? '',
        name: product?.name ?? '',
        product_category_id: product?.product_category_id ?? initialCategory?.id ?? '',
        product_unit_id: product?.product_unit_id ?? initialUnit?.id ?? '',
        category: product?.category ?? initialCategory?.name ?? 'Ferreteria general',
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        inventory_tracking_mode: product?.inventory_tracking_mode ?? initialCategory?.default_tracking_mode ?? 'global',
        base_unit: product?.base_unit ?? initialUnit?.symbol ?? 'unidad',
        item_type: product?.item_type ?? 'physical',
        service_type_id: product?.service_type_id ?? product?.serviceType?.id ?? '',
        is_sellable: product?.is_sellable ?? true,
        is_purchasable: product?.is_purchasable ?? true,
        is_inventory_item: product?.is_inventory_item ?? true,
        is_consumable: product?.is_consumable ?? false,
        is_prepared: product?.is_prepared ?? false,
        is_digital: product?.is_digital ?? false,
        duration_minutes: product?.duration_minutes ?? '',
        preparation_minutes: product?.preparation_minutes ?? '',
        attributes: product?.attributes ?? {},
        catalog_settings: product?.catalog_settings ?? {},
        requires_lot: product?.requires_lot ?? false,
        requires_expiration_date: product?.requires_expiration_date ?? false,
        is_rentable: product?.is_rentable ?? false,
        catalog_settings_text: stringifyJson(product?.catalog_settings ?? {}),
        custom_attributes: normalizeCustomAttributes(product?.custom_attributes ?? []),
        allowed_units: normalizeAllowedUnits(product?.allowed_units, initialUnit),
        unit_conversions: normalizeUnitConversions(product?.unit_conversions ?? product?.unitConversions ?? []),
        images: normalizeImages(product?.images ?? []),
        variants: normalizeVariants(product?.active_variants ?? product?.activeVariants ?? product?.variants ?? []),
        purchase_price: product?.purchase_price ?? '0',
        sale_price: product?.sale_price ?? '0',
        minimum_stock_meters: product?.minimum_stock_meters ?? '0',
        is_active: product?.is_active ?? true,
        branch_scope: isGlobal ? 'global' : 'specific',
        branch_ids: isGlobal ? branches.map((branch) => Number(branch.id)) : initialBranchIds,
    };
}

export default function ProductFormFields({ data, setData, errors = {}, thicknesses = [], categories = [], units = [], branches = [], attributeDefinitions = [], serviceTypes = [], decimalFormat, compact = false, productPolicy = {} }) {
    const selectedCategory = categories.find((category) => Number(category.id) === Number(data.product_category_id));
    const selectedUnit = units.find((unit) => Number(unit.id) === Number(data.product_unit_id));
    const profit = Math.max(Number(data.sale_price || 0) - Number(data.purchase_price || 0), 0);

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
    const addExistingCustomAttribute = (definition) => {
        if (!definition?.code || (data.custom_attributes ?? []).some((attribute) => attribute.code === definition.code)) {
            return;
        }

        setData('custom_attributes', [
            ...(data.custom_attributes ?? []),
            {
                code: definition.code,
                name: definition.name,
                type: definition.type ?? 'text',
                value: '',
                has_unit: Boolean(definition.has_unit ?? definition.unit),
                unit: definition.unit ?? '',
            },
        ]);
    };
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
    const selectItemType = (itemType) => {
        const isService = itemType === 'service' || itemType === 'digital';
        const isPrepared = ['prepared_product', 'finished_product'].includes(itemType);

        setData({
            ...data,
            item_type: itemType,
            service_type_id: itemType === 'service' ? data.service_type_id : '',
            is_inventory_item: !isService,
            is_consumable: itemType === 'internal_supply',
            is_prepared: isPrepared,
            is_digital: itemType === 'digital',
            is_rentable: itemType === 'rental',
            requires_lot: itemType === 'rental' ? true : data.requires_lot,
        });
    };
    const addImage = () => setData('images', [
        ...(data.images ?? []),
        { id: null, url: '', path: '', alt_text: data.name ?? '', is_primary: (data.images ?? []).length === 0, sort_order: (data.images ?? []).length },
    ]);
    const updateImage = (index, field, value) => {
        setData('images', (data.images ?? []).map((image, imageIndex) => (
            imageIndex === index ? { ...image, [field]: value, is_primary: field === 'is_primary' && value ? true : image.is_primary } : { ...image, is_primary: field === 'is_primary' && value ? false : image.is_primary }
        )));
    };
    const removeImage = (index) => setData('images', (data.images ?? []).filter((_, imageIndex) => imageIndex !== index));
    const addVariant = () => setData('variants', [
        ...(data.variants ?? []),
        { id: null, name: '', sku: '', barcode: '', attributes: {}, cost_price: '', sale_price: '', is_active: true },
    ]);
    const updateVariant = (index, field, value) => {
        setData('variants', (data.variants ?? []).map((variant, variantIndex) => (
            variantIndex === index ? { ...variant, [field]: value } : variant
        )));
    };
    const updateVariantAttribute = (index, key, value) => {
        setData('variants', (data.variants ?? []).map((variant, variantIndex) => (
            variantIndex === index ? { ...variant, attributes: { ...(variant.attributes ?? {}), [key]: value } } : variant
        )));
    };
    const removeVariant = (index) => setData('variants', (data.variants ?? []).filter((_, variantIndex) => variantIndex !== index));

    return (
        <div className={`grid gap-5 ${compact ? 'sm:grid-cols-2' : 'sm:grid-cols-2'}`}>
            <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
            <SelectField label="Categoria" name="product_category_id" value={data.product_category_id} onChange={(event) => selectCategory(event.target.value)} error={errors.product_category_id} helpTitle="Categoria del producto" helpTooltip="La categoria ayuda a filtrar productos en compras, ventas y cotizaciones. Tambien define valores sugeridos como unidad base, espesor requerido y rastreo adicional por defecto." required>
                <option value="">Seleccione una categoria</option>
                {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                        {category.name}
                    </option>
                ))}
            </SelectField>
            <CatalogBehavior data={data} setData={setData} errors={errors} productPolicy={productPolicy} serviceTypes={serviceTypes} selectItemType={selectItemType} />
            <CatalogTraceability data={data} setData={setData} errors={errors} productPolicy={productPolicy} />
            <GeneratedField label="SKU" name="sku" value={data.sku} onChange={(event) => setData('sku', event.target.value)} error={errors.sku} onGenerate={generateSku} />
            <GeneratedField label={productPolicy.barcodeRequired ? 'Barcode *' : 'Barcode'} name="barcode" value={data.barcode} onChange={(event) => setData('barcode', event.target.value)} error={errors.barcode} onGenerate={generateBarcode} />
            {productPolicy.imagesEnabled ? (
                <ProductImages data={data} errors={errors} galleryEnabled={productPolicy.galleryEnabled} addImage={addImage} updateImage={updateImage} removeImage={removeImage} />
            ) : null}
            {productPolicy.variantsEnabled ? (
                <ProductVariants data={data} errors={errors} addVariant={addVariant} updateVariant={updateVariant} updateVariantAttribute={updateVariantAttribute} removeVariant={removeVariant} />
            ) : null}
            <SelectField label="Espesor" name="thickness_id" value={data.thickness_id} onChange={(event) => setData('thickness_id', event.target.value)} error={errors.thickness_id}>
                <option value="">{selectedCategory?.requires_thickness ? 'Seleccione espesor' : 'Sin espesor'}</option>
                {thicknesses.map((thickness) => (
                    <option key={thickness.id} value={thickness.id}>
                        {thickness.name}
                    </option>
                ))}
            </SelectField>
            <InventoryControl data={data} setData={setData} errors={errors} />
            <SelectField label="Unidad base" name="product_unit_id" value={data.product_unit_id} onChange={(event) => selectUnit(event.target.value)} error={errors.product_unit_id} helpTitle="Unidad base" helpTooltip="Es la unidad principal del inventario. El stock se guarda en esta unidad. Otras unidades como caja, bolsa o kilo pueden venderse si configuras su equivalencia hacia la unidad base." required>
                <option value="">Seleccione unidad</option>
                {units.map((unit) => (
                    <option key={unit.id} value={unit.id}>
                        {unit.name} ({unit.symbol})
                    </option>
                ))}
            </SelectField>
            {productPolicy.unitEquivalencesEnabled === false ? (
                <div className="sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    Las equivalencias de unidades estan desactivadas para este perfil de negocio. El producto se comprara y vendera solo en su unidad base.
                </div>
            ) : (
                <>
                    <AllowedUnits data={data} units={units} selectedUnit={selectedUnit} errors={errors} toggleAllowedUnit={toggleAllowedUnit} />
                    <UnitConversions data={data} units={units} selectedUnit={selectedUnit} errors={errors} addUnitConversion={addUnitConversion} updateUnitConversion={updateUnitConversion} removeUnitConversion={removeUnitConversion} />
                </>
            )}
            <FormField label="Precio compra" name="purchase_price" type="number" step={decimalStep(decimalFormat.decimalsFor('cost'))} min="0" value={data.purchase_price} onChange={(event) => setData('purchase_price', event.target.value)} error={errors.purchase_price} helpTitle="Precio de compra" helpTooltip="Es el costo referencial del producto en la unidad base. Sirve para calcular ganancia estimada y reportes." required />
            <FormField label="Precio venta" name="sale_price" type="number" step={decimalStep(decimalFormat.decimalsFor('money'))} min="0" value={data.sale_price} onChange={(event) => setData('sale_price', event.target.value)} error={errors.sale_price} helpTitle="Precio de venta" helpTooltip="Es el precio sugerido para ventas y cotizaciones. Solo usuarios con permiso pueden cambiarlo manualmente durante la venta." required />
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
            <BranchAvailability data={data} branches={branches} errors={errors} setBranchScope={setBranchScope} toggleBranch={toggleBranch} />
            <CustomAttributes
                data={data}
                units={units}
                errors={errors}
                attributeDefinitions={attributeDefinitions}
                addCustomAttribute={addCustomAttribute}
                addExistingCustomAttribute={addExistingCustomAttribute}
                updateCustomAttribute={updateCustomAttribute}
                removeCustomAttribute={removeCustomAttribute}
            />
        </div>
    );
}

function CatalogBehavior({ data, setData, errors, productPolicy, serviceTypes = [], selectItemType }) {
    const itemTypes = productPolicy.allowedItemTypes ?? ['physical'];

    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Tipo y comportamiento del item</h3>
                    <ContextHelp title="Catalogo flexible">
                        Define si este registro es producto fisico, servicio, combo, insumo, producto preparado o digital. Esto permite adaptar el sistema a ferreterias, restaurantes, servicios, tiendas o supermercados sin cambiar codigo.
                    </ContextHelp>
                </div>
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    <SelectField label="Tipo de item" name="item_type" value={data.item_type ?? 'physical'} onChange={(event) => selectItemType(event.target.value)} error={errors.item_type}>
                        {itemTypes.map((type) => <option key={type} value={type}>{itemTypeLabel(type)}</option>)}
                    </SelectField>
                    {data.item_type === 'service' ? (
                        <SelectField label="Tipo de servicio" name="service_type_id" value={data.service_type_id ?? ''} onChange={(event) => setData('service_type_id', event.target.value)} error={errors.service_type_id} helpTitle="Tipo de servicio" helpTooltip="Clasifica este servicio para ventas, ordenes, reportes y reglas futuras. Transporte/Delivery es protegido y se puede ocultar o activar desde sistemasuperadmin.">
                            <option value="">Servicio general sin clasificar</option>
                            {serviceTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}{type.is_delivery ? ' (delivery)' : ''}
                                </option>
                            ))}
                        </SelectField>
                    ) : null}
                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <ToggleOption label="Vendible" checked={data.is_sellable} onChange={(value) => setData('is_sellable', value)} />
                        <ToggleOption label="Comprable" checked={data.is_purchasable} onChange={(value) => setData('is_purchasable', value)} />
                        <ToggleOption label="Maneja stock" checked={data.is_inventory_item} onChange={(value) => setData('is_inventory_item', value)} />
                        <ToggleOption label="Insumo interno" checked={data.is_consumable} onChange={(value) => setData('is_consumable', value)} />
                    </div>
                    {data.item_type === 'service' ? (
                        <div className="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100 md:col-span-2">
                            Un servicio se vende en cotizaciones, notas de venta o POS sin descontar stock propio. Si el servicio usa materiales, esos materiales deben registrarse como productos/insumos separados.
                        </div>
                    ) : null}
                    {['service', 'digital', 'rental'].includes(data.item_type) ? (
                        <FormField label="Duracion estimada en minutos" name="duration_minutes" type="number" min="0" value={data.duration_minutes ?? ''} onChange={(event) => setData('duration_minutes', event.target.value)} error={errors.duration_minutes} />
                    ) : null}
                    {['prepared_product', 'finished_product'].includes(data.item_type) ? (
                        <FormField label="Tiempo de preparacion en minutos" name="preparation_minutes" type="number" min="0" value={data.preparation_minutes ?? ''} onChange={(event) => setData('preparation_minutes', event.target.value)} error={errors.preparation_minutes} />
                    ) : null}
                </div>
                <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    El perfil actual permite: {itemTypes.map(itemTypeLabel).join(', ')}.
                </p>
            </div>
        </div>
    );
}

function ProductImages({ data, errors, galleryEnabled, addImage, updateImage, removeImage }) {
    const images = data.images ?? [];

    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Imagenes del producto</h3>
                            <ContextHelp title="Imagenes del catalogo">
                                Usa URL publicas o rutas internas seguras. La primera imagen activa se usa como principal en POS, catalogos visuales y documentos que tengan imagen habilitada.
                            </ContextHelp>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{galleryEnabled ? 'Puedes agregar una galeria.' : 'Este perfil permite solo imagen principal.'}</p>
                    </div>
                    <button type="button" onClick={addImage} disabled={!galleryEnabled && images.length >= 1} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary disabled:cursor-not-allowed disabled:opacity-50">
                        Agregar imagen
                    </button>
                </div>
                {errors.images ? <p className="mt-2 text-sm text-red-600">{errors.images}</p> : null}
                {images.length ? (
                    <div className="mt-4 grid gap-3">
                        {images.map((image, index) => (
                            <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-[1.5fr_1fr_auto]">
                                <FormField label="URL de imagen" name={`images.${index}.url`} value={image.url ?? ''} onChange={(event) => updateImage(index, 'url', event.target.value)} error={errors[`images.${index}.url`]} placeholder="https://..." />
                                <FormField label="Texto alternativo" name={`images.${index}.alt_text`} value={image.alt_text ?? ''} onChange={(event) => updateImage(index, 'alt_text', event.target.value)} error={errors[`images.${index}.alt_text`]} />
                                <div className="flex flex-col justify-end gap-2">
                                    <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-800 dark:bg-slate-950">
                                        <input type="radio" name="primary_image" checked={Boolean(image.is_primary) || index === 0} onChange={() => updateImage(index, 'is_primary', true)} className="h-4 w-4 text-brand-primary focus:ring-brand-primary" />
                                        Principal
                                    </label>
                                    <button type="button" onClick={() => removeImage(index)} className="rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 dark:border-red-900/60">
                                        Quitar
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        Sin imagen configurada. El sistema seguira usando el texto del producto.
                    </p>
                )}
            </div>
        </div>
    );
}

function ProductVariants({ data, errors, addVariant, updateVariant, updateVariantAttribute, removeVariant }) {
    const variants = data.variants ?? [];

    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Variantes comerciales</h3>
                            <ContextHelp title="Variantes">
                                Usa variantes cuando el mismo producto se vende con diferencias comerciales como talla, color, sabor o presentacion. Cada variante puede tener SKU, barcode y precio propio.
                            </ContextHelp>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400">Ejemplo: camiseta azul talla M, hamburguesa doble, cafe grande o producto por presentacion.</p>
                    </div>
                    <button type="button" onClick={addVariant} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                        Agregar variante
                    </button>
                </div>
                {errors.variants ? <p className="mt-2 text-sm text-red-600">{errors.variants}</p> : null}
                {variants.length ? (
                    <div className="mt-4 space-y-3">
                        {variants.map((variant, index) => (
                            <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-12">
                                <div className="xl:col-span-3">
                                    <FormField label="Nombre variante" name={`variants.${index}.name`} value={variant.name ?? ''} onChange={(event) => updateVariant(index, 'name', event.target.value)} error={errors[`variants.${index}.name`]} />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="SKU" name={`variants.${index}.sku`} value={variant.sku ?? ''} onChange={(event) => updateVariant(index, 'sku', event.target.value)} error={errors[`variants.${index}.sku`]} />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Barcode" name={`variants.${index}.barcode`} value={variant.barcode ?? ''} onChange={(event) => updateVariant(index, 'barcode', event.target.value)} error={errors[`variants.${index}.barcode`]} />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Atributo" name={`variants.${index}.attribute_key`} value={Object.keys(variant.attributes ?? {})[0] ?? ''} onChange={(event) => updateVariant(index, 'attributes', { [event.target.value]: Object.values(variant.attributes ?? {})[0] ?? '' })} placeholder="color, talla, sabor" />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Valor" name={`variants.${index}.attribute_value`} value={Object.values(variant.attributes ?? {})[0] ?? ''} onChange={(event) => updateVariantAttribute(index, Object.keys(variant.attributes ?? {})[0] ?? 'valor', event.target.value)} placeholder="Azul, M, grande" />
                                </div>
                                <div className="xl:col-span-1">
                                    <SelectField label="Activa" name={`variants.${index}.is_active`} value={variant.is_active ? '1' : '0'} onChange={(event) => updateVariant(index, 'is_active', event.target.value === '1')}>
                                        <option value="1">Si</option>
                                        <option value="0">No</option>
                                    </SelectField>
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Costo propio" name={`variants.${index}.cost_price`} type="number" min="0" step="0.0001" value={variant.cost_price ?? ''} onChange={(event) => updateVariant(index, 'cost_price', event.target.value)} error={errors[`variants.${index}.cost_price`]} />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Precio propio" name={`variants.${index}.sale_price`} type="number" min="0" step="0.0001" value={variant.sale_price ?? ''} onChange={(event) => updateVariant(index, 'sale_price', event.target.value)} error={errors[`variants.${index}.sale_price`]} />
                                </div>
                                <div className="flex items-end md:col-span-2 xl:col-span-8">
                                    <button type="button" onClick={() => removeVariant(index)} className="rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 dark:border-red-900/60">
                                        Quitar variante
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="mt-4 rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        Sin variantes. El producto se vendera como un item unico.
                    </p>
                )}
            </div>
        </div>
    );
}

function InventoryControl({ data, setData, errors }) {
    return (
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
                    <SelectField label="Rastreo adicional" name="inventory_tracking_mode" value={data.inventory_tracking_mode} onChange={(event) => setData('inventory_tracking_mode', event.target.value)} error={errors.inventory_tracking_mode}>
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
    );
}

function CatalogTraceability({ data, setData, errors, productPolicy }) {
    const updateSettingsText = (value) => {
        setData({
            ...data,
            catalog_settings_text: value,
            catalog_settings: parseJsonObject(value),
        });
    };

    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Trazabilidad y reglas del item</h3>
                    <ContextHelp title="Reglas flexibles del catalogo">
                        Usa estas opciones para productos con lote, vencimiento, alquiler, medicamento, material de obra, servicio con insumos o producto digital. Solo se aplican si el perfil de negocio tiene esas capacidades activas.
                    </ContextHelp>
                </div>
                <div className="mt-4 grid gap-3 md:grid-cols-3">
                    <ToggleOption label="Requiere lote/unidad" checked={data.requires_lot} onChange={(value) => setData('requires_lot', value)} disabled={!productPolicy.lotsEnabled} />
                    <ToggleOption label="Requiere vencimiento" checked={data.requires_expiration_date} onChange={(value) => setData('requires_expiration_date', value)} disabled={!productPolicy.expirationDatesEnabled} />
                    <ToggleOption label="Alquilable" checked={data.is_rentable} onChange={(value) => setData('is_rentable', value)} disabled={!productPolicy.rentalsEnabled} />
                </div>
                <div className="mt-4">
                    <FormField
                        label="Configuracion flexible JSON"
                        name="catalog_settings_text"
                        value={data.catalog_settings_text ?? ''}
                        onChange={(event) => updateSettingsText(event.target.value)}
                        error={errors.catalog_settings}
                        placeholder='{"material_type":"obra","dosage":"1:2:3","warranty_days":30}'
                    />
                    <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
                        Ejemplos: material de obra, dosis, garantia, link digital, sesiones, reglas de alquiler o datos sanitarios. No permite codigo ejecutable; el backend guarda solo datos.
                    </p>
                    {errors.requires_lot ? <p className="mt-1 text-sm text-red-600">{errors.requires_lot}</p> : null}
                    {errors.requires_expiration_date ? <p className="mt-1 text-sm text-red-600">{errors.requires_expiration_date}</p> : null}
                    {errors.is_rentable ? <p className="mt-1 text-sm text-red-600">{errors.is_rentable}</p> : null}
                </div>
            </div>
        </div>
    );
}

function AllowedUnits({ data, units, selectedUnit, errors, toggleAllowedUnit }) {
    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Unidades para venta y compra</h3>
                    <ContextHelp title="Unidades comerciales">
                        Marca las unidades en las que este producto puede comprarse, venderse o cotizarse. La unidad base siempre queda habilitada. Si agregas otra unidad, configura su equivalencia para que el stock se descuente correctamente.
                    </ContextHelp>
                </div>
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">La unidad base siempre queda habilitada. Agrega otras formas comerciales como caja, unidad, kg, bolsa o paquete.</p>
                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    {units.map((unit) => {
                        const isBase = unit.symbol === (selectedUnit?.symbol ?? data.base_unit);
                        const checked = (data.allowed_units ?? []).includes(unit.symbol) || isBase;

                        return (
                            <label key={unit.id} className="flex items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-900">
                                <input type="checkbox" checked={checked} disabled={isBase} onChange={() => toggleAllowedUnit(unit.symbol)} className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary disabled:opacity-60" />
                                <span>{unit.name} ({unit.symbol}){isBase ? ' - base' : ''}</span>
                            </label>
                        );
                    })}
                </div>
                {errors.allowed_units ? <p className="mt-2 text-sm text-red-600">{errors.allowed_units}</p> : null}
            </div>
        </div>
    );
}

function UnitConversions({ data, units, selectedUnit, errors, addUnitConversion, updateUnitConversion, removeUnitConversion }) {
    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Equivalencias de unidades</h3>
                            <ContextHelp title="Equivalencias">
                                Indica cuanto representa una unidad comercial en la unidad base. Ejemplo: si la unidad base es unidad y vendes por caja, configura 1 caja = 12 unidades. Asi el sistema descuenta 12 del stock al vender una caja.
                            </ContextHelp>
                        </div>
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
    );
}

function BranchAvailability({ data, branches, errors, setBranchScope, toggleBranch }) {
    return (
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
                                <input type="checkbox" checked={(data.branch_ids ?? []).map((id) => Number(id)).includes(Number(branch.id))} onChange={() => toggleBranch(branch.id)} className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary" />
                                <span>{branch.name}</span>
                            </label>
                        ))}
                    </div>
                ) : null}
                {errors.branch_ids ? <p className="mt-2 text-sm text-red-600">{errors.branch_ids}</p> : null}
                {errors.branch_scope ? <p className="mt-2 text-sm text-red-600">{errors.branch_scope}</p> : null}
            </div>
        </div>
    );
}

function CustomAttributes({ data, units, errors, attributeDefinitions, addCustomAttribute, addExistingCustomAttribute, updateCustomAttribute, removeCustomAttribute }) {
    const [selectedDefinitionCode, setSelectedDefinitionCode] = useState('');
    const currentCodes = new Set((data.custom_attributes ?? []).map((attribute) => attribute.code).filter(Boolean));
    const availableDefinitions = (attributeDefinitions ?? []).filter((definition) => definition?.code && !currentCodes.has(definition.code));
    const selectedDefinition = availableDefinitions.find((definition) => definition.code === selectedDefinitionCode);
    const useExistingAttribute = () => {
        addExistingCustomAttribute(selectedDefinition);
        setSelectedDefinitionCode('');
    };

    return (
        <div className="sm:col-span-2">
            <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-slate-950 dark:text-white">Caracteristicas propias del producto</h3>
                            <ContextHelp title="Caracteristicas en documentos">
                                Usa caracteristicas para datos como modelo, color, largo, acabado o presentacion. En cotizaciones y notas de venta aparecen como columnas si la plantilla las tiene activadas. Si un producto no tiene esa caracteristica, se imprime "-".
                            </ContextHelp>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Puedes reutilizar una caracteristica ya creada, como Modelo o Color, y solo cambiar el valor de este producto. En cotizaciones y notas de venta estas caracteristicas aparecen como columnas si la plantilla las tiene activadas; si un producto no tiene esa caracteristica, se muestra "-".
                        </p>
                    </div>
                    <button type="button" onClick={addCustomAttribute} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                        Crear nueva caracteristica
                    </button>
                </div>

                <div className="mt-4 rounded-lg border border-brand-primary/20 bg-brand-primary/5 p-4 dark:border-brand-primary/30 dark:bg-brand-primary/10">
                    <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                        <SelectField label="Usar caracteristica existente" name="existing_attribute_definition" value={selectedDefinitionCode} onChange={(event) => setSelectedDefinitionCode(event.target.value)} disabled={!availableDefinitions.length}>
                            <option value="">{availableDefinitions.length ? 'Seleccione una caracteristica reutilizable' : 'No hay caracteristicas reutilizables disponibles'}</option>
                            {availableDefinitions.map((definition) => (
                                <option key={definition.code} value={definition.code}>
                                    {definition.name} ({definition.code})
                                </option>
                            ))}
                        </SelectField>
                        <button type="button" onClick={useExistingAttribute} disabled={!selectedDefinition} className="self-end rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                            Agregar seleccionada
                        </button>
                    </div>
                    <p className="mt-2 text-xs text-slate-600 dark:text-slate-300">
                        Usa esta opcion para no crear duplicados como "Modelo", "modelo calamina" o "Modelo 2". Si no existe la caracteristica que necesitas, usa "Crear nueva caracteristica".
                    </p>
                </div>

                {(data.custom_attributes ?? []).length ? (
                    <div className="mt-4 space-y-3">
                        {data.custom_attributes.map((attribute, index) => (
                            <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 xl:grid-cols-12">
                                <div className="xl:col-span-3">
                                    <FormField label="Nombre" name={`custom_attributes.${index}.name`} value={attribute.name ?? ''} onChange={(event) => updateCustomAttribute(index, 'name', event.target.value)} error={errors[`custom_attributes.${index}.name`]} />
                                </div>
                                <div className="xl:col-span-2">
                                    <FormField label="Codigo" name={`custom_attributes.${index}.code`} value={attribute.code ?? ''} onChange={(event) => updateCustomAttribute(index, 'code', event.target.value)} error={errors[`custom_attributes.${index}.code`]} placeholder="Automatico si se deja vacio" />
                                </div>
                                <div className="xl:col-span-2">
                                    <SelectField label="Tipo" name={`custom_attributes.${index}.type`} value={attribute.type ?? 'text'} onChange={(event) => updateCustomAttribute(index, 'type', event.target.value)} error={errors[`custom_attributes.${index}.type`]}>
                                        <option value="text">Texto</option>
                                        <option value="number">Numerico</option>
                                        <option value="boolean">Si/No</option>
                                    </SelectField>
                                </div>
                                {attribute.type === 'boolean' ? (
                                    <div className="xl:col-span-2">
                                        <SelectField label="Valor" name={`custom_attributes.${index}.value`} value={String(attribute.value ?? '')} onChange={(event) => updateCustomAttribute(index, 'value', event.target.value)} error={errors[`custom_attributes.${index}.value`]}>
                                            <option value="">Sin definir</option>
                                            <option value="1">Si</option>
                                            <option value="0">No</option>
                                        </SelectField>
                                    </div>
                                ) : (
                                    <div className="xl:col-span-2">
                                        <FormField label="Valor" name={`custom_attributes.${index}.value`} type={attribute.type === 'number' ? 'number' : 'text'} step={attribute.type === 'number' ? '0.01' : undefined} value={attribute.value ?? ''} onChange={(event) => updateCustomAttribute(index, 'value', event.target.value)} error={errors[`custom_attributes.${index}.value`]} />
                                    </div>
                                )}
                                <div className="xl:col-span-1">
                                    <SelectField label="Usa unidad" name={`custom_attributes.${index}.has_unit`} value={attribute.has_unit ? '1' : '0'} onChange={(event) => updateCustomAttribute(index, 'has_unit', event.target.value === '1')} error={errors[`custom_attributes.${index}.has_unit`]}>
                                        <option value="0">No</option>
                                        <option value="1">Si</option>
                                    </SelectField>
                                </div>
                                <div className="xl:col-span-2">
                                    <SelectField label="Unidad" name={`custom_attributes.${index}.unit`} value={attribute.unit ?? ''} onChange={(event) => updateCustomAttribute(index, 'unit', event.target.value)} error={errors[`custom_attributes.${index}.unit`]} disabled={!attribute.has_unit}>
                                        <option value="">Sin unidad</option>
                                        {units.map((unit) => <option key={unit.id} value={unit.symbol}>{unit.name} ({unit.symbol})</option>)}
                                    </SelectField>
                                </div>
                                <div className="flex items-end sm:col-span-2 xl:col-span-12">
                                    <button type="button" onClick={() => removeCustomAttribute(index)} className="w-full rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-900/60 dark:hover:bg-red-950/30 sm:w-auto">
                                        Quitar caracteristica
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
    );
}

export function unitLabel(unit) {
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

export function normalizeAllowedUnits(savedUnits, baseUnit) {
    const baseSymbol = typeof baseUnit === 'string' ? baseUnit : baseUnit?.symbol;

    return [...new Set([...(savedUnits ?? []), baseSymbol].filter(Boolean))];
}

export function normalizeCustomAttributes(attributes) {
    return (attributes ?? []).map((attribute) => ({
        code: attribute.code ?? '',
        name: attribute.name ?? '',
        type: ['text', 'number', 'boolean'].includes(attribute.type) ? attribute.type : 'text',
        value: attribute.value ?? '',
        has_unit: Boolean(attribute.has_unit ?? attribute.unit),
        unit: attribute.unit ?? '',
    }));
}

export function normalizeUnitConversions(conversions) {
    return (conversions ?? []).map((conversion) => ({
        product_unit_id: conversion.product_unit_id ?? conversion.unit?.id ?? '',
        factor_to_base: conversion.factor_to_base ?? '1',
        is_active: conversion.is_active ?? true,
    }));
}

export function normalizeImages(images) {
    return (images ?? []).map((image, index) => ({
        id: image.id ?? null,
        url: image.url ?? '',
        path: image.path ?? '',
        alt_text: image.alt_text ?? '',
        is_primary: Boolean(image.is_primary) || index === 0,
        sort_order: image.sort_order ?? index,
    }));
}

export function normalizeVariants(variants) {
    return (variants ?? []).map((variant) => ({
        id: variant.id ?? null,
        sku: variant.sku ?? '',
        barcode: variant.barcode ?? '',
        name: variant.name ?? '',
        attributes: variant.attributes ?? {},
        cost_price: variant.cost_price ?? '',
        sale_price: variant.sale_price ?? '',
        is_active: variant.is_active ?? true,
    }));
}

function parseJsonObject(value) {
    if (!value) {
        return {};
    }

    try {
        const parsed = JSON.parse(value);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

function stringifyJson(value) {
    return value && Object.keys(value).length ? JSON.stringify(value) : '';
}

function ToggleOption({ label, checked, onChange, disabled = false }) {
    return (
        <label className={`flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900 ${disabled ? 'opacity-60' : ''}`}>
            <span className="text-xs font-medium text-slate-700 dark:text-slate-300">{label}</span>
            <input type="checkbox" checked={Boolean(checked)} disabled={disabled} onChange={(event) => onChange(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary disabled:cursor-not-allowed" />
        </label>
    );
}

function itemTypeLabel(type) {
    return {
        physical: 'Producto fisico',
        service: 'Servicio',
        combo: 'Combo',
        kit: 'Kit',
        prepared_product: 'Producto preparado',
        internal_supply: 'Insumo interno',
        finished_product: 'Producto terminado',
        rental: 'Producto alquilable',
        digital: 'Digital/intangible',
    }[type] ?? type;
}

function GeneratedField({ label, name, value, onChange, error, onGenerate }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between gap-3">
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{label}</span>
                <button type="button" onClick={onGenerate} className="rounded-full border border-brand-primary/30 px-3 py-1 text-xs font-semibold text-brand-primary transition hover:bg-brand-primary hover:text-white">
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
