import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import ContextHelp from '../../../../../Shared/Resources/Components/ContextHelp';

const sectionOrder = [
    ['customFields', 'custom-fields', 'Campos personalizados'],
    ['states', 'states', 'Estados'],
    ['resources', 'resources', 'Recursos'],
    ['priceLists', 'price-lists', 'Precios'],
    ['commissions', 'commissions', 'Comisiones'],
    ['notifications', 'notifications', 'Notificaciones'],
    ['currencies', 'currencies', 'Monedas'],
    ['printers', 'printers', 'Impresoras'],
    ['licenses', 'licenses', 'Licencias'],
    ['imports', 'imports', 'Importaciones'],
];

const emptyForms = {
    customFields: { entity_type: 'product', code: '', label: '', type: 'text', options_csv: '', validation_rules_text: '', is_required: false, visible_in_forms: true, visible_in_documents: false, visible_in_reports: false, sort_order: 0, is_active: true },
    states: { entity_type: 'service_order', code: '', label: '', color: '#2563eb', is_initial: false, is_final: false, allowed_transitions_csv: '', required_permission: '', actions_text: '', sort_order: 0, is_active: true },
    resources: { branch_id: '', type: 'table', code: '', name: '', capacity: '', availability_rules_text: '', metadata_text: '', is_active: true },
    priceLists: { branch_id: '', code: '', name: '', channel: 'counter', currency_code: 'BOB', starts_at: '', ends_at: '', rules_text: '', is_active: true },
    commissions: { branch_id: '', role_name: '', responsible_type: 'seller', product_id: '', calculation_base: 'subtotal', type: 'percentage', value: 0, conditions_text: '', is_active: true },
    notifications: { code: '', name: '', trigger: 'stock_low', channels_csv: 'system', recipients_text: '', conditions_text: '', is_active: true },
    currencies: { code: '', name: '', symbol: '', exchange_rate_to_base: 1, rounding_decimals: 2, is_base: false, cash_enabled: true, is_active: true, metadata_text: '' },
    printers: { branch_id: '', code: '', name: '', area: 'cashier', paper_type: 'letter', thermal_width_mm: '', copies: 1, auto_print: false, settings_text: '', is_active: true },
    licenses: { module: 'sales_notes', is_enabled: true, max_branches: '', max_users: '', max_pos_terminals: '', support_until: '', metadata_text: '' },
    imports: { code: '', name: '', entity_type: 'products', required_columns_csv: '', optional_columns_csv: '', mapping_rules_text: '', validation_rules_text: '', is_active: true },
};

