import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';

const booleanLabels = {
    true: 'Si',
    false: 'No',
};
const configSteps = [
    ['sales', 'Ventas'],
    ['billing', 'Facturacion'],
    ['purchases', 'Compras'],
    ['cash', 'Caja/Bancos'],
    ['inventory', 'Inventario'],
    ['human_resources', 'Trabajadores'],
    ['pos', 'POS/Productos'],
    ['modules', 'Modulos'],
];

export default function Index({ activeProfile, drafts, versions, presets = [], options, defaultConfiguration, sandboxSession = null }) {
    const [selectedDraftId, setSelectedDraftId] = useState(drafts[0]?.id ?? null);
    const selectedDraft = drafts.find((draft) => draft.id === selectedDraftId) ?? null;
    const baseConfiguration = selectedDraft?.configuration ?? activeProfile?.configuration ?? defaultConfiguration;
    const form = useForm({
        name: selectedDraft?.name ?? `Demo ${options.businessTypes[activeProfile?.business_type] ?? 'nuevo negocio'}`,
        business_type: selectedDraft?.business_type ?? activeProfile?.business_type ?? 'hardware_store',
        configuration: baseConfiguration,
    });
    const [mode, setMode] = useState('edit');
    const [activeDemoStep, setActiveDemoStep] = useState(0);
    const [activeConfigStep, setActiveConfigStep] = useState('sales');

    const comparison = useMemo(() => buildComparison(activeProfile?.configuration ?? defaultConfiguration, form.data.configuration, options), [activeProfile, form.data.configuration, options]);
    const demo = useMemo(() => buildDemo(form.data.business_type, form.data.configuration, options, sandboxSession?.payload ?? {}), [form.data.business_type, form.data.configuration, options, sandboxSession]);

    const setConfig = (group, key, value) => {
        form.setData('configuration', {
            ...form.data.configuration,
            [group]: {
                ...(form.data.configuration[group] ?? {}),
                [key]: value,
            },
        });
    };
    const setConfigPatch = (patch) => {
        const nextConfiguration = { ...form.data.configuration };

        Object.entries(patch).forEach(([group, values]) => {
            nextConfiguration[group] = {
                ...(nextConfiguration[group] ?? {}),
                ...values,
            };
        });

        form.setData('configuration', nextConfiguration);
    };
    const toggleConfigArray = (group, key, value) => {
        const currentValues = form.data.configuration[group]?.[key] ?? [];
        const exists = currentValues.includes(value);

        setConfig(group, key, exists
            ? currentValues.filter((item) => item !== value)
            : [...currentValues, value]);
    };
    const setNestedConfig = (group, key, nestedKey, value) => {
        setConfig(group, key, {
            ...(form.data.configuration[group]?.[key] ?? {}),
            [nestedKey]: value,
        });
    };
    const toggleNestedConfigArray = (group, key, nestedKey, value) => {
        const currentValues = form.data.configuration[group]?.[key]?.[nestedKey] ?? [];
        const exists = currentValues.includes(value);

        setNestedConfig(group, key, nestedKey, exists
            ? currentValues.filter((item) => item !== value)
            : [...currentValues, value]);
    };
    const setConfigList = (group, key, value) => {
        setConfig(group, key, value.split(',').map((item) => item.trim()).filter(Boolean));
    };

    const saveDraft = (event) => {
        event.preventDefault();

        if (selectedDraft) {
            form.put(route('system-superadmin.business-profiles.drafts.update', selectedDraft.id), { preserveScroll: true });
            return;
        }

        form.post(route('system-superadmin.business-profiles.drafts.store'), { preserveScroll: true });
    };
    const savePreset = () => {
        form.post(route('system-superadmin.business-profiles.presets.store'), { preserveScroll: true });
    };

    const loadDraft = (draft) => {
        setSelectedDraftId(draft.id);
        form.setData({
            name: draft.name,
            business_type: draft.business_type,
            configuration: draft.configuration,
        });
    };

    const newDraft = () => {
        setSelectedDraftId(null);
        form.setData({
            name: 'Nuevo perfil de negocio',
            business_type: activeProfile?.business_type ?? 'hardware_store',
            configuration: activeProfile?.configuration ?? defaultConfiguration,
        });
        setMode('edit');
    };

    const applyPreset = (preset) => {
        const next = businessPreset(preset, form.data.configuration, defaultConfiguration);

        form.setData({
            ...form.data,
            business_type: next.businessType,
            configuration: next.configuration,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Configuracion empresarial</h2>}>
            <Head title="Configuracion empresarial" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Configuracion empresarial"
                    description="Define perfiles de negocio en borrador, prueba su comportamiento en demo y aplica cambios solo cuando esten validados."
                />

                <div className="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    Esta seccion es exclusiva para el rol interno sistemasuperadmin. Los borradores no afectan ventas, compras, caja ni inventario hasta presionar Aplicar configuracion.
                </div>

                <div className="grid gap-4 lg:grid-cols-[1.4fr_0.8fr]">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-5 flex flex-wrap gap-2">
                            {[
                                ['edit', 'Configurar'],
                                ['demo', 'Demo'],
                                ['compare', 'Comparar'],
                                ['history', 'Historial'],
                            ].map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setMode(value)}
                                    className={`rounded-full px-4 py-2 text-sm font-semibold transition ${mode === value ? 'bg-brand-primary text-white shadow-sm shadow-brand-primary/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/15'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>

                        {mode === 'edit' ? (
                            <form onSubmit={saveDraft} className="space-y-6">
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-white/5">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 className="text-base font-semibold text-slate-950 dark:text-white">Plantillas rapidas</h3>
                                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                                Cargan una configuracion sugerida en el borrador. No afectan el sistema real hasta guardar y aplicar.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {[
                                            ['hardware', 'Ferreteria cotizacion'],
                                            ['hardware_pos', 'Ferreteria POS'],
                                            ['store_pos', 'Tienda general'],
                                            ['supermarket', 'Supermercado'],
                                            ['bookstore', 'Libreria'],
                                            ['stationery', 'Papeleria'],
                                            ['factory', 'Fabrica simple'],
                                            ['services', 'Servicios'],
                                            ['mixed', 'Mixto'],
                                        ].map(([preset, label]) => (
                                            <button key={preset} type="button" onClick={() => applyPreset(preset)} className="rounded-full border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <FormField label="Nombre del perfil" name="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required />
                                    <SelectField label="Tipo de negocio" name="business_type" value={form.data.business_type} onChange={(event) => form.setData('business_type', event.target.value)} error={form.errors.business_type} helpTooltip="Este dato adapta la demo, los modulos sugeridos y el flujo comercial base.">
                                        {Object.entries(options.businessTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Flujo de venta" name="sales_workflow" value={form.data.configuration.sales.workflow} onChange={(event) => setConfig('sales', 'workflow', event.target.value)} error={form.errors['configuration.sales.workflow']} helpTooltip="Define si se trabaja con cotizacion, venta directa, POS rapido o servicios.">
                                        {Object.entries(options.salesWorkflows).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                </div>

                                <div className="rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                                    <p className="px-2 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Configurar por pasos</p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {configSteps.map(([value, label], index) => (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() => setActiveConfigStep(value)}
                                                className={`rounded-full px-4 py-2 text-sm font-semibold transition ${activeConfigStep === value ? 'bg-brand-primary text-white shadow-sm shadow-brand-primary/25' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/15'}`}
                                            >
                                                {index + 1}. {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {activeConfigStep === 'sales' ? <Section title="Ventas">
                                    <SelectField label="Uso de cotizacion" name="quotation_mode" value={form.data.configuration.sales.quotation_mode} onChange={(event) => setConfig('sales', 'quotation_mode', event.target.value)}>
                                        {Object.entries(options.quotationModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Documento principal" name="document_main" value={form.data.configuration.sales.document_main} onChange={(event) => setConfig('sales', 'document_main', event.target.value)}>
                                        {Object.entries(options.documents).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Clientes en venta" name="customer_mode" value={form.data.configuration.sales.customer_mode} onChange={(event) => setConfig('sales', 'customer_mode', event.target.value)} helpTooltip="Permite definir si el negocio exige cliente, lo deja opcional o lo oculta en ventas rapidas como supermercados.">
                                        {Object.entries(options.entityModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Descontar inventario" name="inventory_discount_timing" value={form.data.configuration.sales.inventory_discount_timing} onChange={(event) => setConfig('sales', 'inventory_discount_timing', event.target.value)}>
                                        {Object.entries(options.inventoryTimings).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <Toggle label="Cliente ocasional permitido" checked={form.data.configuration.sales.allow_occasional_customer} onChange={(value) => setConfig('sales', 'allow_occasional_customer', value)} />
                                </Section> : null}

                                {activeConfigStep === 'sales' ? <Section title="Configuracion avanzada de venta">
                                    <FormField label="Nombre para cotizacion" name="quotation_label" value={form.data.configuration.sales.quotation_label ?? ''} onChange={(event) => setConfig('sales', 'quotation_label', event.target.value)} />
                                    <FormField label="Nombre para nota de venta" name="sale_note_label" value={form.data.configuration.sales.sale_note_label ?? ''} onChange={(event) => setConfig('sales', 'sale_note_label', event.target.value)} />
                                    <FormField label="Nombre para ticket POS" name="ticket_label" value={form.data.configuration.sales.ticket_label ?? ''} onChange={(event) => setConfig('sales', 'ticket_label', event.target.value)} />
                                    <div className="md:col-span-2 xl:col-span-3">
                                        <FormField label="Terminos por defecto" name="default_terms" value={form.data.configuration.sales.default_terms ?? ''} onChange={(event) => setConfig('sales', 'default_terms', event.target.value)} />
                                    </div>
                                    <FormField label="Terminos de cotizacion" name="terms_quotation" value={form.data.configuration.sales.terms_by_document?.quotation ?? ''} onChange={(event) => setNestedConfig('sales', 'terms_by_document', 'quotation', event.target.value)} />
                                    <FormField label="Terminos de nota" name="terms_sale_note" value={form.data.configuration.sales.terms_by_document?.sale_note ?? ''} onChange={(event) => setNestedConfig('sales', 'terms_by_document', 'sale_note', event.target.value)} />
                                    <FormField label="Terminos de ticket" name="terms_ticket" value={form.data.configuration.sales.terms_by_document?.ticket ?? ''} onChange={(event) => setNestedConfig('sales', 'terms_by_document', 'ticket', event.target.value)} />
                                    <Checklist title="Columnas visibles en documentos" options={options.saleColumns} values={form.data.configuration.sales.visible_columns ?? []} onToggle={(value) => toggleConfigArray('sales', 'visible_columns', value)} />
                                    <Checklist title="Metodos de pago permitidos" options={options.paymentMethodCodes} values={form.data.configuration.sales.allowed_payment_methods ?? []} onToggle={(value) => toggleConfigArray('sales', 'allowed_payment_methods', value)} />
                                    <Checklist title="Metodos en ventas" options={options.paymentMethodCodes} values={form.data.configuration.sales.payment_methods_by_flow?.sales ?? []} onToggle={(value) => toggleNestedConfigArray('sales', 'payment_methods_by_flow', 'sales', value)} />
                                    <Checklist title="Metodos en POS" options={options.paymentMethodCodes} values={form.data.configuration.sales.payment_methods_by_flow?.pos ?? []} onToggle={(value) => toggleNestedConfigArray('sales', 'payment_methods_by_flow', 'pos', value)} />
                                    <Checklist title="Metodos en cobros" options={options.paymentMethodCodes} values={form.data.configuration.sales.payment_methods_by_flow?.collections ?? []} onToggle={(value) => toggleNestedConfigArray('sales', 'payment_methods_by_flow', 'collections', value)} />
                                    <SelectField label="Politica de precios" name="price_policy" value={form.data.configuration.sales.price_policy ?? 'base_price'} onChange={(event) => setConfig('sales', 'price_policy', event.target.value)} helpTooltip="Define de donde saldra el precio: producto, sucursal, cliente o combinacion.">
                                        {Object.entries(options.pricePolicies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Politica de descuentos" name="discount_policy" value={form.data.configuration.sales.discount_policy ?? 'permission'} onChange={(event) => setConfig('sales', 'discount_policy', event.target.value)} helpTooltip="Controla quien puede aplicar descuentos y si existe limite porcentual.">
                                        {Object.entries(options.discountPolicies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <FormField label="Descuento maximo %" name="max_discount_percent" type="number" min="0" max="100" value={form.data.configuration.sales.max_discount_percent ?? 0} onChange={(event) => setConfig('sales', 'max_discount_percent', event.target.value)} />
                                    <FormField label="Roles con descuento" name="discount_roles" value={(form.data.configuration.sales.discount_roles ?? []).join(', ')} onChange={(event) => setConfigList('sales', 'discount_roles', event.target.value)} helpTooltip="Escribe roles separados por coma. Ejemplo: vendedor senior, supervisor." />
                                    <SelectField label="Politica de credito" name="credit_limit_policy" value={form.data.configuration.sales.credit_limit_policy ?? 'disabled'} onChange={(event) => setConfig('sales', 'credit_limit_policy', event.target.value)} helpTooltip="Puede advertir o bloquear cuando una venta a credito supera el limite configurado.">
                                        {Object.entries(options.creditLimitPolicies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <FormField label="Limite credito base" name="default_credit_limit" type="number" min="0" value={form.data.configuration.sales.default_credit_limit ?? 0} onChange={(event) => setConfig('sales', 'default_credit_limit', event.target.value)} />
                                    <SelectField label="Politica stock negativo" name="negative_stock_policy" value={form.data.configuration.sales.negative_stock_policy ?? 'never'} onChange={(event) => setConfig('sales', 'negative_stock_policy', event.target.value)} helpTooltip="Permite definir si se bloquea stock negativo o si se autoriza por rol o categoria.">
                                        {Object.entries(options.negativeStockPolicies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <FormField label="Roles stock negativo" name="negative_stock_roles" value={(form.data.configuration.sales.negative_stock_roles ?? []).join(', ')} onChange={(event) => setConfigList('sales', 'negative_stock_roles', event.target.value)} />
                                    <FormField label="Categorias stock negativo" name="negative_stock_categories" value={(form.data.configuration.sales.negative_stock_categories ?? []).join(', ')} onChange={(event) => setConfigList('sales', 'negative_stock_categories', event.target.value)} />
                                </Section> : null}

                                {activeConfigStep === 'billing' ? <Section title="Facturacion SIAT y escenarios fiscales">
                                    <Toggle label="Activar facturacion SIAT" checked={form.data.configuration.modules.billing} onChange={(value) => {
                                        setConfigPatch({
                                            modules: { billing: value },
                                            billing: {
                                                enabled: value,
                                                invoice_flow: value && form.data.configuration.billing.invoice_flow === 'billing_disabled'
                                                    ? 'sale_note_then_invoice'
                                                    : (!value ? 'billing_disabled' : form.data.configuration.billing.invoice_flow),
                                            },
                                        });
                                        if (!value) {
                                            return;
                                        }
                                    }} helpTooltip="Activa el modulo fiscal SIAT para la empresa. Si se desactiva, el sistema solo emitira documentos internos." />
                                    <SelectField label="Flujo fiscal" name="billing_invoice_flow" value={form.data.configuration.billing.invoice_flow ?? 'sale_note_then_invoice'} onChange={(event) => {
                                        const value = event.target.value;
                                        const patch = {
                                            billing: { invoice_flow: value },
                                            modules: { billing: value !== 'billing_disabled' },
                                        };

                                        if (value === 'quote_sale_note_invoice') {
                                            patch.sales = { workflow: 'quotation_to_sale_note', quotation_mode: 'required', document_main: 'sale_note' };
                                            patch.modules = { ...patch.modules, quotes: true, sales_notes: true };
                                            patch.billing = { ...patch.billing, issue_timing: 'automatic_after_quote_conversion', issue_from: 'sale_note' };
                                        }

                                        if (value === 'direct_invoice') {
                                            patch.sales = { workflow: 'direct_sale', quotation_mode: 'disabled', document_main: 'invoice_direct' };
                                            patch.modules = { ...patch.modules, quotes: false, sales_notes: true };
                                            patch.billing = { ...patch.billing, issue_timing: 'automatic_direct', issue_from: 'direct_sale' };
                                        }

                                        if (value === 'sale_note_then_invoice') {
                                            patch.billing = { ...patch.billing, issue_timing: 'manual', issue_from: 'sale_note' };
                                            patch.modules = { ...patch.modules, sales_notes: true };
                                        }

                                        if (value === 'choose_per_sale') {
                                            patch.billing = { ...patch.billing, issue_timing: 'manual', issue_from: 'manual_choice' };
                                            patch.modules = { ...patch.modules, sales_notes: true };
                                        }

                                        setConfigPatch(patch);
                                    }} helpTooltip="Escenario principal: ferreteria documental, venta directa, factura inmediata o decision por venta.">
                                        {Object.entries(options.billingFlows).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Momento de emision" name="billing_issue_timing" value={form.data.configuration.billing.issue_timing ?? 'manual'} onChange={(event) => setConfig('billing', 'issue_timing', event.target.value)} helpTooltip="Define si la factura se emite con boton manual o automaticamente al crear/convertir la venta.">
                                        {Object.entries(options.billingIssueTimings).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Modalidad SIAT" name="billing_mode" value={form.data.configuration.billing.mode ?? 'computerized_online'} onChange={(event) => setConfig('billing', 'mode', event.target.value)} helpTooltip="Computarizada usa hash. Electronica requiere certificado digital y firma XML-DSig.">
                                        <option value="computerized_online">Computarizada en linea</option>
                                        <option value="electronic_online">Electronica en linea</option>
                                    </SelectField>
                                    <SelectField label="Comportamiento sin internet" name="offline_behavior" value={form.data.configuration.billing.offline_behavior ?? 'temporary_receipt'} onChange={(event) => setConfig('billing', 'offline_behavior', event.target.value)} helpTooltip="Para PWA o internet inestable: bloquear, emitir recibo temporal o dejar en cola para enviar al volver conexion.">
                                        <option value="temporary_receipt">Recibo temporal y envio posterior</option>
                                        <option value="queue">Cola local/servidor para sincronizar</option>
                                        <option value="block">Bloquear venta fiscal</option>
                                    </SelectField>
                                    <Toggle label="Exigir datos fiscales del cliente" checked={form.data.configuration.billing.require_customer_tax_data ?? true} onChange={(value) => setConfig('billing', 'require_customer_tax_data', value)} helpTooltip="Si esta activo, el cliente debe tener documento valido antes de facturar." />
                                    <Toggle label="Solicitar CUFD automaticamente" checked={form.data.configuration.billing.auto_request_cufd ?? true} onChange={(value) => setConfig('billing', 'auto_request_cufd', value)} helpTooltip="Si no hay CUFD vigente, el sistema intentara pedir uno antes de emitir." />
                                    <Toggle label="Sincronizar catalogos diariamente" checked={form.data.configuration.billing.daily_catalog_sync ?? true} onChange={(value) => setConfig('billing', 'daily_catalog_sync', value)} />
                                    <Toggle label="Permitir recibo temporal" checked={form.data.configuration.billing.allow_temporary_receipt ?? true} onChange={(value) => setConfig('billing', 'allow_temporary_receipt', value)} />
                                    <Toggle label="Exigir homologacion de productos" checked={form.data.configuration.billing.require_product_mapping ?? true} onChange={(value) => setConfig('billing', 'require_product_mapping', value)} helpTooltip="Bloquea la factura si un producto no tiene codigo SIN, actividad economica y unidad SIAT." />
                                    <Toggle label="Bloquear venta si falla factura" checked={form.data.configuration.billing.block_sale_if_invoice_fails ?? true} onChange={(value) => setConfig('billing', 'block_sale_if_invoice_fails', value)} helpTooltip="Para factura inmediata conviene bloquear; para pruebas o contingencia puede dejarse en falso y revisar errores SIAT luego." />
                                </Section> : null}

                                {activeConfigStep === 'purchases' ? <Section title="Compras">
                                    <SelectField label="Flujo de compra" name="purchase_workflow" value={form.data.configuration.purchases.workflow} onChange={(event) => setConfig('purchases', 'workflow', event.target.value)}>
                                        {Object.entries(options.purchaseWorkflows).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Proveedores en compra" name="supplier_mode" value={form.data.configuration.purchases.supplier_mode} onChange={(event) => setConfig('purchases', 'supplier_mode', event.target.value)} helpTooltip="Permite exigir proveedor en compras formales, dejarlo opcional o ocultarlo para compras rapidas.">
                                        {Object.entries(options.entityModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <Toggle label="Compra rapida con barcode" checked={form.data.configuration.purchases.barcode_entry} onChange={(value) => setConfig('purchases', 'barcode_entry', value)} />
                                    <Toggle label="Crear producto desde compra" checked={form.data.configuration.purchases.allow_create_product} onChange={(value) => setConfig('purchases', 'allow_create_product', value)} />
                                    <Toggle label="Registrar compra pagada como egreso" checked={form.data.configuration.purchases.register_expense_when_paid} onChange={(value) => setConfig('purchases', 'register_expense_when_paid', value)} />
                                </Section> : null}

                                {activeConfigStep === 'cash' ? <Section title="Caja y bancos">
                                    <SelectField label="Tipo de caja" name="cash_scope" value={form.data.configuration.cash.scope} onChange={(event) => setConfig('cash', 'scope', event.target.value)} helpTooltip="Define si la caja pertenece al usuario, a la sucursal o a un punto POS concreto.">
                                        {Object.entries(options.cashScopes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Conciliacion QR/Banco" name="bank_reconciliation_mode" value={form.data.configuration.banks.reconciliation_mode} onChange={(event) => setConfig('banks', 'reconciliation_mode', event.target.value)} helpTooltip="Automatico concilia al registrar el cobro. Manual lo deja pendiente para revisar. Desactivado no crea movimiento bancario automatico.">
                                        {Object.entries(options.bankReconciliationModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <Toggle label="Caja obligatoria para vender" checked={form.data.configuration.cash.required_to_sell} onChange={(value) => setConfig('cash', 'required_to_sell', value)} />
                                    <Toggle label="Conciliar bancos/QR con caja" checked={form.data.configuration.cash.bank_reconciliation} onChange={(value) => setConfig('cash', 'bank_reconciliation', value)} />
                                    <Toggle label="Cuenta bancaria por sucursal" checked={form.data.configuration.banks.require_branch_account} onChange={(value) => setConfig('banks', 'require_branch_account', value)} />
                                    <Toggle label="Permitir efectivo offline en POS" checked={form.data.configuration.cash.allow_offline_cash_sales} onChange={(value) => setConfig('cash', 'allow_offline_cash_sales', value)} />
                                </Section> : null}

                                {activeConfigStep === 'inventory' ? <Section title="Inventario y despachos">
                                    <SelectField label="Despachos" name="delivery_mode" value={form.data.configuration.deliveries.mode} onChange={(event) => setConfig('deliveries', 'mode', event.target.value)} helpTooltip="Si es obligatorio, toda nota de venta debera marcarse para despacho antes de completarse el flujo.">
                                        {Object.entries(options.deliveryModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <Toggle label="Stock siempre por sucursal" checked={form.data.configuration.inventory.always_by_branch} onChange={(value) => setConfig('inventory', 'always_by_branch', value)} />
                                    <Toggle label="Lote/unidad fisica opcional" checked={form.data.configuration.inventory.lot_tracking_optional} onChange={(value) => setConfig('inventory', 'lot_tracking_optional', value)} />
                                    <Toggle label="Equivalencias de unidades" checked={form.data.configuration.inventory.unit_conversions} onChange={(value) => setConfig('inventory', 'unit_conversions', value)} />
                                    <Toggle label="Conductor obligatorio" checked={form.data.configuration.deliveries.driver_required} onChange={(value) => setConfig('deliveries', 'driver_required', value)} />
                                    <Toggle label="Camion obligatorio" checked={form.data.configuration.deliveries.truck_required} onChange={(value) => setConfig('deliveries', 'truck_required', value)} />
                                </Section> : null}

                                {activeConfigStep === 'pos' ? <Section title="POS y productos">
                                    <SelectField label="Lector de barras" name="scanner_mode" value={form.data.configuration.pos.scanner_mode} onChange={(event) => setConfig('pos', 'scanner_mode', event.target.value)} helpTooltip="Define si el negocio puede vender escribiendo/buscando productos o si depende de lector de barras.">
                                        {Object.entries(options.scannerModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Modo offline POS" name="offline_mode" value={form.data.configuration.pos.offline_mode} onChange={(event) => setConfig('pos', 'offline_mode', event.target.value)} helpTooltip="Controla si el POS permite guardar ventas locales cuando se corta internet.">
                                        {Object.entries(options.offlineModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Catalogo de productos" name="catalog_mode" value={form.data.configuration.products.catalog_mode} onChange={(event) => setConfig('products', 'catalog_mode', event.target.value)} helpTooltip="Adapta el manejo de productos segun si se vende stock, servicios, retail o distribucion.">
                                        {Object.entries(options.catalogModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <SelectField label="Creacion de productos" name="creation_context" value={form.data.configuration.products.creation_context} onChange={(event) => setConfig('products', 'creation_context', event.target.value)}>
                                        {Object.entries(options.productCreationContexts).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </SelectField>
                                    <Toggle label="Barcode obligatorio en productos" checked={form.data.configuration.products.barcode_required} onChange={(value) => setConfig('products', 'barcode_required', value)} />
                                    <Toggle label="Impresion de etiquetas barcode" checked={form.data.configuration.products.barcode_labels} onChange={(value) => {
                                        setConfig('products', 'barcode_labels', value);
                                        setConfig('modules', 'barcode_labels', value);
                                    }} helpTooltip="Permite imprimir etiquetas de codigo de barras desde la ficha del producto y editar su formato de papel/tamano." />
                                    <Toggle label="Permitir items de servicio" checked={form.data.configuration.products.allow_service_items} onChange={(value) => setConfig('products', 'allow_service_items', value)} />
                                </Section> : null}

                                {activeConfigStep === 'human_resources' ? <Section title="Trabajadores y sueldos">
                                    <SelectField label="Gestion de trabajadores" name="workers_mode" value={form.data.configuration.human_resources?.workers_mode ?? 'optional'} onChange={(event) => setConfig('human_resources', 'workers_mode', event.target.value)} helpTooltip="Puede usarse solo para trabajadores internos o para vincularlos con usuarios del sistema cuando tambien ingresan al software.">
                                        <option value="disabled">Desactivado</option>
                                        <option value="optional">Opcional</option>
                                        <option value="required">Obligatorio para pagos de sueldo</option>
                                    </SelectField>
                                    <Toggle label="Modulo de trabajadores" checked={form.data.configuration.modules.workers} onChange={(value) => {
                                        setConfig('modules', 'workers', value);
                                        setConfig('human_resources', 'workers_mode', value ? 'optional' : 'disabled');
                                    }} helpTooltip="Activa la ficha de trabajadores, ya sean usuarios del sistema o personal sin acceso." />
                                    <Toggle label="Modulo de pago de sueldos" checked={form.data.configuration.human_resources?.payroll_enabled ?? true} onChange={(value) => {
                                        setConfig('human_resources', 'payroll_enabled', value);
                                        setConfig('modules', 'payroll', value);
                                    }} helpTooltip="Permite registrar pagos de sueldos y ver historial por trabajador, sucursal y periodo." />
                                    <Toggle label="Registrar sueldo como gasto" checked={form.data.configuration.human_resources?.salary_expense_integration ?? true} onChange={(value) => setConfig('human_resources', 'salary_expense_integration', value)} helpTooltip="Cuando esta activo, cada sueldo pagado se registra automaticamente como egreso en gastos y puede conciliarse con banco/QR." />
                                </Section> : null}

                                {activeConfigStep === 'modules' ? <Section
                                    title="Modulos, submodulos y experiencia"
                                    description="Desde aqui puedes desactivar un modulo completo o solo ciertas funciones internas. Desactivar un modulo lo oculta del menu, bloquea sus rutas para usuarios normales y tambien evita que aparezca en exportaciones cuando el perfil no lo permite."
                                >
                                    {Object.keys(form.data.configuration.modules).map((key) => (
                                        <Toggle
                                            key={key}
                                            label={`Modulo de ${moduleLabel(key)}`}
                                            checked={form.data.configuration.modules[key]}
                                            onChange={(value) => setConfig('modules', key, value)}
                                            helpTooltip={moduleToggleHelp(key)}
                                            enableText={`Activar modulo de ${moduleLabel(key)}`}
                                            disableText={`Desactivar modulo de ${moduleLabel(key)}`}
                                        />
                                    ))}
                                    {Object.keys(form.data.configuration.ux).map((key) => (
                                        <Toggle
                                            key={key}
                                            label={`Caracteristica de experiencia: ${uxLabel(key)}`}
                                            checked={form.data.configuration.ux[key]}
                                            onChange={(value) => setConfig('ux', key, value)}
                                            helpTooltip={uxToggleHelp(key)}
                                            enableText={`Activar caracteristica ${uxLabel(key)}`}
                                            disableText={`Desactivar caracteristica ${uxLabel(key)}`}
                                        />
                                    ))}
                                </Section> : null}

                                <ImpactSummary demo={demo} comparison={comparison} />

                                <div className="flex flex-wrap gap-3">
                                    <button type="submit" disabled={form.processing} className="rounded-full bg-brand-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25 disabled:opacity-60">
                                        {selectedDraft ? 'Actualizar borrador' : 'Guardar borrador'}
                                    </button>
                                    <button type="button" onClick={savePreset} disabled={form.processing} className="rounded-full border border-brand-primary px-5 py-2.5 text-sm font-semibold text-brand-primary disabled:opacity-60">
                                        Guardar como preset
                                    </button>
                                    <button type="button" onClick={newDraft} className="rounded-full border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                                        Nuevo borrador
                                    </button>
                                </div>
                            </form>
                        ) : null}

                        {mode === 'demo' ? <DemoPanel demo={demo} activeStep={activeDemoStep} setActiveStep={setActiveDemoStep} sandboxSession={sandboxSession} /> : null}
                        {mode === 'compare' ? <ComparisonPanel rows={comparison} /> : null}
                        {mode === 'history' ? (
                            <div className="space-y-6">
                                <PresetPanel presets={presets} />
                                <HistoryPanel versions={versions} activeConfiguration={activeProfile?.configuration ?? defaultConfiguration} options={options} />
                            </div>
                        ) : null}
                    </div>

                    <aside className="space-y-4">
                        <Card title="Perfil activo">
                            <p className="text-lg font-semibold text-slate-950 dark:text-white">{activeProfile?.name ?? 'Sin perfil activo'}</p>
                            <p className="text-sm text-slate-500 dark:text-slate-400">{options.businessTypes[activeProfile?.business_type] ?? activeProfile?.business_type}</p>
                            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Aplicado: {activeProfile?.applied_at ? new Date(activeProfile.applied_at).toLocaleString('es-BO') : '-'}</p>
                        </Card>

                        <Card title="Borradores">
                            <div className="space-y-2">
                                {drafts.length === 0 ? <p className="text-sm text-slate-500">No hay borradores.</p> : null}
                                {drafts.map((draft) => (
                                    <div key={draft.id} className={`rounded-2xl border p-3 ${selectedDraftId === draft.id ? 'border-brand-primary bg-brand-primary/5' : 'border-slate-200 dark:border-slate-800'}`}>
                                        <button type="button" onClick={() => loadDraft(draft)} className="text-left text-sm font-semibold text-slate-900 hover:text-brand-primary dark:text-white">{draft.name}</button>
                                        <p className="text-xs text-slate-500">{options.businessTypes[draft.business_type] ?? draft.business_type} - {draft.status}</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {draft.status !== 'applied' ? (
                                                <button type="button" onClick={() => router.post(route('system-superadmin.business-profiles.drafts.apply', draft.id), {}, { preserveScroll: true })} className="rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white" title="Aplica la ultima version guardada de este borrador.">
                                                    Aplicar
                                                </button>
                                            ) : null}
                                            {draft.status !== 'applied' ? (
                                                <button type="button" onClick={() => router.delete(route('system-superadmin.business-profiles.drafts.destroy', draft.id), { preserveScroll: true })} className="rounded-full border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600">
                                                    Descartar
                                                </button>
                                            ) : null}
                                        </div>
                                        {selectedDraftId === draft.id && draft.status !== 'applied' ? (
                                            <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                                Si cambiaste algo en pantalla, primero presiona Actualizar borrador antes de aplicar.
                                            </p>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </Card>
                    </aside>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Section({ title, children, description = null }) {
    return (
        <div>
            <h3 className="mb-3 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {description ? (
                <p className="mb-4 rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100">
                    {description}
                </p>
            ) : null}
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{children}</div>
        </div>
    );
}

function Toggle({ label, checked, onChange, helpTooltip = null, enableText = null, disableText = null }) {
    const actionText = checked ? (disableText ?? `Desactivar ${label}`) : (enableText ?? `Activar ${label}`);

    return (
        <div className="flex min-h-24 items-start justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-800 dark:bg-white/5">
            <div className="min-w-0 flex-1">
                <span className="font-medium text-slate-700 dark:text-slate-200">{label}</span>
                {helpTooltip ? <span className="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">{helpTooltip}</span> : null}
                <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] ${checked ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}>
                    {checked ? 'Activo' : 'Desactivado'}
                </span>
            </div>
            <button
                type="button"
                onClick={() => onChange(!checked)}
                className="flex shrink-0 flex-col items-end gap-1 text-right"
                aria-pressed={checked}
                aria-label={actionText}
                title={actionText}
            >
                <span className={`relative block h-6 w-11 rounded-full transition ${checked ? 'bg-brand-primary' : 'bg-slate-300 dark:bg-slate-700'}`}>
                    <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition ${checked ? 'left-5' : 'left-0.5'}`} />
                </span>
                <span className="max-w-24 text-[10px] font-semibold leading-3 text-slate-500 dark:text-slate-400">
                    {actionText}
                </span>
            </button>
        </div>
    );
}

function Checklist({ title, options, values, onToggle }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-white/5 md:col-span-2 xl:col-span-3">
            <p className="text-sm font-semibold text-slate-950 dark:text-white">{title}</p>
            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {Object.entries(options).map(([value, label]) => (
                    <label key={value} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200">
                        <input
                            type="checkbox"
                            checked={values.includes(value)}
                            onChange={() => onToggle(value)}
                            className="h-4 w-4 rounded border-slate-300 text-brand-primary focus:ring-brand-primary"
                        />
                        <span>{label}</span>
                    </label>
                ))}
            </div>
        </div>
    );
}

function ImpactSummary({ demo, comparison }) {
    const changed = comparison.filter((row) => row.changed);

    return (
        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">Resumen antes de guardar</h3>
                    <p className="mt-1">Este borrador no afecta produccion hasta que se aplique desde la tarjeta de borradores.</p>
                </div>
                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-100">
                    {changed.length} cambios detectados
                </span>
            </div>
            {demo.warnings.length > 0 ? (
                <ul className="mt-3 space-y-1">
                    {demo.warnings.slice(0, 4).map((warning) => <li key={warning}>- {warning}</li>)}
                </ul>
            ) : (
                <p className="mt-3">No hay advertencias criticas para este borrador.</p>
            )}
        </div>
    );
}

function Card({ title, children }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="mb-3 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {children}
        </div>
    );
}

function DemoPanel({ demo, activeStep, setActiveStep, sandboxSession }) {
    const selectedStep = demo.flowSteps[activeStep] ?? demo.flowSteps[0];
    const [runtime, setRuntime] = useState(() => initialDemoRuntime(demo));
    const [screen, setScreen] = useState('panel');
    const [sandbox, setSandbox] = useState(() => initialSandboxState(demo));
    const [sandboxStatus, setSandboxStatus] = useState('guardado');
    const [draftProduct, setDraftProduct] = useState({ name: '', unit: 'unidad', stock: '10', price: '10' });
    const [draftCustomer, setDraftCustomer] = useState({ name: '', phone: '' });
    const [draftSupplier, setDraftSupplier] = useState({ name: '', phone: '' });
    const [cartProduct, setCartProduct] = useState('');
    const [cartQuantity, setCartQuantity] = useState('1');
    const [purchaseProduct, setPurchaseProduct] = useState('');
    const [purchaseQuantity, setPurchaseQuantity] = useState('1');

    useEffect(() => {
        setRuntime(initialDemoRuntime(demo));
        setSandbox(initialSandboxState(demo));
        setSandboxStatus('guardado');
    }, [demo.signature]);

    const persistSandbox = (nextSandbox) => {
        if (!sandboxSession?.id) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        setSandboxStatus('guardando');

        fetch(route('system-superadmin.business-profiles.sandbox.update', sandboxSession.id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ payload: nextSandbox }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('No se pudo guardar la demo sandbox.');
                }

                return response.json();
            })
            .then(() => setSandboxStatus('guardado'))
            .catch(() => setSandboxStatus('error'));
    };

    const commitSandbox = (updater) => {
        setSandbox((current) => {
            const nextSandbox = typeof updater === 'function' ? updater(current) : updater;
            persistSandbox(nextSandbox);

            return nextSandbox;
        });
    };

    const runSelectedStep = () => {
        setRuntime((current) => runDemoStep(current, selectedStep));
    };
    const addProduct = () => {
        if (!draftProduct.name.trim()) {
            return;
        }

        commitSandbox((current) => ({
            ...current,
            products: [
                {
                    id: nextSandboxId(current.products),
                    name: draftProduct.name.trim(),
                    unit: draftProduct.unit.trim() || 'unidad',
                    stock: Number(draftProduct.stock || 0),
                    price: Number(draftProduct.price || 0),
                    branch: current.branches[0]?.name ?? 'Demo',
                    status: 'Activo',
                },
                ...current.products,
            ],
            audit: [`Producto creado en demo: ${draftProduct.name.trim()}`, ...current.audit],
        }));
        setDraftProduct({ name: '', unit: 'unidad', stock: '10', price: '10' });
    };
    const updateProductStock = (id, delta) => {
        commitSandbox((current) => ({
            ...current,
            products: current.products.map((product) => product.id === id ? { ...product, stock: Math.max(Number(product.stock || 0) + delta, 0) } : product),
            audit: [`Stock ajustado en demo por ${delta > 0 ? '+' : ''}${delta}`, ...current.audit],
        }));
    };
    const updateProductField = (id, field, value) => {
        commitSandbox((current) => ({
            ...current,
            products: current.products.map((product) => product.id === id ? { ...product, [field]: ['stock', 'price'].includes(field) ? Number(value || 0) : value } : product),
            audit: [`Producto demo editado: ${field}.`, ...current.audit],
        }));
    };
    const deleteProduct = (id) => {
        commitSandbox((current) => ({
            ...current,
            products: current.products.filter((product) => product.id !== id),
            audit: ['Producto eliminado solo en demo.', ...current.audit],
        }));
    };
    const addCustomer = () => {
        if (!draftCustomer.name.trim()) {
            return;
        }

        commitSandbox((current) => ({
            ...current,
            customers: [{ id: nextSandboxId(current.customers), name: draftCustomer.name.trim(), phone: draftCustomer.phone, document: 'DEMO' }, ...current.customers],
            audit: [`Cliente creado en demo: ${draftCustomer.name.trim()}`, ...current.audit],
        }));
        setDraftCustomer({ name: '', phone: '' });
    };
    const updateCustomerField = (id, field, value) => {
        commitSandbox((current) => ({
            ...current,
            customers: current.customers.map((customer) => customer.id === id ? { ...customer, [field]: value } : customer),
            audit: ['Cliente demo editado.', ...current.audit],
        }));
    };
    const deleteCustomer = (id) => {
        commitSandbox((current) => ({
            ...current,
            customers: current.customers.filter((customer) => customer.id !== id),
            audit: ['Cliente eliminado solo en demo.', ...current.audit],
        }));
    };
    const addSupplier = () => {
        if (!draftSupplier.name.trim()) {
            return;
        }

        commitSandbox((current) => ({
            ...current,
            suppliers: [{ id: nextSandboxId(current.suppliers), name: draftSupplier.name.trim(), phone: draftSupplier.phone, tax_id: 'DEMO' }, ...current.suppliers],
            audit: [`Proveedor creado en demo: ${draftSupplier.name.trim()}`, ...current.audit],
        }));
        setDraftSupplier({ name: '', phone: '' });
    };
    const updateSupplierField = (id, field, value) => {
        commitSandbox((current) => ({
            ...current,
            suppliers: current.suppliers.map((supplier) => supplier.id === id ? { ...supplier, [field]: value } : supplier),
            audit: ['Proveedor demo editado.', ...current.audit],
        }));
    };
    const deleteSupplier = (id) => {
        commitSandbox((current) => ({
            ...current,
            suppliers: current.suppliers.filter((supplier) => supplier.id !== id),
            audit: ['Proveedor eliminado solo en demo.', ...current.audit],
        }));
    };
    const createSale = () => {
        const product = sandbox.products.find((item) => String(item.id) === String(cartProduct)) ?? sandbox.products[0];
        const quantity = Math.max(Number(cartQuantity || 1), 1);

        if (!product) {
            return;
        }

        const total = roundMoney(quantity * Number(product.price || 0));
        commitSandbox((current) => ({
            ...current,
            products: current.products.map((item) => item.id === product.id ? { ...item, stock: Math.max(Number(item.stock || 0) - quantity, 0) } : item),
            sales: [{ id: nextSandboxId(current.sales), receipt_number: `DEMO-${current.sales.length + 1}`, document_type: demo.primaryScreen.kind === 'pos' ? 'ticket' : 'sale_note', customer: current.customers[0]?.name ?? 'Cliente ocasional', total, balance_due: 0, status: 'Pagado' }, ...current.sales],
            cash: { ...current.cash, amount: roundMoney(current.cash.amount + total), open: true },
            audit: [`Venta demo creada por Bs ${total.toFixed(1)}.`, ...current.audit],
        }));
        setRuntime((current) => ({ ...current, cashOpen: true, cash: current.cash + total, documents: [{ type: 'Demo', total }, ...current.documents], lastAction: 'Venta creada dentro del sandbox.' }));
    };
    const createPurchase = () => {
        const product = sandbox.products.find((item) => String(item.id) === String(purchaseProduct)) ?? sandbox.products[0];
        const quantity = Math.max(Number(purchaseQuantity || 1), 1);

        if (!product) {
            return;
        }

        const total = roundMoney(quantity * Number(product.price || 0) * 0.75);
        commitSandbox((current) => ({
            ...current,
            products: current.products.map((item) => item.id === product.id ? { ...item, stock: Number(item.stock || 0) + quantity } : item),
            purchases: [{ id: nextSandboxId(current.purchases), number: `COMP-DEMO-${current.purchases.length + 1}`, supplier: current.suppliers[0]?.name ?? 'Proveedor demo', product: product.name, quantity, total, status: 'Pagada' }, ...current.purchases],
            cash: { ...current.cash, amount: roundMoney(Math.max(current.cash.amount - total, 0)) },
            audit: [`Compra demo registrada por Bs ${total.toFixed(1)}.`, ...current.audit],
        }));
    };
    const toggleCash = () => {
        commitSandbox((current) => ({
            ...current,
            cash: { ...current.cash, open: !current.cash.open },
            audit: [`Caja demo ${current.cash.open ? 'cerrada' : 'abierta'}.`, ...current.audit],
        }));
    };
    const createQrMovement = () => {
        commitSandbox((current) => ({
            ...current,
            bank: { ...current.bank, balance: roundMoney(current.bank.balance + 50), movements: [{ id: Date.now(), type: 'Ingreso QR', amount: 50 }, ...current.bank.movements] },
            audit: ['Movimiento QR demo conciliado en banco.', ...current.audit],
        }));
        setRuntime((current) => ({ ...current, bank: current.bank + 50, bankMovements: current.bankMovements + 1, lastAction: 'QR conciliado dentro del sandbox.' }));
    };
    const resetSandbox = () => {
        setRuntime(initialDemoRuntime(demo));
        if (!sandboxSession?.id) {
            setSandbox(initialSandboxState(demo));
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        setSandboxStatus('guardando');
        fetch(route('system-superadmin.business-profiles.sandbox.reset', sandboxSession.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('No se pudo reiniciar la demo sandbox.');
                }

                return response.json();
            })
            .then((response) => {
                setSandbox(initialSandboxState({ ...demo, sandbox: response.session?.payload ?? demo.sandbox }));
                setSandboxStatus('guardado');
            })
            .catch(() => {
                setSandbox(initialSandboxState(demo));
                setSandboxStatus('error');
            });
    };

    return (
        <div className="space-y-4">
            <div className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p>Demo sandbox: aqui corre una copia temporal e interactiva del sistema. Puedes crear, editar, eliminar, vender, comprar, abrir caja y conciliar QR sin tocar la base de datos real.</p>
                    <button
                        type="button"
                        onClick={() => router.post(route('system-superadmin.business-profiles.sandbox-full.enter'), {}, { preserveScroll: true })}
                        className="rounded-full bg-sky-700 px-4 py-2 text-sm font-semibold text-white shadow-sm"
                    >
                        Entrar a demo completa
                    </button>
                </div>
            </div>

            <div className="grid gap-3 md:grid-cols-4">
                <RuntimeCard title="Caja demo" value={runtime.cashOpen ? 'Abierta' : 'Cerrada'} detail={`Efectivo Bs ${runtime.cash.toFixed(1)}`} tone={runtime.cashOpen ? 'success' : 'warning'} />
                <RuntimeCard title="Banco/QR demo" value={`Bs ${runtime.bank.toFixed(1)}`} detail={`${runtime.bankMovements} movimientos`} tone="info" />
                <RuntimeCard title="Stock demo" value={`${runtime.stock} unidades`} detail="Copia local del inventario" tone="neutral" />
                <RuntimeCard title="Documentos demo" value={`${runtime.documents.length}`} detail={runtime.lastAction} tone="neutral" />
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Sistema demo dentro del sistema</p>
                        <h3 className="mt-1 text-lg font-bold text-slate-950 dark:text-white">Workspace sandbox</h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {sandboxStatus === 'guardando' ? 'Guardando cambios de la demo...' : sandboxStatus === 'error' ? 'No se pudo guardar la ultima accion de la demo.' : 'Cambios de demo guardados en una sesion aislada.'}
                        </p>
                    </div>
                    <button type="button" onClick={resetSandbox} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        Reiniciar copia
                    </button>
                </div>
                <div className="mt-4 flex gap-2 overflow-x-auto pb-1">
                    {[
                        ['panel', 'Panel'],
                        ['productos', 'Productos'],
                        ['ventas', demo.primaryScreen.kind === 'pos' ? 'POS' : 'Ventas'],
                        ['compras', 'Compras'],
                        ['caja', 'Caja'],
                        ['bancos', 'Bancos/QR'],
                        ['personas', 'Clientes/proveedores'],
                    ].map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setScreen(value)}
                            className={`whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold ${screen === value ? 'bg-brand-primary text-white' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <div className="mt-5 rounded-3xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900">
                    {screen === 'panel' ? <SandboxDashboard sandbox={sandbox} demo={demo} /> : null}
                    {screen === 'productos' ? (
                        <SandboxProducts
                            products={sandbox.products}
                            draft={draftProduct}
                            setDraft={setDraftProduct}
                            onAdd={addProduct}
                            onEdit={updateProductField}
                            onStock={updateProductStock}
                            onDelete={deleteProduct}
                        />
                    ) : null}
                    {screen === 'ventas' ? (
                        <SandboxSales
                            demo={demo}
                            products={sandbox.products}
                            sales={sandbox.sales}
                            productId={cartProduct}
                            setProductId={setCartProduct}
                            quantity={cartQuantity}
                            setQuantity={setCartQuantity}
                            onCreate={createSale}
                        />
                    ) : null}
                    {screen === 'compras' ? (
                        <SandboxPurchases
                            products={sandbox.products}
                            purchases={sandbox.purchases}
                            productId={purchaseProduct}
                            setProductId={setPurchaseProduct}
                            quantity={purchaseQuantity}
                            setQuantity={setPurchaseQuantity}
                            onCreate={createPurchase}
                        />
                    ) : null}
                    {screen === 'caja' ? <SandboxCash cash={sandbox.cash} onToggle={toggleCash} /> : null}
                    {screen === 'bancos' ? <SandboxBank bank={sandbox.bank} onQr={createQrMovement} /> : null}
                    {screen === 'personas' ? (
                        <SandboxPeople
                            customers={sandbox.customers}
                            suppliers={sandbox.suppliers}
                            draftCustomer={draftCustomer}
                            setDraftCustomer={setDraftCustomer}
                            draftSupplier={draftSupplier}
                            setDraftSupplier={setDraftSupplier}
                            onCustomer={addCustomer}
                            onSupplier={addSupplier}
                            onEditCustomer={updateCustomerField}
                            onDeleteCustomer={deleteCustomer}
                            onEditSupplier={updateSupplierField}
                            onDeleteSupplier={deleteSupplier}
                        />
                    ) : null}
                </div>
                <div className="mt-4 grid gap-2 md:grid-cols-2">
                    {sandbox.audit.slice(0, 6).map((entry) => (
                        <div key={entry} className="rounded-2xl bg-slate-100 px-4 py-2 text-sm text-slate-700 dark:bg-white/10 dark:text-slate-200">{entry}</div>
                    ))}
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Prueba guiada del perfil</p>
                <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    {demo.flowSteps.map((step, index) => (
                        <button
                            key={step.key}
                            type="button"
                            onClick={() => setActiveStep(index)}
                            disabled={!step.enabled}
                            className={`rounded-2xl border px-4 py-3 text-left text-sm transition ${activeStep === index ? 'border-brand-primary bg-brand-primary text-white shadow-sm shadow-brand-primary/25' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-brand-primary dark:border-slate-800 dark:bg-white/5 dark:text-slate-200'} disabled:cursor-not-allowed disabled:opacity-45`}
                        >
                            <span className="block text-xs font-semibold uppercase tracking-[0.14em] opacity-80">Paso {index + 1}</span>
                            <span className="mt-1 block font-semibold">{step.title}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[0.8fr_1.2fr]">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Navegacion simulada</p>
                    <div className="mt-4 space-y-2">
                        {demo.navigation.map((item) => (
                            <div key={item.label} className={`flex items-center justify-between rounded-2xl border px-4 py-3 text-sm ${item.enabled ? 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-800 dark:bg-white/5 dark:text-slate-100' : 'border-slate-200 bg-slate-100 text-slate-400 line-through dark:border-slate-800 dark:bg-slate-900 dark:text-slate-600'}`}>
                                <span className="font-semibold">{item.label}</span>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${item.enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-slate-200 text-slate-500 dark:bg-slate-800 dark:text-slate-400'}`}>
                                    {item.enabled ? 'Visible' : 'Oculto'}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Pantalla simulada: {selectedStep.title}</p>
                    <div className="mt-4 rounded-3xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-800">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{selectedStep.area}</p>
                                <h3 className="text-xl font-bold text-slate-950 dark:text-white">{selectedStep.screenTitle}</h3>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{selectedStep.description}</p>
                            </div>
                            <span className="rounded-full bg-brand-primary px-3 py-1.5 text-xs font-semibold text-white">{selectedStep.badge}</span>
                        </div>

                        {selectedStep.kind === 'cash' ? (
                            <div className="mt-4 space-y-4">
                                <div className="grid gap-4 md:grid-cols-3">
                                    {selectedStep.fields.map((field) => (
                                        <div key={field.label} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{field.label}</p>
                                            <p className="mt-2 text-lg font-bold text-slate-950 dark:text-white">{field.value}</p>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" onClick={runSelectedStep} disabled={!selectedStep.enabled} className="rounded-2xl bg-brand-primary px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
                                    {selectedStep.primaryAction}
                                </button>
                            </div>
                        ) : selectedStep.kind === 'pos' ? (
                            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_0.7fr]">
                                <div className="space-y-3">
                                    <div className="rounded-2xl border border-brand-primary/30 bg-white px-4 py-3 dark:bg-slate-950">
                                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Lector de barras</p>
                                        <p className="mt-1 font-mono text-lg text-slate-950 dark:text-white">7891000254301</p>
                                    </div>
                                    {selectedStep.items.map((item) => (
                                        <div key={item.name} className="grid grid-cols-[1fr_auto] gap-3 rounded-2xl border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-950">
                                            <div>
                                                <p className="font-semibold text-slate-950 dark:text-white">{item.name}</p>
                                                <p className="text-slate-500">{item.quantity} x {item.price}</p>
                                            </div>
                                            <p className="font-bold text-slate-950 dark:text-white">{item.total}</p>
                                        </div>
                                    ))}
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                                    <p className="text-sm text-slate-500">Total</p>
                                    <p className="mt-1 text-3xl font-bold text-slate-950 dark:text-white">{selectedStep.total}</p>
                                    <div className="mt-4 grid gap-2">
                                        <button type="button" onClick={runSelectedStep} className="rounded-2xl bg-brand-primary px-4 py-3 text-sm font-semibold text-white">{selectedStep.primaryAction}</button>
                                        <button type="button" onClick={() => setRuntime(initialDemoRuntime(demo))} className="rounded-2xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">Reiniciar demo</button>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-4 space-y-3">
                                <div className="grid gap-3 md:grid-cols-3">
                                    {selectedStep.fields.map((field) => (
                                        <div key={field.label} className="rounded-2xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{field.label}</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-950 dark:text-white">{field.value}</p>
                                        </div>
                                    ))}
                                </div>
                                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                            <tr>
                                                <th className="px-4 py-3">Producto</th>
                                                <th className="px-4 py-3">Cant.</th>
                                                <th className="px-4 py-3">Unidad</th>
                                                <th className="px-4 py-3">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {selectedStep.items.map((item) => (
                                                <tr key={item.name} className="border-t border-slate-100 dark:border-slate-800">
                                                    <td className="px-4 py-3 font-semibold">{item.name}</td>
                                                    <td className="px-4 py-3">{item.quantity}</td>
                                                    <td className="px-4 py-3">{item.unit}</td>
                                                    <td className="px-4 py-3 font-bold">{item.total}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" onClick={runSelectedStep} disabled={!selectedStep.enabled} className="rounded-2xl bg-brand-primary px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
                                    {selectedStep.primaryAction}
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                {demo.cards.map((card) => (
                    <div key={card.title} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{card.area}</p>
                        <h3 className="mt-2 text-lg font-semibold text-slate-950 dark:text-white">{card.title}</h3>
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">{card.description}</p>
                    </div>
                ))}
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
                <h3 className="text-base font-semibold text-slate-950 dark:text-white">Flujo simulado</h3>
                <ol className="mt-4 space-y-3">
                    {demo.flowSteps.map((step, index) => (
                        <li key={step.key} className={`flex gap-3 rounded-2xl p-2 text-sm ${activeStep === index ? 'bg-brand-primary/10 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-300'}`}>
                            <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${activeStep === index ? 'bg-brand-primary text-white' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}`}>{index + 1}</span>
                            <span>{step.result}</span>
                        </li>
                    ))}
                </ol>
            </div>

            {demo.warnings.length > 0 ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    <h3 className="font-semibold">Advertencias antes de aplicar</h3>
                    <ul className="mt-3 space-y-2">
                        {demo.warnings.map((warning) => <li key={warning}>- {warning}</li>)}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

function RuntimeCard({ title, value, detail, tone }) {
    const tones = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
        warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
        info: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100',
        neutral: 'border-slate-200 bg-white text-slate-800 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-100',
    };

    return (
        <div className={`rounded-2xl border p-4 shadow-sm ${tones[tone] ?? tones.neutral}`}>
            <p className="text-xs font-semibold uppercase tracking-[0.14em] opacity-70">{title}</p>
            <p className="mt-2 text-lg font-bold">{value}</p>
            <p className="mt-1 text-xs opacity-75">{detail}</p>
        </div>
    );
}

function SandboxDashboard({ sandbox, demo }) {
    const totalSales = sandbox.sales.reduce((sum, sale) => sum + Number(sale.total || 0), 0);
    const totalPurchases = sandbox.purchases.reduce((sum, purchase) => sum + Number(purchase.total || 0), 0);

    return (
        <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-4">
                <RuntimeCard title="Ventas sandbox" value={`Bs ${roundMoney(totalSales).toFixed(1)}`} detail={`${sandbox.sales.length} documentos`} tone="success" />
                <RuntimeCard title="Compras sandbox" value={`Bs ${roundMoney(totalPurchases).toFixed(1)}`} detail={`${sandbox.purchases.length} registros`} tone="warning" />
                <RuntimeCard title="Productos" value={sandbox.products.length} detail="Copia editable" tone="neutral" />
                <RuntimeCard title="Perfil probado" value={demo.primaryScreen.badge} detail={demo.primaryScreen.title} tone="info" />
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                <h4 className="font-semibold text-slate-950 dark:text-white">Menu resultante</h4>
                <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {demo.navigation.map((item) => (
                        <span key={item.label} className={`rounded-full px-3 py-2 text-sm font-semibold ${item.enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-slate-200 text-slate-500 line-through dark:bg-slate-800 dark:text-slate-400'}`}>
                            {item.label}
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}

function SandboxProducts({ products, draft, setDraft, onAdd, onEdit, onStock, onDelete }) {
    return (
        <div className="space-y-4">
            <SandboxForm title="Crear producto en demo">
                <input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} placeholder="Nombre producto" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <input value={draft.unit} onChange={(event) => setDraft({ ...draft, unit: event.target.value })} placeholder="Unidad" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <input type="number" value={draft.stock} onChange={(event) => setDraft({ ...draft, stock: event.target.value })} placeholder="Stock" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <input type="number" value={draft.price} onChange={(event) => setDraft({ ...draft, price: event.target.value })} placeholder="Precio" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <button type="button" onClick={onAdd} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Crear</button>
            </SandboxForm>
            <SandboxTable columns={['Producto', 'Unidad', 'Stock', 'Precio', 'Acciones']}>
                {products.map((product) => (
                    <tr key={product.id} className="border-t border-slate-100 dark:border-slate-800">
                        <td className="px-4 py-3"><input value={product.name} onChange={(event) => onEdit(product.id, 'name', event.target.value)} className="w-52 rounded-xl border border-slate-200 px-2 py-1 font-semibold dark:border-slate-700 dark:bg-slate-900" /></td>
                        <td className="px-4 py-3"><input value={product.unit} onChange={(event) => onEdit(product.id, 'unit', event.target.value)} className="w-28 rounded-xl border border-slate-200 px-2 py-1 dark:border-slate-700 dark:bg-slate-900" /></td>
                        <td className="px-4 py-3"><input type="number" value={product.stock} onChange={(event) => onEdit(product.id, 'stock', event.target.value)} className="w-24 rounded-xl border border-slate-200 px-2 py-1 dark:border-slate-700 dark:bg-slate-900" /></td>
                        <td className="px-4 py-3"><input type="number" value={product.price} onChange={(event) => onEdit(product.id, 'price', event.target.value)} className="w-28 rounded-xl border border-slate-200 px-2 py-1 dark:border-slate-700 dark:bg-slate-900" /></td>
                        <td className="px-4 py-3">
                            <div className="flex flex-wrap gap-2">
                                <button type="button" onClick={() => onStock(product.id, 1)} className="rounded-full border px-3 py-1 text-xs font-semibold">+1</button>
                                <button type="button" onClick={() => onStock(product.id, -1)} className="rounded-full border px-3 py-1 text-xs font-semibold">-1</button>
                                <button type="button" onClick={() => onDelete(product.id)} className="rounded-full border border-red-200 px-3 py-1 text-xs font-semibold text-red-600">Eliminar</button>
                            </div>
                        </td>
                    </tr>
                ))}
            </SandboxTable>
        </div>
    );
}

function SandboxSales({ demo, products, sales, productId, setProductId, quantity, setQuantity, onCreate }) {
    return (
        <div className="space-y-4">
            <SandboxForm title={demo.primaryScreen.kind === 'pos' ? 'Cobrar venta POS demo' : 'Crear nota/cotizacion demo'}>
                <select value={productId} onChange={(event) => setProductId(event.target.value)} className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                    <option value="">Primer producto disponible</option>
                    {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                </select>
                <input type="number" min="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <button type="button" onClick={onCreate} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Crear venta</button>
            </SandboxForm>
            <SandboxTable columns={['Documento', 'Tipo', 'Cliente', 'Total', 'Estado']}>
                {sales.map((sale) => (
                    <tr key={sale.id ?? sale.receipt_number} className="border-t border-slate-100 dark:border-slate-800">
                        <td className="px-4 py-3 font-semibold">{sale.receipt_number}</td>
                        <td className="px-4 py-3">{sale.document_type}</td>
                        <td className="px-4 py-3">{sale.customer}</td>
                        <td className="px-4 py-3">Bs {Number(sale.total || 0).toFixed(1)}</td>
                        <td className="px-4 py-3">{sale.status}</td>
                    </tr>
                ))}
            </SandboxTable>
        </div>
    );
}

function SandboxPurchases({ products, purchases, productId, setProductId, quantity, setQuantity, onCreate }) {
    return (
        <div className="space-y-4">
            <SandboxForm title="Registrar compra demo">
                <select value={productId} onChange={(event) => setProductId(event.target.value)} className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950">
                    <option value="">Primer producto disponible</option>
                    {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                </select>
                <input type="number" min="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                <button type="button" onClick={onCreate} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Registrar compra</button>
            </SandboxForm>
            <SandboxTable columns={['Compra', 'Proveedor', 'Producto', 'Cantidad', 'Total']}>
                {purchases.map((purchase) => (
                    <tr key={purchase.id} className="border-t border-slate-100 dark:border-slate-800">
                        <td className="px-4 py-3 font-semibold">{purchase.number}</td>
                        <td className="px-4 py-3">{purchase.supplier}</td>
                        <td className="px-4 py-3">{purchase.product}</td>
                        <td className="px-4 py-3">{purchase.quantity}</td>
                        <td className="px-4 py-3">Bs {Number(purchase.total || 0).toFixed(1)}</td>
                    </tr>
                ))}
            </SandboxTable>
        </div>
    );
}

function SandboxCash({ cash, onToggle }) {
    return (
        <div className="grid gap-4 md:grid-cols-[1fr_0.8fr]">
            <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
                <p className="text-sm text-slate-500">Estado de caja</p>
                <p className="mt-2 text-3xl font-bold text-slate-950 dark:text-white">{cash.open ? 'Abierta' : 'Cerrada'}</p>
                <p className="mt-2 text-slate-500">Efectivo contado: Bs {Number(cash.amount || 0).toFixed(1)}</p>
                <button type="button" onClick={onToggle} className="mt-4 rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">{cash.open ? 'Cerrar caja demo' : 'Abrir caja demo'}</button>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
                <p className="text-sm font-semibold">Conteo simulado</p>
                <p className="mt-2 text-sm text-slate-500">El cierre separa efectivo de QR/Banco. Esta accion solo modifica la copia temporal.</p>
            </div>
        </div>
    );
}

function SandboxBank({ bank, onQr }) {
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
                <div>
                    <p className="text-sm text-slate-500">Saldo banco/QR demo</p>
                    <p className="mt-1 text-3xl font-bold text-slate-950 dark:text-white">Bs {Number(bank.balance || 0).toFixed(1)}</p>
                </div>
                <button type="button" onClick={onQr} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Conciliar QR demo</button>
            </div>
            <SandboxTable columns={['Movimiento', 'Monto']}>
                {bank.movements.map((movement) => (
                    <tr key={movement.id} className="border-t border-slate-100 dark:border-slate-800">
                        <td className="px-4 py-3 font-semibold">{movement.type}</td>
                        <td className="px-4 py-3">Bs {Number(movement.amount || 0).toFixed(1)}</td>
                    </tr>
                ))}
            </SandboxTable>
        </div>
    );
}

function SandboxPeople({ customers, suppliers, draftCustomer, setDraftCustomer, draftSupplier, setDraftSupplier, onCustomer, onSupplier, onEditCustomer, onDeleteCustomer, onEditSupplier, onDeleteSupplier }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <div className="space-y-3">
                <SandboxForm title="Crear cliente demo">
                    <input value={draftCustomer.name} onChange={(event) => setDraftCustomer({ ...draftCustomer, name: event.target.value })} placeholder="Nombre" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                    <input value={draftCustomer.phone} onChange={(event) => setDraftCustomer({ ...draftCustomer, phone: event.target.value })} placeholder="Telefono" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                    <button type="button" onClick={onCustomer} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Crear cliente</button>
                </SandboxForm>
                <SandboxTable columns={['Cliente', 'Telefono', 'Acciones']}>
                    {customers.map((customer) => (
                        <tr key={customer.id} className="border-t border-slate-100 dark:border-slate-800">
                            <td className="px-4 py-3"><input value={customer.name} onChange={(event) => onEditCustomer(customer.id, 'name', event.target.value)} className="w-48 rounded-xl border border-slate-200 px-2 py-1 font-semibold dark:border-slate-700 dark:bg-slate-900" /></td>
                            <td className="px-4 py-3"><input value={customer.phone ?? ''} onChange={(event) => onEditCustomer(customer.id, 'phone', event.target.value)} className="w-36 rounded-xl border border-slate-200 px-2 py-1 dark:border-slate-700 dark:bg-slate-900" /></td>
                            <td className="px-4 py-3"><button type="button" onClick={() => onDeleteCustomer(customer.id)} className="rounded-full border border-red-200 px-3 py-1 text-xs font-semibold text-red-600">Eliminar</button></td>
                        </tr>
                    ))}
                </SandboxTable>
            </div>
            <div className="space-y-3">
                <SandboxForm title="Crear proveedor demo">
                    <input value={draftSupplier.name} onChange={(event) => setDraftSupplier({ ...draftSupplier, name: event.target.value })} placeholder="Nombre" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                    <input value={draftSupplier.phone} onChange={(event) => setDraftSupplier({ ...draftSupplier, phone: event.target.value })} placeholder="Telefono" className="rounded-2xl border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-950" />
                    <button type="button" onClick={onSupplier} className="rounded-2xl bg-brand-primary px-4 py-2 font-semibold text-white">Crear proveedor</button>
                </SandboxForm>
                <SandboxTable columns={['Proveedor', 'Telefono', 'Acciones']}>
                    {suppliers.map((supplier) => (
                        <tr key={supplier.id} className="border-t border-slate-100 dark:border-slate-800">
                            <td className="px-4 py-3"><input value={supplier.name} onChange={(event) => onEditSupplier(supplier.id, 'name', event.target.value)} className="w-48 rounded-xl border border-slate-200 px-2 py-1 font-semibold dark:border-slate-700 dark:bg-slate-900" /></td>
                            <td className="px-4 py-3"><input value={supplier.phone ?? ''} onChange={(event) => onEditSupplier(supplier.id, 'phone', event.target.value)} className="w-36 rounded-xl border border-slate-200 px-2 py-1 dark:border-slate-700 dark:bg-slate-900" /></td>
                            <td className="px-4 py-3"><button type="button" onClick={() => onDeleteSupplier(supplier.id)} className="rounded-full border border-red-200 px-3 py-1 text-xs font-semibold text-red-600">Eliminar</button></td>
                        </tr>
                    ))}
                </SandboxTable>
            </div>
        </div>
    );
}

function SandboxForm({ title, children }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
            <h4 className="mb-3 font-semibold text-slate-950 dark:text-white">{title}</h4>
            <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-5">{children}</div>
        </div>
    );
}

function SandboxTable({ columns, children }) {
    return (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <table className="min-w-full text-sm">
                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>{columns.map((column) => <th key={column} className="px-4 py-3">{column}</th>)}</tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

function initialDemoRuntime(demo) {
    return {
        cashOpen: Number(demo.sandbox?.totals?.cashOpen ?? 0) > 0,
        cash: Math.max(500, Number(demo.sandbox?.totals?.payments ?? 0)),
        bank: 0,
        bankMovements: 0,
        stock: demo.inventorySeed ?? 80,
        documents: (demo.sandbox?.sales ?? []).slice(0, 3).map((sale) => ({ type: sale.document_type, total: sale.total })),
        deliveries: 0,
        lastAction: demo.sandbox?.generatedAt ? 'Copia temporal de datos reales lista para probar' : 'Copia lista para probar',
    };
}

function initialSandboxState(demo) {
    const products = (demo.sandbox?.products?.length ? demo.sandbox.products : [
        { name: 'Producto demo', unit: 'unidad', stock: 20, price: 10, branch: 'Demo', status: 'Activo' },
        { name: 'Servicio demo', unit: 'servicio', stock: 999, price: 80, branch: 'Demo', status: 'Activo' },
    ]).map((product, index) => ({
        id: product.id ?? index + 1,
        name: product.name,
        unit: product.unit ?? 'unidad',
        stock: Number(product.stock ?? 0),
        price: Number(product.price ?? 0),
        branch: product.branch ?? 'Demo',
        status: product.status ?? 'Activo',
    }));
    const sales = (demo.sandbox?.sales ?? []).map((sale, index) => ({
        id: sale.id ?? index + 1,
        receipt_number: sale.receipt_number ?? `VENTA-${index + 1}`,
        document_type: sale.document_type ?? 'sale_note',
        customer: sale.customer ?? 'Cliente demo',
        total: Number(sale.total ?? 0),
        balance_due: Number(sale.balance_due ?? 0),
        status: sale.status ?? 'Registrado',
    }));
    const bankAccount = demo.sandbox?.bankAccounts?.[0] ?? {};

    return {
        branches: demo.sandbox?.branches ?? [{ id: 1, name: 'Sucursal demo', code: 'DEMO' }],
        products,
        customers: demo.sandbox?.customers?.length ? demo.sandbox.customers : [{ id: 1, name: 'Cliente demo', document: 'DEMO', phone: '70000000' }],
        suppliers: demo.sandbox?.suppliers?.length ? demo.sandbox.suppliers : [{ id: 1, name: 'Proveedor demo', tax_id: 'DEMO', phone: '70000001' }],
        sales,
        purchases: [],
        cash: { open: Number(demo.sandbox?.totals?.cashOpen ?? 0) > 0, amount: Math.max(500, Number(demo.sandbox?.totals?.payments ?? 0)) },
        bank: { balance: Number(bankAccount.balance ?? 0), movements: [] },
        audit: [demo.sandbox?.generatedAt ? 'Sandbox iniciado con copia temporal de datos reales.' : 'Sandbox iniciado con datos demo.'],
    };
}

function nextSandboxId(items) {
    return Math.max(0, ...items.map((item) => Number(item.id || 0))) + 1;
}

function roundMoney(value) {
    return Math.round(Number(value || 0) * 10) / 10;
}

function runDemoStep(current, step) {
    if (!step?.enabled) {
        return { ...current, lastAction: 'Este paso esta oculto por el perfil configurado.' };
    }

    if (step.key === 'cash') {
        return { ...current, cashOpen: true, lastAction: 'Caja demo abierta.' };
    }

    if (step.key === 'pos') {
        const nextStock = Math.max(current.stock - 3, 0);

        return {
            ...current,
            cashOpen: true,
            cash: current.cash + 90,
            bank: current.bank + 58,
            bankMovements: current.bankMovements + 1,
            stock: nextStock,
            documents: [{ type: 'POS', total: 148 }, ...current.documents],
            lastAction: 'Venta POS demo cobrada con efectivo y QR.',
        };
    }

    if (step.key === 'sale') {
        return {
            ...current,
            stock: Math.max(current.stock - 7, 0),
            documents: [{ type: step.badge, total: 690 }, ...current.documents],
            lastAction: 'Documento comercial demo generado.',
        };
    }

    if (step.key === 'purchase') {
        return {
            ...current,
            stock: current.stock + 15,
            bank: Math.max(current.bank - 180.6, 0),
            bankMovements: current.bankMovements + 1,
            documents: [{ type: 'Compra', total: 1806 }, ...current.documents],
            lastAction: 'Compra demo registrada y stock simulado aumentado.',
        };
    }

    if (step.key === 'delivery') {
        return {
            ...current,
            deliveries: current.deliveries + 1,
            lastAction: 'Despacho demo registrado para los items seleccionados.',
        };
    }

    return current;
}

function ComparisonPanel({ rows }) {
    const groups = rows.reduce((carry, row) => {
        const group = row.group ?? 'General';
        carry[group] = [...(carry[group] ?? []), row];

        return carry;
    }, {});

    return (
        <div className="space-y-4">
            {Object.entries(groups).map(([group, groupRows]) => {
                const changedCount = groupRows.filter((row) => row.changed).length;

                return (
                    <section key={group} className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                        <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <h3 className="font-semibold text-slate-950 dark:text-white">{group}</h3>
                            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${changedCount > 0 ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200'}`}>
                                {changedCount} cambios
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <tr>
                                        <th className="px-4 py-3">Configuracion</th>
                                        <th className="px-4 py-3">Actual</th>
                                        <th className="px-4 py-3">Borrador</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {groupRows.map((row) => (
                                        <tr key={`${group}-${row.label}`} className={row.changed ? 'bg-amber-50/70 dark:bg-amber-500/10' : ''}>
                                            <td className="px-4 py-3 font-semibold">{row.label}</td>
                                            <td className="px-4 py-3">{row.current}</td>
                                            <td className="px-4 py-3">{row.next}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                );
            })}
        </div>
    );
}

function HistoryPanel({ versions, activeConfiguration, options }) {
    return (
        <div className="space-y-3">
            {versions.length === 0 ? <p className="text-sm text-slate-500">Todavia no hay versiones anteriores.</p> : null}
            {versions.map((version) => {
                const diffRows = buildComparison(activeConfiguration, version.configuration, options).filter((row) => row.changed);

                return (
                <div key={version.id} className="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="font-semibold text-slate-950 dark:text-white">Version {version.version_number}: {version.name}</p>
                        <p className="text-xs text-slate-500">Aplicado por {version.applied_by?.name ?? 'Sistema'} - {version.applied_at ? new Date(version.applied_at).toLocaleString('es-BO') : '-'}</p>
                        <p className="mt-1 text-xs font-semibold text-amber-600 dark:text-amber-300">{diffRows.length} diferencias contra el perfil activo actual</p>
                    </div>
                    <button type="button" onClick={() => router.post(route('system-superadmin.business-profiles.versions.restore', version.id), {}, { preserveScroll: true })} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                        Restaurar
                    </button>
                    </div>
                    {diffRows.length > 0 ? (
                        <div className="mt-3 grid gap-2 md:grid-cols-2">
                            {diffRows.slice(0, 6).map((row) => (
                                <div key={row.label} className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-100">
                                    <span className="font-semibold">{row.label}:</span> actual {row.current} / version {row.next}
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            )})}
        </div>
    );
}

function PresetPanel({ presets }) {
    return (
        <div className="space-y-3">
            <div>
                <h3 className="text-base font-semibold text-slate-950 dark:text-white">Presets guardados</h3>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Usa presets para reutilizar configuraciones completas por rubro sin afectar produccion hasta convertirlos en borrador y aplicarlos.
                </p>
            </div>
            {presets.length === 0 ? <p className="rounded-2xl border border-dashed border-slate-300 p-4 text-sm text-slate-500 dark:border-slate-700">Todavia no hay presets personalizados.</p> : null}
            {presets.map((preset) => (
                <div key={preset.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                    <div>
                        <p className="font-semibold text-slate-950 dark:text-white">{preset.name}</p>
                        <p className="text-xs text-slate-500">{preset.business_type} - {preset.is_system ? 'Base del sistema' : 'Personalizado'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => router.post(route('system-superadmin.business-profiles.presets.draft', preset.id), {}, { preserveScroll: true })} className="rounded-full bg-brand-primary px-4 py-2 text-sm font-semibold text-white">
                            Crear borrador
                        </button>
                        {!preset.is_system ? (
                            <button type="button" onClick={() => router.delete(route('system-superadmin.business-profiles.presets.destroy', preset.id), { preserveScroll: true })} className="rounded-full border border-red-200 px-4 py-2 text-sm font-semibold text-red-600">
                                Eliminar
                            </button>
                        ) : null}
                    </div>
                </div>
            ))}
        </div>
    );
}

function buildComparison(current, next, options) {
    const rows = [
        ['Ventas', 'Flujo de venta', options.salesWorkflows[current.sales.workflow], options.salesWorkflows[next.sales.workflow]],
        ['Ventas', 'Uso de cotizacion', options.quotationModes[current.sales.quotation_mode], options.quotationModes[next.sales.quotation_mode]],
        ['Ventas', 'Documento principal', options.documents[current.sales.document_main], options.documents[next.sales.document_main]],
        ['Ventas', 'Clientes en venta', options.entityModes[current.sales.customer_mode], options.entityModes[next.sales.customer_mode]],
        ['Ventas', 'Descuento de inventario', options.inventoryTimings[current.sales.inventory_discount_timing], options.inventoryTimings[next.sales.inventory_discount_timing]],
        ['Facturacion', 'Modulo SIAT', booleanLabels[String(Boolean(current.modules.billing))], booleanLabels[String(Boolean(next.modules.billing))]],
        ['Facturacion', 'Flujo fiscal', options.billingFlows[current.billing?.invoice_flow], options.billingFlows[next.billing?.invoice_flow]],
        ['Facturacion', 'Momento de emision', options.billingIssueTimings[current.billing?.issue_timing], options.billingIssueTimings[next.billing?.issue_timing]],
        ['Facturacion', 'Modalidad', current.billing?.mode === 'electronic_online' ? 'Electronica en linea' : 'Computarizada en linea', next.billing?.mode === 'electronic_online' ? 'Electronica en linea' : 'Computarizada en linea'],
        ['Facturacion', 'Homologacion obligatoria', booleanLabels[String(Boolean(current.billing?.require_product_mapping))], booleanLabels[String(Boolean(next.billing?.require_product_mapping))]],
        ['Politicas comerciales', 'Politica de precios', options.pricePolicies[current.sales.price_policy], options.pricePolicies[next.sales.price_policy]],
        ['Politicas comerciales', 'Politica de descuentos', options.discountPolicies[current.sales.discount_policy], options.discountPolicies[next.sales.discount_policy]],
        ['Politicas comerciales', 'Descuento maximo', `${current.sales.max_discount_percent ?? 0}%`, `${next.sales.max_discount_percent ?? 0}%`],
        ['Politicas comerciales', 'Politica de credito', options.creditLimitPolicies[current.sales.credit_limit_policy], options.creditLimitPolicies[next.sales.credit_limit_policy]],
        ['Politicas comerciales', 'Limite credito base', `Bs ${current.sales.default_credit_limit ?? 0}`, `Bs ${next.sales.default_credit_limit ?? 0}`],
        ['Politicas comerciales', 'Stock negativo', options.negativeStockPolicies[current.sales.negative_stock_policy], options.negativeStockPolicies[next.sales.negative_stock_policy]],
        ['Caja y bancos', 'Caja obligatoria', booleanLabels[String(Boolean(current.cash.required_to_sell))], booleanLabels[String(Boolean(next.cash.required_to_sell))]],
        ['Caja y bancos', 'Alcance de caja', options.cashScopes[current.cash.scope], options.cashScopes[next.cash.scope]],
        ['Caja y bancos', 'Conciliacion QR/Banco', options.bankReconciliationModes[current.banks.reconciliation_mode], options.bankReconciliationModes[next.banks.reconciliation_mode]],
        ['POS', 'POS rapido', booleanLabels[String(Boolean(current.modules.pos))], booleanLabels[String(Boolean(next.modules.pos))]],
        ['POS', 'Lector de barras', options.scannerModes[current.pos.scanner_mode], options.scannerModes[next.pos.scanner_mode]],
        ['POS', 'Modo offline POS', options.offlineModes[current.pos.offline_mode], options.offlineModes[next.pos.offline_mode]],
        ['Inventario', 'Catalogo de productos', options.catalogModes[current.products.catalog_mode], options.catalogModes[next.products.catalog_mode]],
        ['Inventario', 'Creacion de productos', options.productCreationContexts[current.products.creation_context], options.productCreationContexts[next.products.creation_context]],
        ['Inventario', 'Despachos', options.deliveryModes[current.deliveries.mode], options.deliveryModes[next.deliveries.mode]],
        ['Compras', 'Proveedores en compra', options.entityModes[current.purchases.supplier_mode], options.entityModes[next.purchases.supplier_mode]],
        ['Compras', 'Compra rapida', booleanLabels[String(Boolean(current.purchases.barcode_entry))], booleanLabels[String(Boolean(next.purchases.barcode_entry))]],
    ];

    return rows.map(([group, label, currentValue, nextValue]) => ({
        group,
        label,
        current: currentValue ?? '-',
        next: nextValue ?? '-',
        changed: String(currentValue) !== String(nextValue),
    }));
}

function buildDemo(businessType, configuration, options, sandboxSnapshot = {}) {
    const typeLabel = options.businessTypes[businessType] ?? 'Negocio';
    const isPos = configuration.sales.workflow === 'pos' || configuration.modules.pos;
    const quotationDisabled = configuration.sales.quotation_mode === 'disabled' || !configuration.modules.quotes;
    const cashRequired = Boolean(configuration.cash.required_to_sell);
    const purchaseBarcode = Boolean(configuration.purchases.barcode_entry || configuration.modules.quick_purchases);
    const scannerRequired = configuration.pos.scanner_mode === 'required';
    const offlineEnabled = configuration.pos.offline_mode !== 'disabled';
    const customerMode = configuration.sales.customer_mode ?? (configuration.sales.customer_required ? 'required' : 'optional');
    const supplierMode = configuration.purchases.supplier_mode ?? 'optional';
    const deliveryMode = configuration.deliveries.mode ?? (configuration.modules.deliveries ? 'optional' : 'disabled');
    const bankMode = configuration.banks.reconciliation_mode ?? (configuration.cash.bank_reconciliation ? 'automatic' : 'disabled');
    const billingFlow = configuration.billing?.invoice_flow ?? 'billing_disabled';
    const billingEnabled = Boolean(configuration.modules.billing) && billingFlow !== 'billing_disabled';
    const navigation = [
        { label: 'Panel', enabled: true },
        { label: 'Alertas', enabled: Boolean(configuration.modules.alerts) },
        { label: 'Reportes', enabled: Boolean(configuration.modules.reports) },
        { label: 'POS rapido', enabled: Boolean(configuration.modules.pos) },
        { label: 'Cotizaciones', enabled: Boolean(configuration.modules.quotes) && configuration.sales.quotation_mode !== 'disabled' },
        { label: 'Notas de venta', enabled: Boolean(configuration.modules.sales_notes) },
        { label: 'Compras', enabled: Boolean(configuration.modules.purchases) },
        { label: 'Caja', enabled: Boolean(configuration.modules.cash) },
        { label: 'Bancos', enabled: Boolean(configuration.modules.banks) },
        { label: 'Facturacion SIAT', enabled: billingEnabled },
        { label: 'Inventario', enabled: Boolean(configuration.modules.inventory) },
        { label: 'Reservas', enabled: Boolean(configuration.modules.inventory) && Boolean(configuration.modules.reservations) },
        { label: 'Transferencias', enabled: Boolean(configuration.modules.inventory) && Boolean(configuration.modules.transfers) },
        { label: 'Despachos', enabled: Boolean(configuration.modules.deliveries) && deliveryMode !== 'disabled' },
        { label: 'Clientes', enabled: Boolean(configuration.modules.customers) && customerMode !== 'hidden' },
        { label: 'Proveedores', enabled: Boolean(configuration.modules.suppliers) && supplierMode !== 'hidden' },
        { label: 'Gastos', enabled: Boolean(configuration.modules.expenses) },
        { label: 'Sueldos', enabled: Boolean(configuration.modules.payroll) },
        { label: 'Trabajadores', enabled: Boolean(configuration.modules.workers) },
        { label: 'Produccion', enabled: Boolean(configuration.modules.production) },
        { label: 'Exportaciones', enabled: Boolean(configuration.modules.exports) },
        { label: 'Etiquetas barcode', enabled: Boolean(configuration.modules.barcode_labels) },
    ];
    const sandboxProducts = Array.isArray(sandboxSnapshot.products) ? sandboxSnapshot.products : [];
    const sandboxCart = sandboxProducts.slice(0, 2).map((product, index) => ({
        name: product.name,
        quantity: index === 0 ? '2' : '1',
        unit: product.unit ?? 'unidad',
        price: `Bs ${Number(product.price || 0).toFixed(1)}`,
        total: `Bs ${(Number(product.price || 0) * (index === 0 ? 2 : 1)).toFixed(1)}`,
    }));
    const cart = sandboxCart.length > 0 ? sandboxCart : (isPos
        ? [
            { name: 'Casco de seguridad', quantity: '2', unit: 'unidad', price: 'Bs 45.0', total: 'Bs 90.0' },
            { name: 'Clavo 2 pulgadas', quantity: '1', unit: 'bolsa', price: 'Bs 58.0', total: 'Bs 58.0' },
        ]
        : [
            { name: 'Calamina azul 0.50 mm', quantity: '5', unit: 'hojas de 10 m', price: 'Bs 120.0', total: 'Bs 600.0' },
            { name: 'Casco de seguridad', quantity: '2', unit: 'unidad', price: 'Bs 45.0', total: 'Bs 90.0' },
        ]);
    const documentFields = [
        { label: 'Cliente', value: options.entityModes[customerMode] ?? '-' },
        { label: 'Proveedor', value: options.entityModes[supplierMode] ?? '-' },
        { label: 'Caja', value: cashRequired ? 'Debe estar abierta' : 'No obligatoria' },
        { label: 'Bancos/QR', value: options.bankReconciliationModes[bankMode] ?? '-' },
        { label: 'Documento', value: options.documents[configuration.sales.document_main] ?? 'Nota de venta' },
        { label: 'Factura SIAT', value: options.billingFlows[billingFlow] ?? 'Sin facturacion fiscal' },
        { label: 'Emision fiscal', value: options.billingIssueTimings[configuration.billing?.issue_timing] ?? '-' },
        { label: 'Cotizacion', value: options.quotationModes[configuration.sales.quotation_mode] ?? '-' },
        { label: 'Despacho', value: options.deliveryModes[deliveryMode] ?? '-' },
        { label: 'Inventario', value: options.inventoryTimings[configuration.sales.inventory_discount_timing] ?? '-' },
        { label: 'Precio', value: priceOverrideLabel(configuration.sales.allow_price_override) },
        { label: 'Lista precios', value: options.pricePolicies[configuration.sales.price_policy] ?? '-' },
        { label: 'Descuentos', value: options.discountPolicies[configuration.sales.discount_policy] ?? '-' },
        { label: 'Credito', value: options.creditLimitPolicies[configuration.sales.credit_limit_policy] ?? '-' },
        { label: 'Stock negativo', value: options.negativeStockPolicies[configuration.sales.negative_stock_policy] ?? '-' },
        { label: 'Barcode', value: options.scannerModes[configuration.pos.scanner_mode] ?? '-' },
        { label: 'Catalogo', value: options.catalogModes[configuration.products.catalog_mode] ?? '-' },
    ];
    const purchaseItems = [
        { name: purchaseBarcode ? 'Producto escaneado 7891000254301' : 'Calamina azul 0.50 mm', quantity: purchaseBarcode ? '12' : '1.5', unit: purchaseBarcode ? 'unidad' : 'tonelada', price: 'Bs 98.0', total: 'Bs 1,176.0' },
        { name: configuration.purchases.allow_create_product ? 'Producto nuevo desde compra' : 'Producto ya registrado', quantity: '3', unit: 'caja', price: 'Bs 210.0', total: 'Bs 630.0' },
    ];
    const flowSteps = [
        {
            key: 'cash',
            enabled: Boolean(configuration.modules.cash),
            kind: 'cash',
            area: 'Caja',
            title: cashRequired ? 'Abrir caja obligatoria' : 'Caja opcional',
            screenTitle: cashRequired ? 'Control de apertura de caja' : 'Caja disponible sin bloqueo de venta',
            badge: cashRequired ? 'Requerida' : 'Opcional',
            description: cashRequired ? 'El cajero debe abrir caja antes de vender o cobrar.' : 'El perfil permite vender aunque no exista caja abierta.',
            fields: [
                { label: 'Alcance', value: options.cashScopes[configuration.cash.scope] ?? '-' },
                { label: 'Efectivo inicial', value: 'Bs 500.0' },
                { label: 'QR/Banco', value: options.bankReconciliationModes[bankMode] ?? '-' },
            ],
            items: [],
            total: 'Bs 500.0',
            primaryAction: 'Abrir caja demo',
            result: cashRequired ? 'Validar que exista caja abierta antes de venta/cobro.' : 'Permitir operacion aun sin caja abierta.',
        },
        {
            key: 'pos',
            enabled: Boolean(configuration.modules.pos),
            kind: 'pos',
            area: 'POS',
            title: 'Venta POS',
            screenTitle: scannerRequired ? 'Venta por lector obligatorio' : 'Venta rapida por codigo o busqueda',
            badge: options.scannerModes[configuration.pos.scanner_mode] ?? 'POS',
            description: offlineEnabled ? 'Si falla la red, el POS puede dejar la venta en cola local segun el perfil.' : 'La venta se registra con conexion activa.',
            fields: documentFields,
            items: cart,
            total: 'Bs 148.0',
            primaryAction: 'Cobrar POS demo',
            result: 'Escanear producto, sumar carrito y cobrar sin tocar datos reales.',
        },
        {
            key: 'sale',
            enabled: Boolean(configuration.modules.sales_notes),
            kind: 'document',
            area: 'Comercial',
            title: quotationDisabled ? 'Venta directa' : 'Cotizacion y nota',
            screenTitle: quotationDisabled ? 'Nota de venta directa' : 'Documento comercial con cotizacion',
            badge: quotationDisabled ? 'Directo' : 'Documental',
            description: customerMode === 'hidden' ? 'La venta se registra sin pedir datos de cliente.' : 'La venta respeta si el cliente es obligatorio u opcional.',
            fields: documentFields,
            items: cart,
            total: 'Bs 690.0',
            primaryAction: quotationDisabled ? 'Emitir nota demo' : 'Crear cotizacion demo',
            result: billingFlow === 'direct_invoice'
                ? 'Crear venta interna y factura fiscal automaticamente.'
                : (quotationDisabled ? 'Crear nota directa segun perfil.' : 'Crear cotizacion y luego convertirla a nota si corresponde.'),
        },
        {
            key: 'billing',
            enabled: billingEnabled,
            kind: 'document',
            area: 'Facturacion',
            title: options.billingFlows[billingFlow] ?? 'Facturacion SIAT',
            screenTitle: 'Emision fiscal SIAT',
            badge: options.billingIssueTimings[configuration.billing?.issue_timing] ?? 'Manual',
            description: configuration.billing?.mode === 'electronic_online'
                ? 'Requiere certificado digital y firma XML en servidor.'
                : 'Modalidad computarizada en linea con XML, CUF, CUFD y envio SOAP.',
            fields: [
                { label: 'Modalidad', value: configuration.billing?.mode === 'electronic_online' ? 'Electronica en linea' : 'Computarizada en linea' },
                { label: 'CUFD', value: configuration.billing?.auto_request_cufd ? 'Automatico si falta' : 'Debe existir vigente' },
                { label: 'Cliente fiscal', value: configuration.billing?.require_customer_tax_data ? 'Obligatorio' : 'Flexible' },
                { label: 'Productos SIN', value: configuration.billing?.require_product_mapping ? 'Homologacion obligatoria' : 'Advertencia' },
                { label: 'Offline', value: configuration.billing?.offline_behavior ?? 'temporary_receipt' },
            ],
            items: cart,
            total: isPos ? 'Factura POS demo' : 'Factura venta demo',
            primaryAction: 'Emitir factura demo',
            result: 'Validar configuracion fiscal, generar XML y registrar respuesta SIAT sin tocar produccion.',
        },
        {
            key: 'purchase',
            enabled: Boolean(configuration.modules.purchases),
            kind: 'document',
            area: 'Compras',
            title: purchaseBarcode ? 'Compra por barcode' : 'Compra tradicional',
            screenTitle: purchaseBarcode ? 'Ingreso rapido de mercaderia' : 'Registro formal de compra',
            badge: supplierMode === 'required' ? 'Proveedor obligatorio' : 'Compra',
            description: configuration.purchases.allow_create_product ? 'Permite crear producto nuevo desde la compra en esta simulacion.' : 'Solo permite productos ya registrados.',
            fields: [
                { label: 'Proveedor', value: options.entityModes[supplierMode] ?? '-' },
                { label: 'Barcode', value: purchaseBarcode ? 'Habilitado' : 'No obligatorio' },
                { label: 'Producto nuevo', value: configuration.purchases.allow_create_product ? 'Permitido' : 'Bloqueado' },
                { label: 'Egreso contable', value: configuration.purchases.register_expense_when_paid ? 'Al pagar compra' : 'No automatico' },
            ],
            items: purchaseItems,
            total: 'Bs 1,806.0',
            primaryAction: 'Registrar compra demo',
            result: 'Registrar compra y actualizar inventario solo en simulacion.',
        },
        {
            key: 'delivery',
            enabled: Boolean(configuration.modules.deliveries) && deliveryMode !== 'disabled',
            kind: 'document',
            area: 'Despachos',
            title: options.deliveryModes[deliveryMode] ?? 'Despacho',
            screenTitle: deliveryMode === 'required' ? 'Despacho obligatorio de nota' : 'Despacho opcional',
            badge: deliveryMode === 'required' ? 'Obligatorio' : 'Opcional',
            description: 'Simula seleccion de productos entregados, conductor y camion segun perfil.',
            fields: [
                { label: 'Conductor', value: configuration.deliveries.driver_required ? 'Obligatorio' : 'Opcional/manual' },
                { label: 'Camion', value: configuration.deliveries.truck_required ? 'Obligatorio' : 'Opcional/manual' },
                { label: 'Inventario', value: options.inventoryTimings[configuration.sales.inventory_discount_timing] ?? '-' },
            ],
            items: cart.map((item) => ({ ...item, total: item.quantity })),
            total: 'Entrega parcial/total',
            primaryAction: 'Registrar despacho demo',
            result: 'Validar entrega de uno o varios productos sin crear despacho real.',
        },
    ];
    const warnings = [
        quotationDisabled ? 'Las cotizaciones quedaran ocultas para el negocio activo.' : null,
        !configuration.modules.purchases ? 'Las compras quedaran bloqueadas por ruta y no solo ocultas del menu.' : null,
        !configuration.modules.purchases ? 'Si desactivas compras, tambien se ocultaran pagos a proveedores y los flujos de compra rapida.' : null,
        !configuration.modules.suppliers ? 'Si desactivas proveedores, compras no podra exigir proveedor aunque el flujo lo marque como obligatorio.' : null,
        !configuration.modules.sales_notes ? 'Si desactivas notas de venta, tambien se afectaran cobros, devoluciones y promesas de pago.' : null,
        !configuration.modules.inventory ? 'Si desactivas inventario, tambien se ocultaran kardex, reservas, transferencias y alertas de stock.' : null,
        !configuration.modules.reports ? 'Reportes quedara oculto y sus rutas internas rechazaran el acceso.' : null,
        !configuration.modules.exports ? 'Exportaciones quedara oculto y no se podran descargar datos desde el modulo.' : null,
        !billingEnabled ? 'Facturacion SIAT quedara desactivada; el sistema seguira emitiendo solo documentos internos.' : null,
        billingFlow === 'direct_invoice' ? 'La venta directa intentara emitir factura inmediatamente; si SIAT falla puede bloquear la venta segun configuracion.' : null,
        billingFlow === 'quote_sale_note_invoice' ? 'La ferreteria trabajara con cotizacion obligatoria, luego nota de venta y despues factura SIAT.' : null,
        billingFlow === 'choose_per_sale' ? 'El usuario decidira en cada venta si emite factura o solo documento interno.' : null,
        configuration.billing?.mode === 'electronic_online' ? 'La modalidad electronica requiere certificado digital seguro en servidor, no en el navegador.' : null,
        billingEnabled && configuration.billing?.require_product_mapping ? 'Todo producto facturable debe estar homologado con codigo SIN, actividad economica y unidad SIAT.' : null,
        billingEnabled && configuration.billing?.offline_behavior === 'temporary_receipt' ? 'En contingencia se emitira recibo temporal y luego debera enviarse paquete SIAT.' : null,
        !configuration.modules.expenses ? 'Gastos quedara oculto; los egresos operativos deberan gestionarse por otro flujo activo.' : null,
        !configuration.modules.payroll ? 'Pago de sueldos quedara oculto aunque existan trabajadores registrados.' : null,
        !configuration.modules.workers ? 'Trabajadores quedara oculto y no se podra gestionar personal desde el sistema.' : null,
        !configuration.modules.barcode_labels ? 'La impresion de etiquetas barcode quedara desactivada desde productos.' : null,
        deliveryMode === 'disabled' ? 'Despachos quedara oculto y el backend rechazara nuevos despachos.' : null,
        deliveryMode === 'required' ? 'Toda nota de venta exigira pasar por despacho segun este perfil.' : null,
        customerMode === 'hidden' ? 'Clientes no se pediran en ventas; util para supermercado o tienda rapida.' : null,
        supplierMode === 'required' ? 'Compras exigira proveedor antes de registrar la operacion.' : null,
        supplierMode === 'hidden' ? 'Proveedores quedara oculto para compras rapidas sin proveedor.' : null,
        bankMode === 'manual' ? 'Los pagos QR/Banco quedaran pendientes para conciliacion manual.' : null,
        bankMode === 'disabled' ? 'No se crearan movimientos bancarios automaticos por pagos QR/Banco.' : null,
        !cashRequired ? 'Los usuarios podran vender sin caja abierta si tambien tienen permisos de venta.' : null,
        configuration.sales.allow_negative_stock ? 'Se permitiran ventas sin stock suficiente; usar solo si el negocio acepta preventas.' : null,
        configuration.sales.discount_policy === 'never' ? 'Los descuentos quedaran bloqueados incluso si el usuario intenta aplicarlos.' : null,
        Number(configuration.sales.max_discount_percent ?? 0) > 0 ? `Ningun descuento podra superar ${configuration.sales.max_discount_percent}% salvo ajuste futuro de politica.` : null,
        configuration.sales.credit_limit_policy === 'block' ? 'Las ventas a credito se bloquearan si superan el limite configurado.' : null,
        configuration.sales.price_policy !== 'base_price' ? 'La politica de precios requiere mantener listas por sucursal o cliente para evitar precios incompletos.' : null,
        configuration.sales.negative_stock_policy !== 'never' ? 'El stock negativo se evaluara por la politica avanzada configurada.' : null,
        configuration.sales.inventory_discount_timing !== 'sale_note' ? 'El inventario no se descontara al emitir la nota; se movera segun el momento configurado.' : null,
        !configuration.modules.banks && configuration.cash.bank_reconciliation ? 'La conciliacion bancaria esta activa, pero el modulo Bancos esta oculto.' : null,
        scannerRequired && !configuration.modules.pos ? 'El lector esta como obligatorio, pero el modulo POS esta oculto.' : null,
        offlineEnabled && !configuration.modules.offline_pos ? 'El modo offline esta configurado, pero el modulo POS offline esta desactivado.' : null,
        configuration.products.barcode_required && configuration.products.catalog_mode !== 'barcode_retail' ? 'Barcode obligatorio funciona mejor con catalogo retail por codigo de barras.' : null,
    ].filter(Boolean);

    return {
        signature: JSON.stringify({
            businessType,
            modules: configuration.modules,
            sales: configuration.sales,
            purchases: configuration.purchases,
            deliveries: configuration.deliveries,
            banks: configuration.banks,
            cash: configuration.cash,
            pos: configuration.pos,
            products: configuration.products,
            sandboxGeneratedAt: sandboxSnapshot.generatedAt,
        }),
        inventorySeed: Number(sandboxSnapshot.totals?.stock ?? (businessType === 'supermarket' ? 250 : 80)),
        sandbox: sandboxSnapshot,
        navigation,
        primaryScreen: {
            kind: isPos ? 'pos' : 'document',
            area: isPos ? 'Caja POS' : 'Comercial',
            title: isPos ? 'Venta por lector de barras' : (quotationDisabled ? 'Nota de venta directa' : 'Cotizacion y nota de venta'),
            badge: isPos ? 'Rapido' : (quotationDisabled ? 'Directo' : 'Documental'),
        },
        documentFields,
        cart,
        cards: [
            {
                area: typeLabel,
                title: isPos ? 'Punto de venta rapido' : 'Venta administrativa',
                description: isPos ? 'El cajero escanea productos, cobra y emite ticket sin entrar a cotizaciones.' : 'El usuario trabaja con documentos comerciales, cliente y detalle formal.',
            },
            {
                area: 'Caja',
                title: configuration.cash.required_to_sell ? 'Caja obligatoria' : 'Caja opcional',
                description: configuration.cash.bank_reconciliation ? 'Los pagos QR y banco se concilian contra la caja activa.' : 'La caja registra solo movimientos operativos directos.',
            },
            {
                area: 'Inventario',
                title: configuration.products.unit_equivalences ? 'Unidades equivalentes' : 'Stock simple',
                description: configuration.products.unit_equivalences ? 'El sistema podra comprar por caja y vender por unidad segun equivalencias del producto.' : 'El stock se maneja en la unidad principal del producto.',
            },
            {
                area: 'Compras',
                title: purchaseBarcode ? 'Compra por barcode' : 'Compra tradicional',
                description: `${configuration.purchases.allow_create_product ? 'Se podran crear productos nuevos desde la compra.' : 'Solo se podran comprar productos ya registrados.'} Proveedor: ${options.entityModes[supplierMode] ?? 'Opcional'}.`,
            },
            {
                area: 'Despachos',
                title: options.deliveryModes[deliveryMode] ?? 'Despacho opcional',
                description: deliveryMode === 'required' ? 'La venta queda conectada al control de entrega.' : 'El negocio puede vender sin obligar entrega posterior.',
            },
        ],
        steps: isPos
            ? ['Abrir caja.', scannerRequired ? 'Escanear codigo de barras obligatorio.' : 'Escanear codigo o buscar producto manualmente.', 'Agregar o sumar producto al carrito.', offlineEnabled ? 'Si se corta internet, guardar en cola local autorizada.' : 'Cobrar con conexion activa.', 'Registrar movimiento y descontar inventario segun regla configurada.']
            : ['Crear cotizacion o venta segun configuracion.', 'Seleccionar cliente y productos.', 'Generar documento interno.', 'Registrar anticipo o pago.', 'Mover inventario segun regla configurada.'],
        flowSteps,
        warnings,
    };
}

function priceOverrideLabel(value) {
    return {
        never: 'No editable',
        permission: 'Solo con permiso',
        always: 'Editable siempre',
    }[value] ?? 'Solo con permiso';
}

function businessPreset(preset, current, defaults) {
    const base = {
        ...defaults,
        ...current,
        modules: { ...(defaults.modules ?? {}), ...(current.modules ?? {}) },
        sales: { ...(defaults.sales ?? {}), ...(current.sales ?? {}) },
        purchases: { ...(defaults.purchases ?? {}), ...(current.purchases ?? {}) },
        deliveries: { ...(defaults.deliveries ?? {}), ...(current.deliveries ?? {}) },
        banks: { ...(defaults.banks ?? {}), ...(current.banks ?? {}) },
        billing: { ...(defaults.billing ?? {}), ...(current.billing ?? {}) },
        pos: { ...(defaults.pos ?? {}), ...(current.pos ?? {}) },
        products: { ...(defaults.products ?? {}), ...(current.products ?? {}) },
        cash: { ...(defaults.cash ?? {}), ...(current.cash ?? {}) },
        inventory: { ...(defaults.inventory ?? {}), ...(current.inventory ?? {}) },
        ux: { ...(defaults.ux ?? {}), ...(current.ux ?? {}) },
    };

    const presets = {
        hardware: {
            businessType: 'hardware_store',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: false, purchases: true, quick_purchases: false, cash: true, banks: true, billing: true, inventory: true, deliveries: true, customers: true, suppliers: true },
                sales: { ...base.sales, workflow: 'quotation_to_sale_note', quotation_mode: 'required', document_main: 'sale_note', customer_mode: 'required', customer_required: true, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                billing: { ...base.billing, enabled: true, invoice_flow: 'quote_sale_note_invoice', issue_from: 'sale_note', issue_timing: 'automatic_after_quote_conversion' },
                purchases: { ...base.purchases, workflow: 'standard_purchase', barcode_entry: false, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'optional', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'mixed_inventory', barcode_required: false, unit_equivalences: true, allow_service_items: false, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'user_branch', bank_reconciliation: true },
            },
        },
        hardware_pos: {
            businessType: 'hardware_store',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, billing: true, inventory: true, deliveries: true, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'optional_quotation', quotation_mode: 'optional', document_main: 'ticket', customer_mode: 'optional', customer_required: false, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                billing: { ...base.billing, enabled: true, invoice_flow: 'choose_per_sale', issue_from: 'manual_choice', issue_timing: 'manual' },
                purchases: { ...base.purchases, workflow: 'barcode_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'optional', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'mixed_inventory', barcode_required: false, unit_equivalences: true, allow_service_items: false, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'pos_terminal', bank_reconciliation: true },
            },
        },
        store_pos: {
            businessType: 'store',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: false, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, billing: false, inventory: true, deliveries: false, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'pos', quotation_mode: 'disabled', document_main: 'ticket', customer_mode: 'optional', customer_required: false, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'barcode_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'disabled', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'barcode_retail', barcode_required: true, unit_equivalences: true, allow_service_items: false, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'pos_terminal', bank_reconciliation: true },
            },
        },
        supermarket: {
            businessType: 'supermarket',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: false, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, billing: true, inventory: true, deliveries: false, customers: false, suppliers: true, offline_pos: true },
                sales: { ...base.sales, workflow: 'pos', quotation_mode: 'disabled', document_main: 'invoice_direct', customer_mode: 'hidden', customer_required: false, allow_price_override: 'permission', inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                billing: { ...base.billing, enabled: true, invoice_flow: 'direct_invoice', issue_from: 'pos', issue_timing: 'automatic_direct' },
                purchases: { ...base.purchases, workflow: 'barcode_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'disabled', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'required', offline_mode: 'local_queue', payment_flow: 'single_or_mixed', customer_prompt: 'hidden' },
                products: { ...base.products, catalog_mode: 'barcode_retail', barcode_required: true, unit_equivalences: true, allow_service_items: false, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'pos_terminal', bank_reconciliation: true, allow_offline_cash_sales: true },
            },
        },
        bookstore: {
            businessType: 'bookstore',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, inventory: true, deliveries: false, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'optional_quotation', quotation_mode: 'optional', document_main: 'ticket', customer_mode: 'optional', customer_required: false, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'barcode_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'disabled', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'barcode_retail', barcode_required: true, unit_equivalences: false, allow_service_items: false, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'pos_terminal', bank_reconciliation: true },
            },
        },
        stationery: {
            businessType: 'stationery',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, inventory: true, deliveries: false, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'optional_quotation', quotation_mode: 'optional', document_main: 'ticket', customer_mode: 'optional', customer_required: false, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'standard_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'disabled', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'mixed_inventory', barcode_required: false, unit_equivalences: true, allow_service_items: true, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'user_branch', bank_reconciliation: true },
            },
        },
        factory: {
            businessType: 'factory',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: false, purchases: true, quick_purchases: false, cash: true, banks: true, inventory: true, deliveries: true, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'quotation_to_sale_note', quotation_mode: 'required', document_main: 'sale_note', customer_mode: 'required', customer_required: true, inventory_discount_timing: 'delivery', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'order_to_purchase', barcode_entry: false, allow_create_product: false, supplier_mode: 'required' },
                deliveries: { ...base.deliveries, mode: 'required', driver_required: true, truck_required: true },
                banks: { ...base.banks, reconciliation_mode: 'manual', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'disabled', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'required' },
                products: { ...base.products, catalog_mode: 'warehouse', barcode_required: false, unit_equivalences: true, allow_service_items: false, creation_context: 'restricted' },
                cash: { ...base.cash, required_to_sell: false, scope: 'branch', bank_reconciliation: true },
            },
        },
        services: {
            businessType: 'services',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: false, purchases: false, quick_purchases: false, cash: true, banks: true, inventory: false, deliveries: false, customers: true, suppliers: false },
                sales: { ...base.sales, workflow: 'service_sale', quotation_mode: 'optional', document_main: 'receipt', customer_mode: 'required', customer_required: true, inventory_discount_timing: 'manual', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'standard_purchase', barcode_entry: false, allow_create_product: false, supplier_mode: 'hidden' },
                deliveries: { ...base.deliveries, mode: 'disabled', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'manual', require_branch_account: false },
                pos: { ...base.pos, scanner_mode: 'disabled', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'required' },
                products: { ...base.products, catalog_mode: 'services', barcode_required: false, unit_equivalences: false, allow_service_items: true, creation_context: 'restricted' },
                cash: { ...base.cash, required_to_sell: false, scope: 'user_branch', bank_reconciliation: true },
            },
        },
        mixed: {
            businessType: 'mixed',
            configuration: {
                ...base,
                modules: { ...base.modules, quotes: true, sales_notes: true, pos: true, purchases: true, quick_purchases: true, cash: true, banks: true, inventory: true, deliveries: true, customers: true, suppliers: true, offline_pos: false },
                sales: { ...base.sales, workflow: 'optional_quotation', quotation_mode: 'optional', document_main: 'sale_note', customer_mode: 'optional', customer_required: false, inventory_discount_timing: 'sale_note', allow_negative_stock: false },
                purchases: { ...base.purchases, workflow: 'standard_purchase', barcode_entry: true, allow_create_product: true, supplier_mode: 'optional' },
                deliveries: { ...base.deliveries, mode: 'optional', driver_required: false, truck_required: false },
                banks: { ...base.banks, reconciliation_mode: 'automatic', require_branch_account: true },
                pos: { ...base.pos, scanner_mode: 'optional', offline_mode: 'disabled', payment_flow: 'single_or_mixed', customer_prompt: 'optional' },
                products: { ...base.products, catalog_mode: 'mixed_inventory', barcode_required: false, unit_equivalences: true, allow_service_items: true, creation_context: 'inventory_and_purchase' },
                cash: { ...base.cash, required_to_sell: true, scope: 'user_branch', bank_reconciliation: true },
            },
        },
    };

    return presets[preset] ?? presets.hardware;
}

function moduleLabel(key) {
    return {
        quotes: 'Cotizaciones',
        alerts: 'Alertas',
        sales_notes: 'Notas de venta',
        pos: 'POS rapido',
        purchases: 'Compras',
        quick_purchases: 'Compras rapidas',
        cash: 'Caja',
        banks: 'Bancos',
        billing: 'Facturacion SIAT',
        inventory: 'Inventario',
        deliveries: 'Despachos',
        customers: 'Clientes',
        suppliers: 'Proveedores',
        expenses: 'Gastos',
        returns: 'Devoluciones',
        payment_promises: 'Promesas de pago',
        reports: 'Reportes',
        exports: 'Exportaciones',
        production: 'Produccion',
        barcode_labels: 'Etiquetas de codigo de barras',
        workers: 'Trabajadores',
        payroll: 'Pago de sueldos',
        reservations: 'Reservas',
        transfers: 'Transferencias',
        offline_pos: 'POS offline',
    }[key] ?? key;
}

function moduleToggleHelp(key) {
    return {
        quotes: 'Desactivar este modulo oculta cotizaciones y evita crear documentos previos a la venta.',
        alerts: 'Desactivar este modulo oculta alertas operativas y avisos de stock o vencimientos.',
        sales_notes: 'Desactivar este modulo oculta notas de venta y afecta cobros, devoluciones y promesas de pago.',
        pos: 'Desactivar este modulo oculta el punto de venta rapido por busqueda o codigo de barras.',
        purchases: 'Desactivar este modulo bloquea compras, compras rapidas y pagos asociados a proveedores.',
        quick_purchases: 'Desactivar este submodulo quita la compra rapida por barcode, pero mantiene compras tradicionales si el modulo Compras sigue activo.',
        cash: 'Desactivar este modulo oculta apertura, cierre e historial de caja.',
        banks: 'Desactivar este modulo oculta cuentas bancarias, movimientos QR y conciliaciones.',
        billing: 'Desactivar este modulo oculta facturacion SIAT y deja solo documentos internos.',
        inventory: 'Desactivar este modulo oculta stock central, kardex, reservas, transferencias y alertas de inventario.',
        deliveries: 'Desactivar este modulo oculta despachos y bloquea nuevos registros de entrega.',
        customers: 'Desactivar este modulo oculta clientes; las ventas deberan trabajar como cliente opcional u oculto segun el flujo.',
        suppliers: 'Desactivar este modulo oculta proveedores; compras no podra exigir proveedor aunque el flujo lo marque como obligatorio.',
        expenses: 'Desactivar este modulo oculta gastos y egresos operativos.',
        returns: 'Desactivar este submodulo oculta devoluciones de venta.',
        payment_promises: 'Desactivar este submodulo oculta promesas de pago y seguimiento de compromisos.',
        reports: 'Desactivar este modulo oculta reportes e informes para usuarios normales.',
        exports: 'Desactivar este modulo oculta exportaciones y evita descargar datos desde perfiles normales.',
        production: 'Desactivar este modulo oculta funciones de produccion o fabricacion simple.',
        barcode_labels: 'Desactivar esta caracteristica oculta impresion de etiquetas con codigo de barras desde productos.',
        workers: 'Desactivar este modulo oculta gestion de trabajadores.',
        payroll: 'Desactivar este modulo oculta pago de sueldos aunque existan trabajadores registrados.',
        reservations: 'Desactivar este submodulo oculta reservas de inventario.',
        transfers: 'Desactivar este submodulo oculta transferencias entre sucursales.',
        offline_pos: 'Desactivar esta caracteristica impide usar el POS en modo offline o contingencia.',
    }[key] ?? 'Desactivar esta opcion la oculta del perfil de negocio y bloquea sus acciones relacionadas cuando corresponda.';
}

function uxLabel(key) {
    return {
        context_help: 'Ayudas contextuales',
        spanish_messages: 'Mensajes en espanol',
        responsive_tables: 'Tablas responsivas',
        demo_mode: 'Modo demo',
    }[key] ?? key;
}

function uxToggleHelp(key) {
    return {
        context_help: 'Desactivar esta caracteristica quita los mensajes de ayuda con signo de pregunta.',
        spanish_messages: 'Desactivar esta caracteristica permite mensajes tecnicos; se recomienda mantenerla activa para clientes finales.',
        responsive_tables: 'Desactivar esta caracteristica reduce los ajustes especiales de tablas en pantallas pequenas.',
        demo_mode: 'Desactivar esta caracteristica oculta la demo previa de cambios antes de aplicar el perfil.',
    }[key] ?? 'Controla una caracteristica visual o de experiencia del sistema.';
}
