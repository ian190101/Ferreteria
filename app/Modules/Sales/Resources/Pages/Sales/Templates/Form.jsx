import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

const FIELD_LABELS = {
    branch_name: 'Nombre de empresa/sucursal',
    branch_address: 'Direccion',
    branch_phone: 'Telefono principal',
    branch_secondary_phone: 'Telefono secundario',
    document_title: 'Titulo del documento',
    receipt_number: 'Numero de comprobante',
    date: 'Fecha',
    currency: 'Moneda',
    seller: 'Vendedor',
    point_of_sale: 'Punto de venta',
    customer: 'Cliente',
    sale_type: 'Tipo de venta',
    customer_contact: 'Contacto del cliente',
    exchange_rate: 'Tipo de cambio',
    item_number: 'Numero de item',
    item_description: 'Descripcion del producto',
    item_lot: 'Lote del item',
    item_model: 'Modelo/SKU',
    item_unit: 'Unidad',
    item_quantity: 'Cantidad',
    item_base: 'Base calculada',
    item_price: 'Precio',
    item_subtotal: 'Subtotal del item',
    subtotal: 'Subtotal',
    discount: 'Descuento',
    advance: 'Anticipo',
    balance_due: 'Saldo por pagar',
};

const FIELD_GROUPS = [
    {
        title: 'Empresa y documento',
        fields: ['branch_name', 'branch_address', 'branch_phone', 'branch_secondary_phone', 'document_title', 'receipt_number', 'date'],
    },
    {
        title: 'Cliente y venta',
        fields: ['currency', 'seller', 'point_of_sale', 'customer', 'sale_type', 'customer_contact', 'exchange_rate'],
    },
    {
        title: 'Columnas de items',
        fields: ['item_number', 'item_description', 'item_lot', 'item_model', 'item_unit', 'item_quantity', 'item_base', 'item_price', 'item_subtotal'],
    },
    {
        title: 'Totales',
        fields: ['subtotal', 'discount', 'advance', 'balance_due'],
    },
];

