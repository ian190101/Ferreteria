import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Catalogs({ categories, units, thicknesses = [] }) {
    const [editingUnit, setEditingUnit] = useState(null);
    const [editingThickness, setEditingThickness] = useState(null);
    const [editingCategory, setEditingCategory] = useState(null);
    const unitForm = useForm(unitDefaults());
    const thicknessForm = useForm(thicknessDefaults());
    const categoryForm = useForm(categoryDefaults(units[0]));

    const submitUnit = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => {
            unitForm.reset();
            setEditingUnit(null);
        } };

        if (editingUnit) {
            unitForm.put(route('inventory.products.catalogs.units.update', editingUnit.id), options);
            return;
        }

        unitForm.post(route('inventory.products.catalogs.units.store'), options);
    };

    const submitThickness = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => {
            thicknessForm.reset();
            setEditingThickness(null);
        } };

        if (editingThickness) {
            thicknessForm.put(route('inventory.products.catalogs.thicknesses.update', editingThickness.id), options);
            return;
        }

        thicknessForm.post(route('inventory.products.catalogs.thicknesses.store'), options);
    };

    const submitCategory = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => {
            categoryForm.reset();
            setEditingCategory(null);
        } };

        if (editingCategory) {
            categoryForm.put(route('inventory.products.catalogs.categories.update', editingCategory.id), options);
            return;
        }

        categoryForm.post(route('inventory.products.catalogs.categories.store'), options);
    };

    const editThickness = (thickness) => {
        setEditingThickness(thickness);
        thicknessForm.setData({
            name: thickness.name,
            millimeters: thickness.millimeters,
            kg_per_meter: thickness.kg_per_meter,
            is_active: thickness.is_active,
        });
    };

    const editUnit = (unit) => {
        setEditingUnit(unit);
        unitForm.setData({
            name: unit.name,
            symbol: unit.symbol,
            kind: unit.kind,
            is_active: unit.is_active,
        });
    };

    const editCategory = (category) => {
        setEditingCategory(category);
        categoryForm.setData({
            name: category.name,
            description: category.description ?? '',
            default_unit_id: category.default_unit_id ?? '',
            default_tracking_mode: category.default_tracking_mode ?? 'global',
            requires_thickness: category.requires_thickness,
            is_active: category.is_active,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Categorias y caracteristicas" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader
                        title="Catalogos de productos"
                        description="Define unidades, espesores y categorias. Las caracteristicas se configuran directamente dentro de cada producto."
                    />
                    <Link href={route('inventory.products.index')} className="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:border-brand-primary hover:text-brand-primary dark:border-slate-800 dark:text-slate-300">
                        Volver a productos
                    </Link>
                </div>

                <div className="grid gap-6 xl:grid-cols-3">
                    <Panel title={editingUnit ? 'Editar unidad' : 'Nueva unidad'}>
                        <form onSubmit={submitUnit} className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Nombre" name="unit_name" value={unitForm.data.name} onChange={(event) => unitForm.setData('name', event.target.value)} error={unitForm.errors.name} required />
                            <FormField label="Simbolo" name="symbol" value={unitForm.data.symbol} onChange={(event) => unitForm.setData('symbol', event.target.value)} error={unitForm.errors.symbol} required />
                            <SelectField label="Tipo" name="kind" value={unitForm.data.kind} onChange={(event) => unitForm.setData('kind', event.target.value)} error={unitForm.errors.kind} required>
                                <option value="cantidad">Cantidad</option>
                                <option value="longitud">Longitud</option>
                                <option value="peso">Peso</option>
                                <option value="volumen">Volumen</option>
                            </SelectField>
                            <SelectField label="Estado" name="unit_active" value={unitForm.data.is_active ? '1' : '0'} onChange={(event) => unitForm.setData('is_active', event.target.value === '1')} error={unitForm.errors.is_active}>
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </SelectField>
                            <div className="flex items-center gap-3 sm:col-span-2">
                                <PrimaryButton disabled={unitForm.processing}>{editingUnit ? 'Actualizar unidad' : 'Crear unidad'}</PrimaryButton>
                                {editingUnit ? <button type="button" className="text-sm text-slate-500" onClick={() => { setEditingUnit(null); unitForm.setData(unitDefaults()); }}>Cancelar</button> : null}
                            </div>
                        </form>

                        <SimpleTable
                            headers={['Unidad', 'Tipo', 'Uso', 'Estado', '']}
                            rows={units.map((unit) => [
                                `${unit.name} (${unit.symbol})`,
                                unit.kind,
                                unit.products_count,
                                unit.is_active ? 'Activa' : 'Inactiva',
                                <IconButton key={unit.id} icon="edit" label="Editar" onClick={() => editUnit(unit)} />,
                            ])}
                        />
                    </Panel>

                    <Panel title={editingThickness ? 'Editar espesor' : 'Nuevo espesor'}>
                        <form onSubmit={submitThickness} className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Nombre" name="thickness_name" value={thicknessForm.data.name} onChange={(event) => thicknessForm.setData('name', event.target.value)} error={thicknessForm.errors.name} required />
                            <FormField label="Milimetros" name="millimeters" type="number" step="0.0001" value={thicknessForm.data.millimeters} onChange={(event) => thicknessForm.setData('millimeters', event.target.value)} error={thicknessForm.errors.millimeters} required />
                            <FormField label="Kg por metro" name="kg_per_meter" type="number" step="0.000001" value={thicknessForm.data.kg_per_meter} onChange={(event) => thicknessForm.setData('kg_per_meter', event.target.value)} error={thicknessForm.errors.kg_per_meter} required />
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Factor kg a metros</label>
                                <div className="mt-1 flex min-h-10 items-center rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                                    {conversionFactor(thicknessForm.data.kg_per_meter)}
                                </div>
                            </div>
                            <SelectField label="Estado" name="thickness_active" value={thicknessForm.data.is_active ? '1' : '0'} onChange={(event) => thicknessForm.setData('is_active', event.target.value === '1')} error={thicknessForm.errors.is_active}>
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </SelectField>
                            <div className="flex items-center gap-3 sm:col-span-2">
                                <PrimaryButton disabled={thicknessForm.processing}>{editingThickness ? 'Actualizar espesor' : 'Crear espesor'}</PrimaryButton>
                                {editingThickness ? <button type="button" className="text-sm text-slate-500" onClick={() => { setEditingThickness(null); thicknessForm.setData(thicknessDefaults()); }}>Cancelar</button> : null}
                            </div>
                        </form>

                        <SimpleTable
                            headers={['Espesor', 'Kg/m', 'Factor', 'Uso', 'Estado', '']}
                            rows={thicknesses.map((thickness) => [
                                `${thickness.name} (${Number(thickness.millimeters).toLocaleString('es-BO')} mm)`,
                                Number(thickness.kg_per_meter).toLocaleString('es-BO', { maximumFractionDigits: 6 }),
                                Number(thickness.kg_to_meter_factor).toLocaleString('es-BO', { maximumFractionDigits: 6 }),
                                thickness.products_count,
                                thickness.is_active ? 'Activo' : 'Inactivo',
                                <IconButton key={thickness.id} icon="edit" label="Editar" onClick={() => editThickness(thickness)} />,
                            ])}
                        />
                    </Panel>

                    <Panel title={editingCategory ? 'Editar categoria' : 'Nueva categoria'}>
                        <form onSubmit={submitCategory} className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Nombre" name="category_name" value={categoryForm.data.name} onChange={(event) => categoryForm.setData('name', event.target.value)} error={categoryForm.errors.name} required />
                            <SelectField label="Unidad por defecto" name="default_unit_id" value={categoryForm.data.default_unit_id} onChange={(event) => categoryForm.setData('default_unit_id', event.target.value)} error={categoryForm.errors.default_unit_id} required>
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>
                                ))}
                            </SelectField>
                            <SelectField label="Rastreo adicional por defecto" name="default_tracking_mode" value={categoryForm.data.default_tracking_mode} onChange={(event) => categoryForm.setData('default_tracking_mode', event.target.value)} error={categoryForm.errors.default_tracking_mode} required>
                                <option value="global">No, solo stock por sucursal</option>
                                <option value="coil">Si, tambien por lote/unidad fisica</option>
                            </SelectField>
                            <SelectField label="Requiere espesor" name="requires_thickness" value={categoryForm.data.requires_thickness ? '1' : '0'} onChange={(event) => categoryForm.setData('requires_thickness', event.target.value === '1')} error={categoryForm.errors.requires_thickness}>
                                <option value="1">Si</option>
                                <option value="0">No</option>
                            </SelectField>
                            <SelectField label="Estado" name="category_active" value={categoryForm.data.is_active ? '1' : '0'} onChange={(event) => categoryForm.setData('is_active', event.target.value === '1')} error={categoryForm.errors.is_active}>
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </SelectField>
                            <div className="sm:col-span-2">
                                <FormField label="Descripcion" name="description" value={categoryForm.data.description} onChange={(event) => categoryForm.setData('description', event.target.value)} error={categoryForm.errors.description} />
                            </div>
                            <div className="flex items-center gap-3 sm:col-span-2">
                                <PrimaryButton disabled={categoryForm.processing}>{editingCategory ? 'Actualizar categoria' : 'Crear categoria'}</PrimaryButton>
                                {editingCategory ? <button type="button" className="text-sm text-slate-500" onClick={() => { setEditingCategory(null); categoryForm.setData(categoryDefaults(units[0])); }}>Cancelar</button> : null}
                            </div>
                        </form>

                        <SimpleTable
                            headers={['Categoria', 'Unidad', 'Productos', 'Estado', '']}
                            rows={categories.map((category) => [
                                category.name,
                                category.default_unit ? `${category.default_unit.name} (${category.default_unit.symbol})` : '-',
                                category.products_count,
                                category.is_active ? 'Activa' : 'Inactiva',
                                <div key={category.id} className="flex items-center gap-2">
                                    <IconButton icon="edit" label="Editar" onClick={() => editCategory(category)} />
                                </div>,
                            ])}
                        />
                    </Panel>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Panel({ title, children, className = '' }) {
    return (
        <div className={`rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 ${className}`}>
            <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {children}
        </div>
    );
}

