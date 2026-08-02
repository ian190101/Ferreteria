import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import ContextHelp from '../../../../../Shared/Resources/Components/ContextHelp';

const sectionOrder = [
    ['entities', 'entities', 'Entidades'],
    ['relationships', 'relationships', 'Relaciones'],
    ['attachments', 'attachments', 'Adjuntos'],
    ['forms', 'forms', 'Formularios'],
    ['formFields', 'form-fields', 'Campos por formulario'],
    ['documentTemplates', 'document-templates', 'Documentos 2.0'],
    ['reportTemplates', 'report-templates', 'Reportes 2.0'],
    ['calculationFormulas', 'calculation-formulas', 'Formulas'],
    ['customFields', 'custom-fields', 'Campos personalizados'],
    ['workflows', 'workflows', 'Flujos'],
    ['states', 'states', 'Estados'],
    ['workflowTransitions', 'workflow-transitions', 'Transiciones'],
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
    entities: { code: '', label: '', plural_label: '', base_entity: '', module: '', icon: '', mode: 'optional', is_visible: true, is_editable: true, is_required: false, is_exportable: false, is_reportable: false, is_auditable: true, is_sensitive: false, retention_policy: 'standard', permissions_text: '', settings_text: '', sort_order: 0, is_active: true },
    relationships: { source_entity_type: 'customer', target_entity_type: 'product', code: '', label: '', type: 'one_to_many', inverse_label: '', is_required: false, allows_multiple: true, min_items: '', max_items: '', cascade_behavior: 'restrict', permissions_text: '', metadata_text: '', sort_order: 0, is_active: true },
    attachments: { entity_type: 'customer', code: '', label: '', purpose: 'evidence', allowed_extensions_csv: 'jpg,png,pdf', allowed_mime_types_csv: 'image/jpeg,image/png,application/pdf', max_file_mb: 10, storage_disk: 'local', path_prefix: 'business-attachments', is_required: false, is_sensitive: false, requires_signed_url: true, audit_downloads: true, visible_in_documents: false, visible_in_reports: false, permissions_text: '', metadata_text: '', retention_policy: 'standard', sort_order: 0, is_active: true },
    forms: { entity_type: 'customer', code: '', name: '', flow: 'customer_create', workflow_code: '', state_code: '', surface: 'form', description: '', submit_label: 'Guardar', layout_text: '', permissions_text: '', validations_text: '', metadata_text: '', sort_order: 0, is_active: true },
    formFields: { dynamic_form_definition_id: '', field_code: '', label_override: '', help_text: '', placeholder: '', is_required: false, is_visible: true, is_read_only: false, default_value_text: '', validation_rules_text: '', visibility_conditions_text: '', required_conditions_text: '', options_override_csv: '', sort_order: 0, is_active: true },
    documentTemplates: { branch_id: '', document_type: 'sale_note', entity_type: '', code: '', name: '', paper_type: 'letter', thermal_width_mm: '', printer_area: 'cashier', copies: 1, layout_text: '', fields_csv: 'empresa,numero,cliente,items,total', columns_csv: 'descripcion,cantidad,precio,subtotal', legal_text: '', terms_text: '', permissions_text: '', metadata_text: '', is_default: false, is_active: true },
    reportTemplates: { code: '', name: '', module: 'sales', entity_type: '', description: '', columns_csv: 'fecha,documento,cliente,total', filters_text: '', groupings_csv: '', metrics_text: '', permissions_text: '', metadata_text: '', cache_ttl_minutes: 10, is_exportable: false, is_default: false, is_active: true },
    calculationFormulas: { entity_type: '', code: '', name: '', description: '', result_type: 'decimal', expression_text: '{"op":"multiply","args":[{"var":"cantidad"},{"var":"precio"}]}', variables_text: '[{"code":"cantidad","label":"Cantidad"},{"code":"precio","label":"Precio"}]', precision: 2, permissions_text: '', metadata_text: '', is_active: true },
    customFields: { entity_type: 'product', code: '', label: '', help_text: '', placeholder: '', type: 'text', group: '', options_csv: '', validation_rules_text: '', default_value_text: '', format: '', min_value: '', max_value: '', relation_entity_type: '', metadata_text: '', is_required: false, visible_in_forms: true, visible_in_table: false, visible_in_documents: false, visible_in_reports: false, is_exportable: false, is_auditable: true, is_sensitive: false, is_encrypted: false, is_read_only: false, sort_order: 0, is_active: true },
    workflows: { entity_type: 'service_order', code: '', name: '', description: '', initial_state_code: '', final_state_codes_csv: '', settings_text: '', is_default: false, is_active: true },
    states: { entity_type: 'service_order', workflow_code: '', code: '', label: '', color: '#2563eb', state_type: 'intermediate', is_initial: false, is_final: false, allowed_transitions_csv: '', required_permission: '', entry_validations_text: '', actions_text: '', exit_actions_text: '', sort_order: 0, is_active: true },
    workflowTransitions: { workflow_definition_id: '', from_state_code: '', to_state_code: '', label: '', required_permission: '', conditions_text: '', validations_text: '', actions_text: '', requires_reason: false, is_reversible: false, sort_order: 0, is_active: true },
    resources: { branch_id: '', assigned_worker_id: '', type: 'table', code: '', name: '', capacity: '', status: 'available', location: '', maintenance_starts_at: '', maintenance_ends_at: '', availability_rules_text: '', metadata_text: '', is_active: true },
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
    entities = [],
    relationships = [],
    attachments = [],
    forms = [],
    formFields = [],
    documentTemplates = [],
    reportTemplates = [],
    calculationFormulas = [],
    customFields = [],
    workflows = [],
    states = [],
    workflowTransitions = [],
    resources = [],
    priceLists = [],
    commissions = [],
    notifications = [],
    currencies = [],
    printers = [],
    licenses = [],
    imports = [],
    branches = [],
    workers = [],
    products = [],
    options = {},
}) {
    const [activeSection, setActiveSection] = useState('customFields');
    const [editing, setEditing] = useState(null);
    const currentRouteSection = sectionOrder.find(([key]) => key === activeSection)?.[1] ?? 'custom-fields';
    const rows = { entities, relationships, attachments, forms, formFields, documentTemplates, reportTemplates, calculationFormulas, customFields, workflows, states, workflowTransitions, resources, priceLists, commissions, notifications, currencies, printers, licenses, imports }[activeSection] ?? [];
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
                            <DynamicFormFields section={activeSection} form={form} options={options} branches={branches} workers={workers} products={products} forms={forms} />
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

function DynamicFormFields({ section, form, options, branches, workers, products, forms }) {
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
                <FormField label="Ayuda para el usuario" value={form.data.help_text} onChange={(event) => set('help_text', event.target.value)} />
                <FormField label="Placeholder" value={form.data.placeholder} onChange={(event) => set('placeholder', event.target.value)} />
                <OptionSelect label="Tipo" value={form.data.type} onChange={(value) => set('type', value)} options={options.fieldTypes} />
                <FormField label="Grupo o seccion" value={form.data.group} onChange={(event) => set('group', event.target.value)} helpText="Ejemplo: Datos clinicos, Vehiculo, Garantia." />
                <FormField label="Opciones separadas por coma" value={form.data.options_csv} onChange={(event) => set('options_csv', event.target.value)} helpText="Solo aplica para seleccion unica o multiple." />
                <FormField label="Validacion JSON opcional" value={form.data.validation_rules_text} onChange={(event) => set('validation_rules_text', event.target.value)} />
                <FormField label="Valor por defecto JSON/texto" value={form.data.default_value_text} onChange={(event) => set('default_value_text', event.target.value)} />
                <FormField label="Formato" value={form.data.format} onChange={(event) => set('format', event.target.value)} helpText="Ejemplo: placa, celular, ci, moneda." />
                <FormField label="Valor minimo" type="number" step="0.000001" value={form.data.min_value} onChange={(event) => set('min_value', event.target.value)} />
                <FormField label="Valor maximo" type="number" step="0.000001" value={form.data.max_value} onChange={(event) => set('max_value', event.target.value)} />
                <OptionSelect label="Entidad relacionada" value={form.data.relation_entity_type} onChange={(value) => set('relation_entity_type', value)} options={{ '': 'Sin relacion', ...(options.entities ?? {}) }} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                {bool('is_required', 'Obligatorio')}
                {bool('visible_in_forms', 'Visible en formularios')}
                {bool('visible_in_table', 'Visible en tabla')}
                {bool('visible_in_documents', 'Visible en documentos')}
                {bool('visible_in_reports', 'Visible en reportes')}
                {bool('is_exportable', 'Exportable')}
                {bool('is_auditable', 'Auditable')}
                {bool('is_sensitive', 'Dato sensible')}
                {bool('is_encrypted', 'Cifrar valor')}
                {bool('is_read_only', 'Solo lectura')}
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'entities') {
        return (
            <>
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. No puede repetir una entidad base como product o customer." />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} error={form.errors.label} />
                <FormField label="Nombre plural" value={form.data.plural_label} onChange={(event) => set('plural_label', event.target.value)} />
                <OptionSelect label="Entidad base opcional" value={form.data.base_entity} onChange={(value) => set('base_entity', value)} options={{ '': 'Sin entidad base', ...(options.baseEntities ?? options.entities ?? {}) }} />
                <OptionSelect label="Modulo relacionado" value={form.data.module} onChange={(value) => set('module', value)} options={{ '': 'Sin modulo directo', ...(options.modules ?? {}) }} />
                <FormField label="Icono lucide opcional" value={form.data.icon} onChange={(event) => set('icon', event.target.value)} helpText="Ejemplo: Stethoscope, Car, PawPrint." />
                <OptionSelect label="Modo" value={form.data.mode} onChange={(value) => set('mode', value)} options={options.entityModes} />
                <OptionSelect label="Retencion" value={form.data.retention_policy} onChange={(value) => set('retention_policy', value)} options={options.retentionPolicies} />
                {bool('is_visible', 'Visible')}
                {bool('is_editable', 'Editable')}
                {bool('is_required', 'Obligatoria')}
                {bool('is_exportable', 'Exportable')}
                {bool('is_reportable', 'Reportable')}
                {bool('is_auditable', 'Auditable')}
                {bool('is_sensitive', 'Dato sensible')}
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} helpText='Ejemplo: {"view":["admin"],"edit":["gerente"]}' />
                <FormField label="Configuracion JSON" value={form.data.settings_text} onChange={(event) => set('settings_text', event.target.value)} />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'relationships') {
        return (
            <>
                <OptionSelect label="Entidad origen" value={form.data.source_entity_type} onChange={(value) => set('source_entity_type', value)} options={options.entities} />
                <OptionSelect label="Entidad destino" value={form.data.target_entity_type} onChange={(value) => set('target_entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. Ejemplo: vehiculos_cliente." />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} error={form.errors.label} />
                <OptionSelect label="Tipo de relacion" value={form.data.type} onChange={(value) => set('type', value)} options={options.relationshipTypes} />
                <FormField label="Nombre inverso" value={form.data.inverse_label} onChange={(event) => set('inverse_label', event.target.value)} helpText="Ejemplo: Cliente propietario, Paciente, Prestamo asociado." />
                {bool('is_required', 'Relacion obligatoria')}
                {bool('allows_multiple', 'Permite multiples destinos')}
                <FormField label="Minimo de relaciones" type="number" value={form.data.min_items} onChange={(event) => set('min_items', event.target.value)} />
                <FormField label="Maximo de relaciones" type="number" value={form.data.max_items} onChange={(event) => set('max_items', event.target.value)} />
                <OptionSelect label="Al desactivar" value={form.data.cascade_behavior} onChange={(value) => set('cascade_behavior', value)} options={options.relationshipCascadeBehaviors} />
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} helpText='Ejemplo: {"view":["admin"],"attach":["tecnico"]}' />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} helpText='Ejemplo: {"documentos":["contrato"],"requiere_evidencia":true}' />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'attachments') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. Ejemplo: ci_escaneado, foto_antes, contrato_firmado." />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} error={form.errors.label} />
                <OptionSelect label="Proposito" value={form.data.purpose} onChange={(value) => set('purpose', value)} options={options.attachmentPurposes} />
                <FormField label="Extensiones permitidas" value={form.data.allowed_extensions_csv} onChange={(event) => set('allowed_extensions_csv', event.target.value)} helpText="Separadas por coma. Ejemplo: jpg,png,pdf." />
                <FormField label="MIME permitidos" value={form.data.allowed_mime_types_csv} onChange={(event) => set('allowed_mime_types_csv', event.target.value)} helpText="Separados por coma. Ejemplo: image/jpeg,application/pdf." />
                <FormField label="Maximo MB" type="number" value={form.data.max_file_mb} onChange={(event) => set('max_file_mb', event.target.value)} />
                <OptionSelect label="Disco" value={form.data.storage_disk} onChange={(value) => set('storage_disk', value)} options={options.attachmentStorageDisks} />
                <FormField label="Carpeta base" value={form.data.path_prefix} onChange={(event) => set('path_prefix', event.target.value)} helpText="Sin espacios. Ejemplo: business-attachments/clinica." />
                <OptionSelect label="Retencion" value={form.data.retention_policy} onChange={(value) => set('retention_policy', value)} options={options.retentionPolicies} />
                {bool('is_required', 'Obligatorio')}
                {bool('is_sensitive', 'Dato sensible')}
                {bool('requires_signed_url', 'Requiere URL firmada')}
                {bool('audit_downloads', 'Auditar descargas')}
                {bool('visible_in_documents', 'Visible en documentos')}
                {bool('visible_in_reports', 'Visible en reportes')}
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} helpText='Ejemplo: {"view":["doctor"],"download":["admin"]}' />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} helpText='Ejemplo: {"requiere_firma":true,"lado":"frontal"}' />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'forms') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. Ejemplo: cierre_orden, aprobar_prestamo." />
                <FormField label="Nombre del formulario" value={form.data.name} onChange={(event) => set('name', event.target.value)} error={form.errors.name} />
                <OptionSelect label="Momento del flujo" value={form.data.flow} onChange={(value) => set('flow', value)} options={{ '': 'General para la entidad', ...(options.formFlows ?? {}) }} />
                <FormField label="Codigo de workflow" value={form.data.workflow_code} onChange={(event) => set('workflow_code', event.target.value)} helpText="Opcional. Ejemplo: reparacion, prestamo." />
                <FormField label="Codigo de estado" value={form.data.state_code} onChange={(event) => set('state_code', event.target.value)} helpText="Opcional. Ejemplo: diagnostico, aprobado." />
                <OptionSelect label="Superficie" value={form.data.surface} onChange={(value) => set('surface', value)} options={options.formSurfaces} />
                <FormField label="Descripcion" value={form.data.description} onChange={(event) => set('description', event.target.value)} />
                <FormField label="Texto del boton" value={form.data.submit_label} onChange={(event) => set('submit_label', event.target.value)} />
                <FormField label="Layout JSON" value={form.data.layout_text} onChange={(event) => set('layout_text', event.target.value)} helpText='Ejemplo: {"columns":2,"sections":["Datos","Cierre"]}' />
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} />
                <FormField label="Validaciones JSON" value={form.data.validations_text} onChange={(event) => set('validations_text', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'formFields') {
        return (
            <>
                <FormSelect value={form.data.dynamic_form_definition_id} onChange={(value) => set('dynamic_form_definition_id', value)} forms={forms} />
                <FormField label="Codigo de campo" value={form.data.field_code} onChange={(event) => set('field_code', event.target.value)} error={form.errors.field_code} helpText="Debe existir como campo personalizado activo en la misma entidad del formulario." />
                <FormField label="Nombre alternativo" value={form.data.label_override} onChange={(event) => set('label_override', event.target.value)} />
                <FormField label="Ayuda contextual" value={form.data.help_text} onChange={(event) => set('help_text', event.target.value)} />
                <FormField label="Placeholder" value={form.data.placeholder} onChange={(event) => set('placeholder', event.target.value)} />
                {bool('is_required', 'Obligatorio en este paso')}
                {bool('is_visible', 'Visible en este paso')}
                {bool('is_read_only', 'Solo lectura en este paso')}
                <FormField label="Valor por defecto JSON/texto" value={form.data.default_value_text} onChange={(event) => set('default_value_text', event.target.value)} />
                <FormField label="Validaciones adicionales JSON" value={form.data.validation_rules_text} onChange={(event) => set('validation_rules_text', event.target.value)} helpText='Ejemplo: ["min:3","max:180"]' />
                <FormField label="Condiciones de visibilidad JSON" value={form.data.visibility_conditions_text} onChange={(event) => set('visibility_conditions_text', event.target.value)} />
                <FormField label="Condiciones de obligatoriedad JSON" value={form.data.required_conditions_text} onChange={(event) => set('required_conditions_text', event.target.value)} />
                <FormField label="Opciones override" value={form.data.options_override_csv} onChange={(event) => set('options_override_csv', event.target.value)} helpText="Separadas por coma si este paso necesita opciones distintas." />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'documentTemplates') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <OptionSelect label="Tipo de documento" value={form.data.document_type} onChange={(value) => set('document_type', value)} options={options.documentTypes} />
                <OptionSelect label="Entidad relacionada" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={{ '': 'Sin entidad directa', ...(options.entities ?? {}) }} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} helpTooltip="Usa minusculas, numeros y guion bajo. Ejemplo: contrato_prestamo_base." />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} error={form.errors.name} />
                <OptionSelect label="Papel" value={form.data.paper_type} onChange={(value) => set('paper_type', value)} options={options.paperTypes} />
                <FormField label="Ancho termico mm" type="number" value={form.data.thermal_width_mm} onChange={(event) => set('thermal_width_mm', event.target.value)} />
                <OptionSelect label="Area de impresion" value={form.data.printer_area} onChange={(value) => set('printer_area', value)} options={{ '': 'Manual/sin area', ...(options.printerAreas ?? {}) }} />
                <FormField label="Copias" type="number" value={form.data.copies} onChange={(event) => set('copies', event.target.value)} />
                <FormField label="Layout JSON" value={form.data.layout_text} onChange={(event) => set('layout_text', event.target.value)} helpText='Ejemplo: {"font_size":11,"show_logo":true}' />
                <FormField label="Campos visibles" value={form.data.fields_csv} onChange={(event) => set('fields_csv', event.target.value)} helpText="Separados por coma." />
                <FormField label="Columnas visibles" value={form.data.columns_csv} onChange={(event) => set('columns_csv', event.target.value)} helpText="Separadas por coma." />
                <FormField label="Texto legal" value={form.data.legal_text} onChange={(event) => set('legal_text', event.target.value)} />
                <FormField label="Terminos y condiciones" value={form.data.terms_text} onChange={(event) => set('terms_text', event.target.value)} />
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                {bool('is_default', 'Plantilla default')}
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'reportTemplates') {
        return (
            <>
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} error={form.errors.name} />
                <OptionSelect label="Modulo" value={form.data.module} onChange={(value) => set('module', value)} options={{ '': 'General', ...(options.reportModules ?? {}) }} />
                <OptionSelect label="Entidad relacionada" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={{ '': 'Sin entidad directa', ...(options.entities ?? {}) }} />
                <FormField label="Descripcion" value={form.data.description} onChange={(event) => set('description', event.target.value)} />
                <FormField label="Columnas" value={form.data.columns_csv} onChange={(event) => set('columns_csv', event.target.value)} helpText="Separadas por coma. Ejemplo: fecha,cliente,total,margen." />
                <FormField label="Filtros JSON" value={form.data.filters_text} onChange={(event) => set('filters_text', event.target.value)} helpText='Ejemplo: {"date_range":true,"branch":true}' />
                <FormField label="Agrupaciones" value={form.data.groupings_csv} onChange={(event) => set('groupings_csv', event.target.value)} />
                <FormField label="Metricas JSON" value={form.data.metrics_text} onChange={(event) => set('metrics_text', event.target.value)} helpText='Ejemplo: {"total":"sum","margen":"avg"}' />
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                <FormField label="Cache TTL minutos" type="number" value={form.data.cache_ttl_minutes} onChange={(event) => set('cache_ttl_minutes', event.target.value)} />
                {bool('is_exportable', 'Exportable')}
                {bool('is_default', 'Reporte default')}
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'calculationFormulas') {
        return (
            <>
                <OptionSelect label="Entidad relacionada" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={{ '': 'Formula global', ...(options.entities ?? {}) }} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} error={form.errors.name} />
                <OptionSelect label="Tipo de resultado" value={form.data.result_type} onChange={(value) => set('result_type', value)} options={options.formulaResultTypes} />
                <FormField label="Precision decimal" type="number" value={form.data.precision} onChange={(event) => set('precision', event.target.value)} error={form.errors.precision} />
                <div className="md:col-span-2">
                    <FormField label="Descripcion" value={form.data.description} onChange={(event) => set('description', event.target.value)} />
                </div>
                <div className="md:col-span-2">
                    <FormField label="Formula JSON segura" value={form.data.expression_text} onChange={(event) => set('expression_text', event.target.value)} error={form.errors.expression_text} helpText='No acepta codigo libre. Ejemplo: {"op":"multiply","args":[{"var":"m2"},{"var":"precio"}]}' />
                </div>
                <div className="md:col-span-2">
                    <FormField label="Variables JSON" value={form.data.variables_text} onChange={(event) => set('variables_text', event.target.value)} error={form.errors.variables_text} helpText='Lista permitida. Ejemplo: [{"code":"m2","label":"Metros cuadrados"},{"code":"precio","label":"Precio"}]' />
                </div>
                <FormField label="Permisos JSON" value={form.data.permissions_text} onChange={(event) => set('permissions_text', event.target.value)} />
                <FormField label="Metadatos JSON" value={form.data.metadata_text} onChange={(event) => set('metadata_text', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'states') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo de flujo opcional" value={form.data.workflow_code} onChange={(event) => set('workflow_code', event.target.value)} helpText="Debe coincidir con el codigo del flujo si este estado pertenece a uno." />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre visible" value={form.data.label} onChange={(event) => set('label', event.target.value)} />
                <FormField label="Color" type="color" value={form.data.color} onChange={(event) => set('color', event.target.value)} />
                <OptionSelect label="Tipo de estado" value={form.data.state_type} onChange={(value) => set('state_type', value)} options={options.stateTypes} />
                {bool('is_initial', 'Estado inicial')}
                {bool('is_final', 'Estado final')}
                <FormField label="Transiciones permitidas" value={form.data.allowed_transitions_csv} onChange={(event) => set('allowed_transitions_csv', event.target.value)} helpText="Codigos separados por coma. Ejemplo: en_proceso,listo." />
                <FormField label="Permiso requerido" value={form.data.required_permission} onChange={(event) => set('required_permission', event.target.value)} />
                <FormField label="Validaciones de entrada JSON" value={form.data.entry_validations_text} onChange={(event) => set('entry_validations_text', event.target.value)} />
                <FormField label="Acciones JSON" value={form.data.actions_text} onChange={(event) => set('actions_text', event.target.value)} />
                <FormField label="Acciones de salida JSON" value={form.data.exit_actions_text} onChange={(event) => set('exit_actions_text', event.target.value)} />
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'workflows') {
        return (
            <>
                <OptionSelect label="Entidad" value={form.data.entity_type} onChange={(value) => set('entity_type', value)} options={options.entities} />
                <FormField label="Codigo interno" value={form.data.code} onChange={(event) => set('code', event.target.value)} error={form.errors.code} />
                <FormField label="Nombre del flujo" value={form.data.name} onChange={(event) => set('name', event.target.value)} error={form.errors.name} />
                <FormField label="Descripcion" value={form.data.description} onChange={(event) => set('description', event.target.value)} />
                <FormField label="Estado inicial" value={form.data.initial_state_code} onChange={(event) => set('initial_state_code', event.target.value)} />
                <FormField label="Estados finales" value={form.data.final_state_codes_csv} onChange={(event) => set('final_state_codes_csv', event.target.value)} helpText="Codigos separados por coma. Ejemplo: entregado,cancelado." />
                <FormField label="Configuracion JSON" value={form.data.settings_text} onChange={(event) => set('settings_text', event.target.value)} />
                {bool('is_default', 'Flujo por defecto')}
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'workflowTransitions') {
        return (
            <>
                <WorkflowSelect value={form.data.workflow_definition_id} onChange={(value) => set('workflow_definition_id', value)} workflows={options.workflows ?? []} />
                <FormField label="Desde estado" value={form.data.from_state_code} onChange={(event) => set('from_state_code', event.target.value)} />
                <FormField label="Hacia estado" value={form.data.to_state_code} onChange={(event) => set('to_state_code', event.target.value)} />
                <FormField label="Etiqueta" value={form.data.label} onChange={(event) => set('label', event.target.value)} />
                <FormField label="Permiso requerido" value={form.data.required_permission} onChange={(event) => set('required_permission', event.target.value)} />
                <FormField label="Condiciones JSON" value={form.data.conditions_text} onChange={(event) => set('conditions_text', event.target.value)} />
                <FormField label="Validaciones JSON" value={form.data.validations_text} onChange={(event) => set('validations_text', event.target.value)} />
                <FormField label="Acciones JSON" value={form.data.actions_text} onChange={(event) => set('actions_text', event.target.value)} />
                {bool('requires_reason', 'Requiere motivo')}
                {bool('is_reversible', 'Reversible')}
                <FormField label="Orden" type="number" value={form.data.sort_order} onChange={(event) => set('sort_order', event.target.value)} />
                {bool('is_active', 'Activo')}
            </>
        );
    }

    if (section === 'resources') {
        return (
            <>
                <BranchSelect value={form.data.branch_id} onChange={(value) => set('branch_id', value)} branches={branches} />
                <WorkerSelect value={form.data.assigned_worker_id} onChange={(value) => set('assigned_worker_id', value)} workers={workers} />
                <OptionSelect label="Tipo de recurso" value={form.data.type} onChange={(value) => set('type', value)} options={options.resourceTypes} />
                <OptionSelect label="Estado operativo" value={form.data.status} onChange={(value) => set('status', value)} options={options.resourceStatuses} />
                <FormField label="Codigo" value={form.data.code} onChange={(event) => set('code', event.target.value)} />
                <FormField label="Nombre" value={form.data.name} onChange={(event) => set('name', event.target.value)} />
                <FormField label="Capacidad" type="number" value={form.data.capacity} onChange={(event) => set('capacity', event.target.value)} />
                <FormField label="Ubicacion" value={form.data.location} onChange={(event) => set('location', event.target.value)} />
                <FormField label="Mantenimiento desde" type="datetime-local" value={form.data.maintenance_starts_at} onChange={(event) => set('maintenance_starts_at', event.target.value)} />
                <FormField label="Mantenimiento hasta" type="datetime-local" value={form.data.maintenance_ends_at} onChange={(event) => set('maintenance_ends_at', event.target.value)} />
                <div className="md:col-span-2">
                    <FormField label="Reglas de disponibilidad JSON" value={form.data.availability_rules_text} onChange={(event) => set('availability_rules_text', event.target.value)} />
                    <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
                        Ejemplo: {"{\"days\":[1,2,3,4,5],\"time_ranges\":[{\"start\":\"08:00\",\"end\":\"18:00\"}],\"blocked_dates\":[\"2026-08-06\"],\"buffer_minutes\":15}"}
                    </p>
                </div>
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

function WorkerSelect({ value, onChange, workers }) {
    return (
        <SelectField label="Responsable asignado" value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">Sin responsable fijo</option>
            {workers.map((worker) => (
                <option key={worker.id} value={worker.id}>
                    {worker.name}{worker.position ? ` - ${worker.position}` : ''}
                </option>
            ))}
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

function WorkflowSelect({ value, onChange, workflows }) {
    return (
        <SelectField label="Flujo" value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">Seleccionar flujo</option>
            {workflows.map((workflow) => <option key={workflow.id} value={workflow.id}>{workflow.name}</option>)}
        </SelectField>
    );
}

function FormSelect({ value, onChange, forms }) {
    return (
        <SelectField label="Formulario" value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            <option value="">Seleccionar formulario</option>
            {forms.map((form) => <option key={form.id} value={form.id}>{form.name} ({form.entity_type})</option>)}
        </SelectField>
    );
}

function sectionHelp(section) {
    return {
        entities: 'Define entidades propias del negocio como paciente, vehiculo, mascota, prestamo, proyecto, garantia o equipo. Crear una entidad aqui no cambia ferreteria hasta que se active el motor correspondiente en el perfil.',
        relationships: 'Define vinculos entre entidades como cliente-vehiculo, paciente-tratamiento, prestamo-garantia, proyecto-etapa u orden-material. No se aplican en ferreteria hasta activar el motor de relaciones.',
        attachments: 'Define documentos, fotos, contratos, firmas y evidencias por entidad. Crear la definicion no habilita subida de archivos en ferreteria hasta activar el motor de adjuntos.',
        forms: 'Define formularios por momento del flujo, por ejemplo crear cliente, aprobar prestamo, cerrar orden o finalizar tratamiento. No se usan en ferreteria hasta activar el motor de formularios.',
        formFields: 'Define que campos aparecen, son obligatorios o quedan solo lectura dentro de cada formulario por flujo.',
        documentTemplates: 'Define plantillas documentales 2.0 por documento, entidad, sucursal, campos, columnas, textos legales e impresora destino. No reemplaza plantillas actuales hasta activar el motor documental.',
        reportTemplates: 'Define columnas, filtros, agrupaciones y metricas por negocio. No cambia reportes actuales hasta activar plantillas de reportes.',
        calculationFormulas: 'Define formulas JSON controladas para calculos de obra, prestamos, alquileres, servicios o produccion. No acepta codigo libre y no opera hasta activar formula_engine.',
        customFields: 'Permite agregar datos propios por entidad, por ejemplo placa en taller, historia clinica en servicios o talla en tienda.',
        workflows: 'Define flujos por entidad, como reparacion, prestamo, comanda, tratamiento o alquiler. Un flujo controla estados y transiciones.',
        states: 'Define estados y transiciones para reservas, comandas, servicios o produccion sin escribir codigo nuevo.',
        workflowTransitions: 'Define pasos permitidos entre estados, permisos, validaciones, motivo obligatorio y acciones automaticas.',
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
        entities: [{ key: 'label', label: 'Entidad' }, { key: 'code', label: 'Codigo' }, { key: 'mode', label: 'Modo' }, { key: 'is_active', label: 'Estado' }],
        relationships: [{ key: 'label', label: 'Relacion' }, { key: 'source_entity_type', label: 'Origen' }, { key: 'target_entity_type', label: 'Destino' }, { key: 'type', label: 'Tipo' }, { key: 'is_active', label: 'Estado' }],
        attachments: [{ key: 'label', label: 'Adjunto' }, { key: 'entity_type', label: 'Entidad' }, { key: 'purpose', label: 'Proposito' }, { key: 'max_file_mb', label: 'MB' }, { key: 'is_sensitive', label: 'Sensible' }],
        forms: [{ key: 'name', label: 'Formulario' }, { key: 'entity_type', label: 'Entidad' }, { key: 'flow', label: 'Flujo' }, { key: 'surface', label: 'Superficie' }, { key: 'is_active', label: 'Estado' }],
        formFields: [{ key: 'form.name', label: 'Formulario' }, { key: 'field_code', label: 'Campo' }, { key: 'is_required', label: 'Obligatorio' }, { key: 'is_read_only', label: 'Solo lectura' }, { key: 'is_active', label: 'Estado' }],
        documentTemplates: [{ key: 'name', label: 'Plantilla' }, { key: 'document_type', label: 'Documento' }, { key: 'branch.name', label: 'Sucursal' }, { key: 'paper_type', label: 'Papel' }, { key: 'is_default', label: 'Default' }],
        reportTemplates: [{ key: 'name', label: 'Reporte' }, { key: 'module', label: 'Modulo' }, { key: 'entity_type', label: 'Entidad' }, { key: 'columns', label: 'Columnas' }, { key: 'is_active', label: 'Estado' }],
        calculationFormulas: [{ key: 'name', label: 'Formula' }, { key: 'code', label: 'Codigo' }, { key: 'entity_type', label: 'Entidad' }, { key: 'result_type', label: 'Resultado' }, { key: 'is_active', label: 'Estado' }],
        customFields: [{ key: 'label', label: 'Nombre' }, { key: 'entity_type', label: 'Entidad' }, { key: 'type', label: 'Tipo' }, { key: 'is_active', label: 'Estado' }],
        workflows: [{ key: 'name', label: 'Flujo' }, { key: 'entity_type', label: 'Entidad' }, { key: 'initial_state_code', label: 'Inicial' }, { key: 'is_default', label: 'Default' }],
        states: [{ key: 'label', label: 'Estado' }, { key: 'entity_type', label: 'Entidad' }, { key: 'is_initial', label: 'Inicial' }, { key: 'is_final', label: 'Final' }],
        workflowTransitions: [{ key: 'workflow.name', label: 'Flujo' }, { key: 'from_state_code', label: 'Desde' }, { key: 'to_state_code', label: 'Hacia' }, { key: 'requires_reason', label: 'Motivo' }],
        resources: [{ key: 'name', label: 'Recurso' }, { key: 'type', label: 'Tipo' }, { key: 'branch.name', label: 'Sucursal' }, { key: 'status', label: 'Estado operativo' }, { key: 'assigned_worker.name', label: 'Responsable' }],
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
        return { ...base, ...pick(row, ['entity_type', 'code', 'label', 'help_text', 'placeholder', 'type', 'group', 'format', 'min_value', 'max_value', 'relation_entity_type', 'is_required', 'visible_in_forms', 'visible_in_table', 'visible_in_documents', 'visible_in_reports', 'is_exportable', 'is_auditable', 'is_sensitive', 'is_encrypted', 'is_read_only', 'sort_order', 'is_active']), options_csv: (row.options ?? []).join(', '), validation_rules_text: stringify(row.validation_rules), default_value_text: stringify(row.default_value), metadata_text: stringify(row.metadata) };
    }

    if (section === 'entities') {
        return { ...base, ...pick(row, ['code', 'label', 'plural_label', 'base_entity', 'module', 'icon', 'mode', 'is_visible', 'is_editable', 'is_required', 'is_exportable', 'is_reportable', 'is_auditable', 'is_sensitive', 'retention_policy', 'sort_order', 'is_active']), permissions_text: stringify(row.permissions), settings_text: stringify(row.settings) };
    }

    if (section === 'relationships') {
        return { ...base, ...pick(row, ['source_entity_type', 'target_entity_type', 'code', 'label', 'type', 'inverse_label', 'is_required', 'allows_multiple', 'min_items', 'max_items', 'cascade_behavior', 'sort_order', 'is_active']), permissions_text: stringify(row.permissions), metadata_text: stringify(row.metadata) };
    }

    if (section === 'attachments') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'label', 'purpose', 'max_file_mb', 'storage_disk', 'path_prefix', 'is_required', 'is_sensitive', 'requires_signed_url', 'audit_downloads', 'visible_in_documents', 'visible_in_reports', 'retention_policy', 'sort_order', 'is_active']), allowed_extensions_csv: (row.allowed_extensions ?? []).join(', '), allowed_mime_types_csv: (row.allowed_mime_types ?? []).join(', '), permissions_text: stringify(row.permissions), metadata_text: stringify(row.metadata) };
    }

    if (section === 'forms') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'name', 'flow', 'workflow_code', 'state_code', 'surface', 'description', 'submit_label', 'sort_order', 'is_active']), layout_text: stringify(row.layout), permissions_text: stringify(row.permissions), validations_text: stringify(row.validations), metadata_text: stringify(row.metadata) };
    }

    if (section === 'formFields') {
        return { ...base, ...pick(row, ['dynamic_form_definition_id', 'field_code', 'label_override', 'help_text', 'placeholder', 'is_required', 'is_visible', 'is_read_only', 'sort_order', 'is_active']), default_value_text: stringify(row.default_value), validation_rules_text: stringify(row.validation_rules), visibility_conditions_text: stringify(row.visibility_conditions), required_conditions_text: stringify(row.required_conditions), options_override_csv: (row.options_override ?? []).join(', ') };
    }

    if (section === 'documentTemplates') {
        return { ...base, ...pick(row, ['branch_id', 'document_type', 'entity_type', 'code', 'name', 'paper_type', 'thermal_width_mm', 'printer_area', 'copies', 'legal_text', 'terms_text', 'is_default', 'is_active']), layout_text: stringify(row.layout), fields_csv: (row.fields ?? []).join(', '), columns_csv: (row.columns ?? []).join(', '), permissions_text: stringify(row.permissions), metadata_text: stringify(row.metadata) };
    }

    if (section === 'reportTemplates') {
        return { ...base, ...pick(row, ['code', 'name', 'module', 'entity_type', 'description', 'cache_ttl_minutes', 'is_exportable', 'is_default', 'is_active']), columns_csv: (row.columns ?? []).join(', '), filters_text: stringify(row.filters), groupings_csv: (row.groupings ?? []).join(', '), metrics_text: stringify(row.metrics), permissions_text: stringify(row.permissions), metadata_text: stringify(row.metadata) };
    }

    if (section === 'calculationFormulas') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'name', 'description', 'result_type', 'precision', 'is_active']), expression_text: stringify(row.expression), variables_text: stringify(row.variables), permissions_text: stringify(row.permissions), metadata_text: stringify(row.metadata) };
    }

    if (section === 'states') {
        return { ...base, ...pick(row, ['entity_type', 'workflow_code', 'code', 'label', 'color', 'state_type', 'is_initial', 'is_final', 'required_permission', 'sort_order', 'is_active']), allowed_transitions_csv: (row.allowed_transitions ?? []).join(', '), entry_validations_text: stringify(row.entry_validations), actions_text: stringify(row.actions), exit_actions_text: stringify(row.exit_actions) };
    }

    if (section === 'workflows') {
        return { ...base, ...pick(row, ['entity_type', 'code', 'name', 'description', 'initial_state_code', 'is_default', 'is_active']), final_state_codes_csv: (row.final_state_codes ?? []).join(', '), settings_text: stringify(row.settings) };
    }

    if (section === 'workflowTransitions') {
        return { ...base, ...pick(row, ['workflow_definition_id', 'from_state_code', 'to_state_code', 'label', 'required_permission', 'requires_reason', 'is_reversible', 'sort_order', 'is_active']), conditions_text: stringify(row.conditions), validations_text: stringify(row.validations), actions_text: stringify(row.actions) };
    }

    if (section === 'resources') {
        return { ...base, ...pick(row, ['branch_id', 'assigned_worker_id', 'type', 'code', 'name', 'capacity', 'status', 'location', 'is_active']), maintenance_starts_at: toInputDateTime(row.maintenance_starts_at), maintenance_ends_at: toInputDateTime(row.maintenance_ends_at), availability_rules_text: stringify(row.availability_rules), metadata_text: stringify(row.metadata) };
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