export default function Index({
    summary = {},
    customFields = [],
    states = [],
    resources = [],
    priceLists = [],
    commissions = [],
    notifications = [],
    currencies = [],
    printers = [],
    licenses = [],
    imports = [],
    branches = [],
    products = [],
    options = {},
}) {
    const [activeSection, setActiveSection] = useState('customFields');
    const [editing, setEditing] = useState(null);
    const currentRouteSection = sectionOrder.find(([key]) => key === activeSection)?.[1] ?? 'custom-fields';
    const rows = { customFields, states, resources, priceLists, commissions, notifications, currencies, printers, licenses, imports }[activeSection] ?? [];
    const form = useForm(emptyForms[activeSection]);

    const sectionLabel = sectionOrder.find(([key]) => key === activeSection)?.[2] ?? 'Configuracion';
    const helpText = useMemo(() => sectionHelp(activeSection), [activeSection]);

    const switchSection = (section) => {
        setActiveSection(section);
        setEditing(null);
        form.setData(emptyForms[section]);
        form.clearErrors();
    };

    const submit = (event) => {
        event.preventDefault();
        const endpoint = editing
            ? route('system-superadmin.transversal-config.update', [currentRouteSection, editing.id])
            : route('system-superadmin.transversal-config.store', currentRouteSection);

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditing(null);
                form.setData(emptyForms[activeSection]);
            },
        };

        if (editing) {
            form.put(endpoint, options);
            return;
        }

        form.post(endpoint, options);
    };

    const editRow = (row) => {
        setEditing(row);
        form.setData(formFromRow(activeSection, row));
        form.clearErrors();
    };

    const deactivate = (row) => {
        router.delete(route('system-superadmin.transversal-config.destroy', [currentRouteSection, row.id]), {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Configuracion transversal</h2>}>
            <Head title="Configuracion transversal" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Configuracion transversal"
                    description="Gestiona piezas reutilizables para distintos tipos de negocio sin cambiar el perfil activo de ferreteria hasta que se conecten en fases posteriores."
                />

                <div className="mb-6 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    Esta seccion solo la ve el rol interno sistemasuperadmin. Crear estos datos no modifica ventas, compras ni stock actuales; quedan listos para usar cuando el perfil de negocio active cada capacidad.
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {sectionOrder.map(([key, , label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => switchSection(key)}
                            className={`rounded-2xl border p-4 text-left transition ${activeSection === key ? 'border-brand-primary bg-brand-primary text-white shadow-lg shadow-brand-primary/20' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-primary/50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200'}`}
                        >
                            <p className="text-sm font-bold">{label}</p>
                            <p className={`mt-1 text-xs ${activeSection === key ? 'text-white/80' : 'text-slate-500 dark:text-slate-400'}`}>
                                {summary[sectionOrder.find((item) => item[0] === key)?.[1]?.replaceAll('-', '_')] ?? rows.length} registros
                            </p>
                        </button>
                    ))}
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[0.82fr_1.18fr]">
                    <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-5 flex items-start justify-between gap-4">
                            <div>
                                <h3 className="flex items-center gap-2 text-lg font-semibold text-slate-950 dark:text-white">
                                    {editing ? 'Editar' : 'Nuevo'}: {sectionLabel}
                                    <ContextHelp title={`Ayuda de ${sectionLabel}`}>{helpText}</ContextHelp>
                                </h3>
                                <p className="mt-1 text-sm leading-6 text-slate-500 dark:text-slate-400">{helpText}</p>
                            </div>
                            {editing ? (
                                <button type="button" onClick={() => { setEditing(null); form.setData(emptyForms[activeSection]); }} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-300">
                                    Cancelar
                                </button>
                            ) : null}
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <DynamicFormFields section={activeSection} form={form} options={options} branches={branches} products={products} />
                        </div>

                        <button type="submit" disabled={form.processing} className="mt-5 w-full rounded-2xl bg-brand-primary px-4 py-3 text-sm font-bold text-white shadow-sm shadow-brand-primary/20 disabled:opacity-60">
                            {editing ? 'Guardar cambios' : 'Crear registro'}
                        </button>
                    </form>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-lg font-semibold text-slate-950 dark:text-white">Registros de {sectionLabel}</h3>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Se muestran hasta 80 registros recientes para mantener la pantalla ligera.</p>
                            </div>
                        </div>

                        <div className="responsive-table overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-xs uppercase tracking-[0.14em] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                        {columnsFor(activeSection).map((column) => <th key={column.key} className="px-3 py-3">{column.label}</th>)}
                                        <th className="px-3 py-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {rows.length === 0 ? (
                                        <tr>
                                            <td className="px-3 py-8 text-center text-slate-500 dark:text-slate-400" colSpan={columnsFor(activeSection).length + 1}>
                                                Aun no hay registros para esta seccion.
                                            </td>
                                        </tr>
                                    ) : rows.map((row) => (
                                        <tr key={row.id} className="align-top">
                                            {columnsFor(activeSection).map((column) => (
                                                <td key={column.key} className="px-3 py-3 text-slate-700 dark:text-slate-200">
                                                    {formatValue(row, column.key)}
                                                </td>
                                            ))}
                                            <td className="px-3 py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <button type="button" onClick={() => editRow(row)} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-300">
                                                        Editar
                                                    </button>
                                                    <button type="button" onClick={() => deactivate(row)} className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                                                        Desactivar
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function DynamicFormFields({ section, form, options, branches, products }) {
    const set = (key, value) => form.setData(key, value);
    const bool = (key, label, helpTooltip = null) => (
        <label className="flex min-h-[3.25rem] items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 dark:border-slate-800 dark:bg-white/5 dark:text-slate-200">
            <span className="flex items-center gap-2">{label}<ContextHelp>{helpTooltip}</ContextHelp></span>
            <input type="checkbox" checked={Boolean(form.data[key])} onChange={(event) => set(key, event.target.checked)} className="h-5 w-5 rounded-md border-slate-300 text-brand-primary focus:ring-brand-primary" />
        </label>
    );

    if (section === 'customFields') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. Ejemplo: placa_vehiculo." />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} error={form.errors.label} />
                <OptionSelect label="Tipo" value={form.data.type} onChange={(value) => set('type', value)} options={options.fieldTypes} />
                <FormField label="Opciones separadas por coma" value={form.data.options_csv} onChange={(event) => set('options_csv', event.target.value)} helpText="Solo aplica para seleccion unica o multiple." />
                <FormField label="Validacion JSON opcional" value={form.data.validation_rules_text} onChange={(event) => set('validation_rules_text', event.target.value)} />
                {bool('is_required', 'Obligatorio')}
                {bool('visible_in_forms', 'Visible en formularios')}
                {bool('visible_in_documents', 'Visible en documentos')}
                {bool('visible_in_reports', 'Visible en reportes')}
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'states') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} />
                <FormField label="Color" type="color" value={form.data.color} onChange={(event) => set('color', event.target.value)} />
                {bool('is_initial', 'Estado inicial')}
                {bool('is_final', 'Estado final')}
                <FormField label="Transiciones permitidas" value={form.data.allowed_transitions_csv} onChange={(event) => set('allowed_transitions_csv', event.target.value)} helpText="Codigos separados por coma. Ejemplo: en_proceso,listo." />
                <FormField label="Permiso requerido" value={form.data.required_permission} onChange={(event) => set('required_permission', event.target.value)} />
                <FormField label="Acciones JSON" value={form.data.actions_text} onChange={(event) => set('actions_text', event.target.value)} />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'resources') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <OptionSelect label="Tipo de recurso" value={form.data.type} onChange={(value) => set('type', value)} options={options.resourceTypes} />
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <FormField label="Capacidad" type="number" value={form.data.capacity} onChange={(event) => set('capacity', event.target.value)} />
                <FormField label="Disponibilidad JSON" value={form.data.availability_rules_text} onChange={(event) => set('availability_rules_text', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'priceLists') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <OptionSelect label="Canal" value={form.data.channel} onChange={(value) => set('channel', value)} options={options.channels} />
                <FormField label="Moneda" value={form.data.currency_code} onChange={(event) => set('currency_code', event.target.value)} />
                <FormField label="Inicio" type="datetime-local" value={form.data.starts_at} onChange={(event) => set('starts_at', event.target.value)} />
                <FormField label="Fin" type="datetime-local" value={form.data.ends_at} onChange={(event) => set('ends_at', event.target.value)} />
                <FormField label="Reglas JSON" value={form.data.rules_text} onChange={(event) => set('rules_text', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'commissions') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <FormField label="Rol" value={form.data.role_name} onChange={(event) => set('role_name', event.target.value)} />
                <OptionSelect label="Responsable" value={form.data.responsible_type} onChange={(value) => set('responsible_type', value)} options={options.responsibleTypes} />
                <ProductSelect value={form.data.product_id} onChange={(value) => set('product_id', value)} products={products} />
                <OptionSelect label="Base" value={form.data.calculation_base} onChange={(value) => set('calculation_base', value)} options={options.commissionBases} />
                <OptionSelect label="Tipo" value={form.data.type} onChange={(value) => set('type', value)} options={options.commissionTypes} />
                <FormField label="Valor" type="number" step="0.0001" value={form.data.value} onChange={(event) => set('value', event.target.value)} />
                <FormField label="Condiciones JSON" value={form.data.conditions_text} onChange={(event) => set('conditions_text', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'notifications') {
        return (
            <>
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <OptionSelect label="Disparador" value={form.data.trigger} onChange={(value) => set('trigger', value)} options={options.notificationTriggers} />
                <FormField label="Canales" value={form.data.channels_csv} onChange={(event) => set('channels_csv', event.target.value)} helpText="Ejemplo: system,email." />
                <FormField label="Destinatarios JSON" value={form.data.recipients_text} onChange={(event) => set('recipients_text', event.target.value)} />
                <FormField label="Condiciones JSON" value={form.data.conditions_text} onChange={(event) => set('conditions_text', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'currencies') {
        return (
            <>
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <FormField label="Simbolo" value={form.data.symbol} onChange={(event) => set('symbol', event.target.value)} />
                <FormField label="Tipo de cambio a moneda base" type="number" step="0.00000001" value={form.data.exchange_rate_to_base} onChange={(event) => set('exchange_rate_to_base', event.target.value)} />
                <FormField label="Decimales de redondeo" type="number" value={form.data.rounding_decimals} onChange={(event) => set('rounding_decimals', event.target.value)} />
                {bool('is_base', 'Moneda base', 'Solo una moneda puede quedar como base.')}
                {bool('cash_enabled', 'Disponible en caja')}
                {bool('is_active', 'Activo')}
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
            </>
        );
    }

    if (section === 'printers') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <OptionSelect label="Area" value={form.data.area} onChange={(value) => set('area', value)} options={options.printerAreas} />
                <OptionSelect label="Papel" value={form.data.paper_type} onChange={(value) => set('paper_type', value)} options={options.paperTypes} />
                <FormField label="Ancho termico mm" type="number" value={form.data.thermal_width_mm} onChange={(event) => set('thermal_width_mm', event.target.value)} />
                <FormField label="Copias" type="number" value={form.data.copies} onChange={(event) => set('copies', event.target.value)} />
                {bool('auto_print', 'Impresion automatica')}
                {bool('is_active', 'Activo')}
                <FormField label="Configuracion JSON" value={form.data.settings_text} onChange={(event) => set('settings_text', event.target.value)} />
            </>
        );
    }

    if (section === 'licenses') {
        return (
            <>
                <OptionSelect label="Modulo" value={form.data.module} onChange={(value) => set('module', value)} options={options.modules} />
                {bool('is_enabled', 'Modulo contratado')}
                <FormField label="Maximo sucursales" type="number" value={form.data.max_branches} onChange={(event) => set('max_branches', event.target.value)} />
                <FormField label="Maximo usuarios" type="number" value={form.data.max_users} onChange={(event) => set('max_users', event.target.value)} />
                <FormField label="Maximo puntos POS" type="number" value={form.data.max_pos_terminals} onChange={(event) => set('max_pos_terminals', event.target.value)} />
                <FormField label="Soporte hasta" type="date" value={form.data.support_until} onChange={(event) => set('support_until', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
            </>
        );
    }

    return (
        <>
            <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
            <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
            <OptionSelect label="Entidad a importar" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.importEntities} />
            <FormField label="Columnas obligatorias" value={form.data.required_columns_csv} onChange={(event) => set('required_columns_csv', event.target.value)} helpText="Separadas por coma. Ejemplo: nombre,codigo,precio." />
            <FormField label="Columnas opcionales" value={form.data.optional_columns_csv} onChange={(event) => set('optional_columns_csv', event.target.value)} />
            <FormField label="Mapeo JSON" value={form.data.mapping_rules_text} onChange={(event) => set('mapping_rules_text', event.target.value)} />
            <FormField label="Validacion JSON" value={form.data.validation_rules_text} onChange={(event) => set('validation_rules_text', event.target.value)} />
            {bool('is_active', 'Activo')}
        </>
    );
}

function OptionSelect({ label, value, onChange, options = {} }) {
    return (
        <SelectField label={label} value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            {Object.entries(options).map(([key, text]) => <option key={key} value={key}>{text}</option>)}
        </SelectField>
    );
}

function BranchSelect({ value, onChange, branches }) {
    return (
        <SelectField label="Sucursal" value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">Global</option>
            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
        </SelectField>
    );
}

function ProductSelect({ value, onChange, products }) {
    return (
        <SelectField label="Producto" value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">Todos</option>
            {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
        </SelectField>
    );
}

function sectionHelp(section) {
    return {
        customFields: 'Permite agregar datos propios por entidad, por ejemplo placa en taller, historia clinica en servicios o talla en tienda.',
        states: 'Define estados y transiciones para reservas, comandas, servicios o produccion sin escribir codigo nuevo.',
        resources: 'Registra mesas, tecnicos, habitaciones, equipos, vehiculos o cualquier recurso que pueda reservarse.',
        priceLists: 'Configura precios por canal, sucursal, cliente u horario. Las reglas se aplicaran cuando el perfil active precios avanzados.',
        commissions: 'Define comisiones por vendedor, mesero, tecnico, producto, margen o cobro.',
        notifications: 'Configura alertas operativas como stock bajo, reservas proximas, pagos vencidos, SIAT fallido o backup fallido.',
        currencies: 'Gestiona moneda base, monedas permitidas, redondeos y disponibilidad en caja.',
        printers: 'Separa impresoras por area: caja, cocina, barra, etiquetas, factura o reporte de cierre.',
        licenses: 'Controla modulos contratados, limites de sucursales, usuarios, POS y fecha de soporte.',
        imports: 'Define plantillas de importacion para productos, servicios, stock inicial, clientes, recetas o recursos.',
    }[section] ?? 'Configuracion transversal.';
}

function columnsFor(section) {
    return {
        customFields: [{ key: 'label', label: 'Nombre' }, { key: 'entity_type', label: 'Entidad' }, { key: 'type', label: 'Tipo' }, { key: 'is_active', label: 'Estado' }],
        states: [{ key: 'label', label: 'Estado' }, { key: 'entity_type', label: 'Entidad' }, { key: 'is_initial', label: 'Inicial' }, { key: 'is_final', label: 'Final' }],
        resources: [{ key: 'name', label: 'Recurso' }, { key: 'type', label: 'Tipo' }, { key: 'branch.name', label: 'Sucursal' }, { key: 'is_active', label: 'Estado' }],
        priceLists: [{ key: 'name', label: 'Lista' }, { key: 'channel', label: 'Canal' }, { key: 'currency_code', label: 'Moneda' }, { key: 'is_active', label: 'Estado' }],
        commissions: [{ key: 'responsible_type', label: 'Responsable' }, { key: 'calculation_base', label: 'Base' }, { key: 'type', label: 'Tipo' }, { key: 'value', label: 'Valor' }],
        notifications: [{ key: 'name', label: 'Alerta' }, { key: 'trigger', label: 'Disparador' }, { key: 'channels', label: 'Canales' }, { key: 'is_active', label: 'Estado' }],
        currencies: [{ key: 'code', label: 'Codigo' }, { key: 'name', label: 'Moneda' }, { key: 'exchange_rate_to_base', label: 'Cambio' }, { key: 'is_base', label: 'Base' }],
        printers: [{ key: 'name', label: 'Impresora' }, { key: 'area', label: 'Area' }, { key: 'paper_type', label: 'Papel' }, { key: 'auto_print', label: 'Auto' }],
        licenses: [{ key: 'module', label: 'Modulo' }, { key: 'is_enabled', label: 'Activo' }, { key: 'max_users', label: 'Usuarios' }, { key: 'support_until', label: 'Soporte' }],
        imports: [{ key: 'name', label: 'Plantilla' }, { key: 'entity_type', label: 'Entidad' }, { key: 'required_columns', label: 'Obligatorias' }, { key: 'is_active', label: 'Estado' }],
    }[section] ?? [];
}

function formatValue(row, key) {
    const value = key.split('.').reduce((current, part) => current?.[part], row);

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'Si' : 'No';
    }

    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function formFromRow(section, row) {
    const base = { ...emptyForms[section] };

    if (section === 'customFields') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'label', 'type', 'is_required', 'visible_in_forms', 'visible_in_documents', 'visible_in_reports', 'sort_order', 'is_active']), options_csv: (row.options ?? []).join(', '), validation_rules_text: stringify(row.validation_rules) };
    }

    if (section === 'states') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'label', 'color', 'is_initial', 'is_final', 'required_permission', 'sort_order', 'is_active']), allowed_transitions_csv: (row.allowed_transitions ?? []).join(', '), actions_text: stringify(row.actions) };
    }

    if (section === 'resources') {
        return { ...base, ...pick(row, ['branch_id', 'type', 'code', 'name', 'capacity', 'is_active']), availability_rules_text: stringify(row.availability_rules), metadata_text: stringify(row.metadata) };
    }

    if (section === 'priceLists') {
        return { ...base, ...pick(row, ['branch_id', 'code', 'name', 'channel', 'currency_code', 'is_active']), starts_at: toInputDateTime(row.starts_at), ends_at: toInputDateTime(row.ends_at), rules_text: stringify(row.rules) };
    }

    if (section === 'commissions') {
        return { ...base, ...pick(row, ['branch_id', 'role_name', 'responsible_type', 'product_id', 'calculation_base', 'type', 'value', 'is_active']), conditions_text: stringify(row.conditions) };
    }

    if (section === 'notifications') {
        return { ...base, ...pick(row, ['code', 'name', 'trigger', 'is_active']), channels_csv: (row.channels ?? []).join(', '), recipients_text: stringify(row.recipients), conditions_text: stringify(row.conditions) };
    }

    if (section === 'currencies') {
        return { ...base, ...pick(row, ['code', 'name', 'symbol', 'exchange_rate_to_base', 'rounding_decimals', 'is_base', 'cash_enabled', 'is_active']), metadata_text: stringify(row.metadata) };
    }

    if (section === 'printers') {
        return { ...base, ...pick(row, ['branch_id', 'code', 'name', 'area', 'paper_type', 'thermal_width_mm', 'copies', 'auto_print', 'is_active']), settings_text: stringify(row.settings) };
    }

    if (section === 'licenses') {
        return { ...base, ...pick(row, ['module', 'is_enabled', 'max_branches', 'max_users', 'max_pos_terminals']), support_until: row.support_until ?? '', metadata_text: stringify(row.metadata) };
    }

    return { ...base, ...pick(row, ['code', 'name', 'entity_type', 'is_active']), required_columns_csv: (row.required_columns ?? []).join(', '), optional_columns_csv: (row.optional_columns ?? []).join(', '), mapping_rules_text: stringify(row.mapping_rules), validation_rules_text: stringify(row.validation_rules) };
}

function pick(row, keys) {
    return keys.reduce((carry, key) => ({ ...carry, [key]: row[key] ?? '' }), {});
}

function stringify(value) {
    return value ? JSON.stringify(value) : '';
}

function toInputDateTime(value) {
    if (!value) {
        return '';
    }

    return String(value).slice(0, 16);
}