function SimpleTable({ headers, rows }) {
    return (
        <div className="mt-5 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        {headers.map((header) => <th key={header} className="px-3 py-2 font-medium">{header}</th>)}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map((row, index) => (
                        <tr key={index}>
                            {row.map((cell, cellIndex) => <td key={cellIndex} className="px-3 py-2 align-top">{cell}</td>)}
                        </tr>
                    ))}
                    {rows.length === 0 ? (
                        <tr>
                            <td className="px-3 py-4 text-slate-500" colSpan={headers.length}>Sin registros.</td>
                        </tr>
                    ) : null}
                </tbody>
            </table>
        </div>
    );
}

function unitDefaults() {
    return {
        name: '',
        symbol: '',
        kind: 'cantidad',
        is_active: true,
    };
}

function categoryDefaults(unit) {
    return {
        name: '',
        description: '',
        default_unit_id: unit?.id ?? '',
        default_tracking_mode: 'global',
        requires_thickness: false,
        is_active: true,
    };
}

function thicknessDefaults() {
    return {
        name: '',
        millimeters: '',
        kg_per_meter: '',
        is_active: true,
    };
}

function conversionFactor(kgPerMeter) {
    const value = Number(kgPerMeter);

    if (!value || value <= 0) {
        return 'Ingrese kg/m';
    }

    return `${(1 / value).toLocaleString('es-BO', { maximumFractionDigits: 6 })} m por kg`;
}