export default function Form({ template, branches, defaultLayout, attributeFields = [] }) {
    const isEditing = Boolean(template);
    const [draggedSection, setDraggedSection] = useState(null);
    const { data, setData, post, put, processing, errors } = useForm({
        branch_id: template?.branch_id ?? '',
        name: template?.name ?? 'Formato principal',
        document_type: template?.document_type ?? 'both',
        paper_type: template?.paper_type ?? 'letter',
        thermal_width_mm: template?.thermal_width_mm ?? 80,
        use_branding: template?.use_branding ?? true,
        is_default: template?.is_default ?? true,
        is_active: template?.is_active ?? true,
        layout: template?.layout ?? defaultLayout,
    });

    const setLayout = (path, value) => {
        const next = structuredClone(data.layout);
        let pointer = next;
        path.slice(0, -1).forEach((part) => {
            pointer = pointer[part];
        });
        pointer[path.at(-1)] = value;
        setData('layout', next);
    };

    const setField = (field, value) => setLayout(['fields', field], value);
    const setSection = (index, field, value) => {
        const sections = [...data.layout.sections];
        sections[index] = { ...sections[index], [field]: value };
        setLayout(['sections'], normalizeSectionOrder(sections));
    };

    const moveSection = (fromIndex, toIndex) => {
        if (toIndex < 0 || toIndex >= data.layout.sections.length) {
            return;
        }

        const sections = [...data.layout.sections];
        const [section] = sections.splice(fromIndex, 1);
        sections.splice(toIndex, 0, section);
        setLayout(['sections'], normalizeSectionOrder(sections));
    };

    const dropSection = (targetIndex) => {
        if (draggedSection === null || draggedSection === targetIndex) {
            setDraggedSection(null);
            return;
        }

        moveSection(draggedSection, targetIndex);
        setDraggedSection(null);
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('sales.templates.update', template.id), { preserveScroll: true });
            return;
        }

        post(route('sales.templates.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title={isEditing ? 'Editar plantilla' : 'Nueva plantilla'} />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title={isEditing ? 'Editar plantilla' : 'Nueva plantilla'}
                    description="Controla el formato imprimible: branding, dimensiones, campos visibles y posicion por orden de secciones."
                />

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_420px]">
                    <div className="space-y-6">
                        <Panel title="Datos generales">
                            <div className="grid gap-5 sm:grid-cols-3">
                                <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                                <SelectField label="Sucursal" name="branch_id" value={data.branch_id ?? ''} onChange={(event) => setData('branch_id', event.target.value || null)} error={errors.branch_id}>
                                    <option value="">Global</option>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <SelectField label="Documento" name="document_type" value={data.document_type} onChange={(event) => setData('document_type', event.target.value)} error={errors.document_type}>
                                    <option value="both">Ambos</option>
                                    <option value="quotation">Cotizacion</option>
                                    <option value="sale_note">Nota de venta</option>
                                </SelectField>
                                <SelectField label="Tipo de hoja" name="paper_type" value={data.paper_type} onChange={(event) => setData('paper_type', event.target.value)} error={errors.paper_type}>
                                    <option value="letter">Bond carta</option>
                                    <option value="legal">Oficio</option>
                                    <option value="full_page">Hoja completa</option>
                                    <option value="thermal">Impresora termica</option>
                                </SelectField>
                                {data.paper_type === 'thermal' ? (
                                    <FormField label="Ancho termico mm" name="thermal_width_mm" type="number" min="40" max="120" value={data.thermal_width_mm} onChange={(event) => setData('thermal_width_mm', event.target.value)} error={errors.thermal_width_mm} />
                                ) : null}
                                <FormField label="Margen mm" name="margin_mm" type="number" min="0" max="30" value={data.layout.margin_mm} onChange={(event) => setLayout(['margin_mm'], Number(event.target.value))} error={errors['layout.margin_mm']} />
                                <FormField label="Tamano fuente" name="font_size" type="number" min="8" max="18" value={data.layout.font_size} onChange={(event) => setLayout(['font_size'], Number(event.target.value))} error={errors['layout.font_size']} />
                                <SelectField label="Fuente" name="font_family" value={data.layout.font_family} onChange={(event) => setLayout(['font_family'], event.target.value)} error={errors['layout.font_family']}>
                                    <option value="monospace">Monoespaciada</option>
                                    <option value="Arial, sans-serif">Arial</option>
                                    <option value="Georgia, serif">Serif</option>
                                </SelectField>
                                <SelectField label="Branding" name="use_branding" value={data.use_branding ? '1' : '0'} onChange={(event) => setData('use_branding', event.target.value === '1')} error={errors.use_branding}>
                                    <option value="1">Usar colores/logo de sucursal</option>
                                    <option value="0">Usar colores/logo de plantilla</option>
                                </SelectField>
                                <SelectField label="Predeterminada" name="is_default" value={data.is_default ? '1' : '0'} onChange={(event) => setData('is_default', event.target.value === '1')} error={errors.is_default}>
                                    <option value="1">Si</option>
                                    <option value="0">No</option>
                                </SelectField>
                            </div>
                        </Panel>

                        <Panel title="Logo y colores">
                            <div className="grid gap-5 sm:grid-cols-3">
                                <FormField label="Ruta logo" name="logo_path" value={data.layout.logo.path ?? ''} onChange={(event) => setLayout(['logo', 'path'], event.target.value)} error={errors['layout.logo.path']} />
                                <FormField label="Ancho logo mm" name="logo_width" type="number" value={data.layout.logo.width_mm} onChange={(event) => setLayout(['logo', 'width_mm'], Number(event.target.value))} error={errors['layout.logo.width_mm']} />
                                <SelectField label="Posicion logo" name="logo_position" value={data.layout.logo.position} onChange={(event) => setLayout(['logo', 'position'], event.target.value)} error={errors['layout.logo.position']}>
                                    <option value="left">Izquierda</option>
                                    <option value="center">Centro</option>
                                    <option value="right">Derecha</option>
                                </SelectField>
                                <SelectField label="Mostrar logo" name="logo_show" value={data.layout.logo.show ? '1' : '0'} onChange={(event) => setLayout(['logo', 'show'], event.target.value === '1')} error={errors['layout.logo.show']}>
                                    <option value="1">Si</option>
                                    <option value="0">No</option>
                                </SelectField>
                                <FormField label="Color primario" name="primary_color" type="color" value={data.layout.colors.primary} onChange={(event) => setLayout(['colors', 'primary'], event.target.value)} error={errors['layout.colors.primary']} />
                                <FormField label="Color secundario" name="secondary_color" type="color" value={data.layout.colors.secondary} onChange={(event) => setLayout(['colors', 'secondary'], event.target.value)} error={errors['layout.colors.secondary']} />
                            </div>
                        </Panel>

                        <Panel title="Campos visibles">
                            <div className="space-y-5">
                                {FIELD_GROUPS.map((group) => (
                                    <div key={group.title}>
                                        <h4 className="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">{group.title}</h4>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            {group.fields.map((field) => (
                                                <FieldToggle key={field} field={field} label={FIELD_LABELS[field] ?? field} value={fieldValue(data.layout.fields, field)} onChange={setField} />
                                            ))}
                                        </div>
                                    </div>
                                ))}

                                {attributeFields.length ? (
                                    <div>
                                        <h4 className="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Caracteristicas de productos</h4>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            {attributeFields.map((attribute) => (
                                                <FieldToggle
                                                    key={attribute.field}
                                                    field={attribute.field}
                                                    label={attribute.label}
                                                    value={fieldValue(data.layout.fields, attribute.field)}
                                                    onChange={setField}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        </Panel>

                        <Panel title="Secciones y posicion">
                            <div className="space-y-3">
                                {data.layout.sections.map((section, index) => (
                                    <div
                                        key={section.key}
                                        draggable
                                        onDragStart={() => setDraggedSection(index)}
                                        onDragOver={(event) => event.preventDefault()}
                                        onDrop={() => dropSection(index)}
                                        className={[
                                            'grid gap-3 rounded-md border p-3 transition dark:border-slate-800 sm:grid-cols-[44px_1fr_140px]',
                                            draggedSection === index ? 'border-brand-primary bg-brand-primary/5' : 'border-slate-200 bg-white dark:bg-slate-950/30',
                                        ].join(' ')}
                                    >
                                        <div className="flex items-center justify-center rounded-md border border-slate-200 text-slate-500 dark:border-slate-700" title="Arrastrar para ordenar">
                                            ::
                                        </div>
                                        <div>
                                            <label className="flex items-center gap-2 text-sm font-medium">
                                                <input type="checkbox" checked={section.show} onChange={(event) => setSection(index, 'show', event.target.checked)} />
                                                {section.label}
                                            </label>
                                            <p className="mt-1 text-xs text-slate-500">Posicion {section.order} - {section.key}</p>
                                        </div>
                                        <div className="flex items-center justify-end gap-2">
                                            <button type="button" onClick={() => moveSection(index, index - 1)} className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-700" disabled={index === 0}>
                                                Subir
                                            </button>
                                            <button type="button" onClick={() => moveSection(index, index + 1)} className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-700" disabled={index === data.layout.sections.length - 1}>
                                                Bajar
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Panel>

                        <div className="flex items-center gap-3">
                            <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar plantilla' : 'Crear plantilla'}</PrimaryButton>
                            <Link href={route('sales.templates.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                        </div>
                    </div>

                    <Preview data={data} />
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function fieldValue(fields, field) {
    if (Object.hasOwn(fields ?? {}, field)) {
        return Boolean(fields[field]);
    }

    return field !== 'item_lot';
}

function FieldToggle({ field, label, value, onChange }) {
    return (
        <label className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
            <input type="checkbox" checked={value} onChange={(event) => onChange(field, event.target.checked)} />
            <span>{label}</span>
        </label>
    );
}

function normalizeSectionOrder(sections) {
    return sections.map((section, index) => ({
        ...section,
        order: index + 1,
    }));
}

function Panel({ title, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {children}
        </section>
    );
}

function Preview({ data }) {
    const width = data.paper_type === 'thermal' ? `${data.thermal_width_mm}mm` : '100%';

    return (
        <aside className="hidden lg:block">
            <div className="sticky top-6 rounded-lg border border-slate-200 bg-slate-100 p-4 dark:border-slate-800 dark:bg-slate-950">
                <div
                    className="mx-auto bg-white p-4 text-black shadow"
                    style={{
                        width,
                        maxWidth: '100%',
                        minHeight: data.paper_type === 'thermal' ? '360px' : '520px',
                        fontFamily: data.layout.font_family,
                        fontSize: `${data.layout.font_size}px`,
                    }}
                >
                    <p className="text-center font-bold" style={{ color: data.layout.colors.primary }}>FABRICA DE CALAMINAS</p>
                    <p className="text-center text-xs">Direccion / telefonos</p>
                    <div className="mt-3 border-t border-black pt-2 text-right text-xs">NOTA DE VENTA<br />Nro.: 000001</div>
                    <div className="mt-3 grid grid-cols-2 gap-1 border-t border-black pt-2 text-xs">
                        <span>Cliente</span><span>Moneda</span><span>Vendedor</span><span>Tipo</span>
                    </div>
                    <div className="mt-3 border-y border-black py-1 text-xs">Cant. Descripcion P.Unit Total</div>
                    <div className="mt-16 border-t border-black pt-2 text-right text-xs">Total / Anticipo / Saldo</div>
                </div>
            </div>
        </aside>
    );
}
