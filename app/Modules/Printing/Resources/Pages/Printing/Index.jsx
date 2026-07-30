import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

export default function Index({
    branches = [],
    documentTypes = {},
    areas = {},
    paperTypes = {},
    triggers = {},
    templates,
    printerProfiles = [],
    rules = [],
    jobs = [],
    previewHtml = '',
    filters = {},
}) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('printing.manage');
    const canManageJobs = permissions.includes('printing.jobs.manage');
    const firstDocument = Object.keys(documentTypes)[0] ?? 'ticket_pos';
    const firstArea = Object.keys(areas)[0] ?? 'cashier';
    const firstPaper = Object.keys(paperTypes)[0] ?? 'letter';
    const firstTrigger = Object.keys(triggers)[0] ?? 'manual';

    const filterForm = useForm({
        document_type: filters.document_type ?? '',
        per_page: filters.per_page ?? 12,
    });
    const printerForm = useForm({
        branch_id: '',
        code: '',
        name: '',
        area: firstArea,
        paper_type: firstPaper,
        thermal_width_mm: '',
        copies: 1,
        auto_print: false,
    });
    const templateForm = useForm({
        branch_id: '',
        document_type: firstDocument,
        name: '',
        paper_type: firstPaper,
        thermal_width_mm: '',
        font_size: 12,
        margin_mm: 4,
        show_logo: false,
        show_barcode: firstDocument === 'barcode_label',
        color: '#000000',
        fields: [],
        is_default: false,
        is_active: true,
    });
    const ruleForm = useForm({
        branch_id: '',
        printer_profile_id: '',
        print_document_template_id: '',
        document_type: firstDocument,
        area: firstArea,
        trigger: firstTrigger,
        copies: 1,
        auto_print: false,
        is_active: true,
    });
    const jobForm = useForm({
        branch_id: branches[0]?.id ?? '',
        printer_profile_id: '',
        print_document_template_id: '',
        document_type: firstDocument,
        area: firstArea,
        copies: 1,
    });

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('printing.index'), { preserveScroll: true, preserveState: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Impresion</h2>}>
            <Head title="Impresion y documentos" />

            <section className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <ModuleIntro />

                    <div className="mb-6 grid gap-4 lg:grid-cols-4">
                        <InfoCard title="Plantillas" value={templates.total ?? 0} help="Formatos por documento, papel y sucursal." />
                        <InfoCard title="Impresoras" value={printerProfiles.length} help="Caja, cocina, barra, etiquetas o reportes." />
                        <InfoCard title="Reglas" value={rules.length} help="Define cuando y donde imprimir." />
                        <InfoCard title="Cola reciente" value={jobs.length} help="Trabajos listos para seguimiento." />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                        <div className="space-y-6">
                            {canManage ? (
                                <>
                                    <Panel title="Perfil de impresora" help="Crea impresoras logicas por area. Puede ser global o exclusiva de una sucursal.">
                                        <form onSubmit={(event) => submit(event, printerForm, route('printing.printers.store'), ['code', 'name'])} className="grid gap-4 md:grid-cols-3">
                                            <Select label="Sucursal" value={printerForm.data.branch_id} onChange={(value) => printerForm.setData('branch_id', value)} error={printerForm.errors.branch_id}>
                                                <option value="">Global</option>
                                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                            </Select>
                                            <Field label="Codigo" value={printerForm.data.code} onChange={(value) => printerForm.setData('code', value)} error={printerForm.errors.code} placeholder="CAJA-01" />
                                            <Field label="Nombre" value={printerForm.data.name} onChange={(value) => printerForm.setData('name', value)} error={printerForm.errors.name} placeholder="Impresora caja principal" />
                                            <Select label="Area" value={printerForm.data.area} onChange={(value) => printerForm.setData('area', value)} error={printerForm.errors.area}>
                                                {options(areas)}
                                            </Select>
                                            <Select label="Papel" value={printerForm.data.paper_type} onChange={(value) => printerForm.setData('paper_type', value)} error={printerForm.errors.paper_type}>
                                                {options(paperTypes)}
                                            </Select>
                                            <Field label="Ancho termico mm" type="number" value={printerForm.data.thermal_width_mm} onChange={(value) => printerForm.setData('thermal_width_mm', value)} error={printerForm.errors.thermal_width_mm} placeholder="58 / 80" />
                                            <Field label="Copias" type="number" min="1" max="5" value={printerForm.data.copies} onChange={(value) => printerForm.setData('copies', value)} error={printerForm.errors.copies} />
                                            <Toggle label="Impresion automatica" checked={printerForm.data.auto_print} onChange={(checked) => printerForm.setData('auto_print', checked)} />
                                            <Submit processing={printerForm.processing}>Guardar impresora</Submit>
                                        </form>
                                    </Panel>

                                    <Panel title="Plantilla de documento" help="Define papel, campos y estilo base para documentos nuevos como ticket, comanda u orden de servicio.">
                                        <form onSubmit={(event) => submit(event, templateForm, route('printing.templates.store'), ['name'])} className="space-y-4">
                                            <div className="grid gap-4 md:grid-cols-3">
                                                <Select label="Sucursal" value={templateForm.data.branch_id} onChange={(value) => templateForm.setData('branch_id', value)} error={templateForm.errors.branch_id}>
                                                    <option value="">Global</option>
                                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                                </Select>
                                                <Select label="Documento" value={templateForm.data.document_type} onChange={(value) => templateForm.setData({ ...templateForm.data, document_type: value, show_barcode: value === 'barcode_label' })} error={templateForm.errors.document_type}>
                                                    {options(documentTypes)}
                                                </Select>
                                                <Field label="Nombre" value={templateForm.data.name} onChange={(value) => templateForm.setData('name', value)} error={templateForm.errors.name} placeholder="Ticket caja 80 mm" />
                                                <Select label="Papel" value={templateForm.data.paper_type} onChange={(value) => templateForm.setData('paper_type', value)} error={templateForm.errors.paper_type}>
                                                    {options(paperTypes)}
                                                </Select>
                                                <Field label="Tamano letra" type="number" min="8" max="18" value={templateForm.data.font_size} onChange={(value) => templateForm.setData('font_size', value)} error={templateForm.errors.font_size} />
                                                <Field label="Margen mm" type="number" min="0" max="20" value={templateForm.data.margin_mm} onChange={(value) => templateForm.setData('margin_mm', value)} error={templateForm.errors.margin_mm} />
                                                <Field label="Ancho termico mm" type="number" value={templateForm.data.thermal_width_mm} onChange={(value) => templateForm.setData('thermal_width_mm', value)} error={templateForm.errors.thermal_width_mm} />
                                                <Field label="Color texto" type="color" value={templateForm.data.color} onChange={(value) => templateForm.setData('color', value)} error={templateForm.errors.color} />
                                            </div>
                                            <FieldPicker form={templateForm} documentType={templateForm.data.document_type} />
                                            <div className="grid gap-3 md:grid-cols-3">
                                                <Toggle label="Mostrar logo" checked={templateForm.data.show_logo} onChange={(checked) => templateForm.setData('show_logo', checked)} />
                                                <Toggle label="Mostrar codigo de barras" checked={templateForm.data.show_barcode} onChange={(checked) => templateForm.setData('show_barcode', checked)} />
                                                <Toggle label="Predeterminada" checked={templateForm.data.is_default} onChange={(checked) => templateForm.setData('is_default', checked)} />
                                            </div>
                                            <Submit processing={templateForm.processing}>Guardar plantilla</Submit>
                                        </form>
                                    </Panel>

                                    <Panel title="Regla de impresion" help="Une documento, plantilla, impresora y disparador. Si una regla es automatica, el flujo podra enviar el trabajo sin preguntar.">
                                        <form onSubmit={(event) => submit(event, ruleForm, route('printing.rules.store'))} className="grid gap-4 md:grid-cols-3">
                                            <Select label="Sucursal" value={ruleForm.data.branch_id} onChange={(value) => ruleForm.setData('branch_id', value)} error={ruleForm.errors.branch_id}>
                                                <option value="">Global</option>
                                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                            </Select>
                                            <Select label="Documento" value={ruleForm.data.document_type} onChange={(value) => ruleForm.setData('document_type', value)} error={ruleForm.errors.document_type}>
                                                {options(documentTypes)}
                                            </Select>
                                            <Select label="Area" value={ruleForm.data.area} onChange={(value) => ruleForm.setData('area', value)} error={ruleForm.errors.area}>
                                                {options(areas)}
                                            </Select>
                                            <Select label="Disparador" value={ruleForm.data.trigger} onChange={(value) => ruleForm.setData('trigger', value)} error={ruleForm.errors.trigger}>
                                                {options(triggers)}
                                            </Select>
                                            <Select label="Plantilla" value={ruleForm.data.print_document_template_id} onChange={(value) => ruleForm.setData('print_document_template_id', value)} error={ruleForm.errors.print_document_template_id}>
                                                <option value="">Resolver automaticamente</option>
                                                {templates.data.map((template) => <option key={template.id} value={template.id}>{template.name} - {documentTypes[template.document_type]}</option>)}
                                            </Select>
                                            <Select label="Impresora" value={ruleForm.data.printer_profile_id} onChange={(value) => ruleForm.setData('printer_profile_id', value)} error={ruleForm.errors.printer_profile_id}>
                                                <option value="">Sin impresora fija</option>
                                                {printerProfiles.map((profile) => <option key={profile.id} value={profile.id}>{profile.name} - {areas[profile.area] ?? profile.area}</option>)}
                                            </Select>
                                            <Field label="Copias" type="number" min="1" max="5" value={ruleForm.data.copies} onChange={(value) => ruleForm.setData('copies', value)} error={ruleForm.errors.copies} />
                                            <Toggle label="Automatica" checked={ruleForm.data.auto_print} onChange={(checked) => ruleForm.setData('auto_print', checked)} />
                                            <Submit processing={ruleForm.processing}>Guardar regla</Submit>
                                        </form>
                                    </Panel>
                                </>
                            ) : null}

                            <Panel title="Plantillas configuradas" help="Solo se muestran plantillas globales o de las sucursales permitidas.">
                                <form onSubmit={submitFilters} className="mb-4 grid gap-3 md:grid-cols-4">
                                    <Select label="Documento" value={filterForm.data.document_type} onChange={(value) => filterForm.setData('document_type', value)}>
                                        <option value="">Todos</option>
                                        {options(documentTypes)}
                                    </Select>
                                    <Field label="Por pagina" type="number" min="5" max="100" value={filterForm.data.per_page} onChange={(value) => filterForm.setData('per_page', value)} />
                                    <div className="flex items-end gap-2 md:col-span-2">
                                        <Submit processing={filterForm.processing}>Filtrar</Submit>
                                        <button type="button" onClick={() => router.get(route('printing.index'))} className="h-11 rounded-2xl border border-slate-300 px-4 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Limpiar</button>
                                    </div>
                                </form>
                                <ResponsiveTable
                                    headers={['Nombre', 'Documento', 'Sucursal', 'Papel', 'Estado']}
                                    rows={templates.data.map((template) => [
                                        <strong>{template.name}</strong>,
                                        documentTypes[template.document_type] ?? template.document_type,
                                        template.branch?.name ?? 'Global',
                                        paperTypes[template.paper_type] ?? template.paper_type,
                                        template.is_default ? 'Predeterminada' : (template.is_active ? 'Activa' : 'Inactiva'),
                                    ])}
                                />
                                <div className="mt-4"><Pagination links={templates.links} /></div>
                            </Panel>
                        </div>

                        <aside className="space-y-6">
                            <Panel title="Vista previa segura" help="La vista previa usa datos demo y el mismo renderizador backend que guardara los trabajos de impresion.">
                                {previewHtml ? (
                                    <div className="overflow-auto rounded-2xl bg-slate-100 p-4 dark:bg-slate-950">
                                        <div className="inline-block min-w-full rounded-lg bg-white text-black shadow-sm" dangerouslySetInnerHTML={{ __html: previewHtml }} />
                                    </div>
                                ) : (
                                    <Empty text="Crea una plantilla para ver la previsualizacion." />
                                )}
                            </Panel>

                            {canManageJobs ? (
                                <Panel title="Enviar prueba a cola" help="Crea un trabajo de impresion con datos demo para validar reglas, papel y destino.">
                                    <form onSubmit={(event) => submit(event, jobForm, route('printing.jobs.store'))} className="space-y-4">
                                        <Select label="Sucursal" value={jobForm.data.branch_id} onChange={(value) => jobForm.setData('branch_id', value)} error={jobForm.errors.branch_id}>
                                            <option value="">Global</option>
                                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                        </Select>
                                        <Select label="Documento" value={jobForm.data.document_type} onChange={(value) => jobForm.setData('document_type', value)} error={jobForm.errors.document_type}>
                                            {options(documentTypes)}
                                        </Select>
                                        <Select label="Area" value={jobForm.data.area} onChange={(value) => jobForm.setData('area', value)} error={jobForm.errors.area}>
                                            {options(areas)}
                                        </Select>
                                        <Select label="Plantilla" value={jobForm.data.print_document_template_id} onChange={(value) => jobForm.setData('print_document_template_id', value)} error={jobForm.errors.print_document_template_id}>
                                            <option value="">Predeterminada</option>
                                            {templates.data.map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}
                                        </Select>
                                        <Select label="Impresora" value={jobForm.data.printer_profile_id} onChange={(value) => jobForm.setData('printer_profile_id', value)} error={jobForm.errors.printer_profile_id}>
                                            <option value="">Sin impresora fija</option>
                                            {printerProfiles.map((profile) => <option key={profile.id} value={profile.id}>{profile.name}</option>)}
                                        </Select>
                                        <Field label="Copias" type="number" min="1" max="5" value={jobForm.data.copies} onChange={(value) => jobForm.setData('copies', value)} error={jobForm.errors.copies} />
                                        <Submit processing={jobForm.processing}>Enviar prueba</Submit>
                                    </form>
                                </Panel>
                            ) : null}

                            <Panel title="Reglas activas" help="Resumen de comportamiento por documento y area.">
                                {rules.length === 0 ? <Empty text="Aun no hay reglas." /> : (
                                    <div className="space-y-3">
                                        {rules.map((rule) => (
                                            <div key={rule.id} className="rounded-2xl border border-slate-200 p-3 text-sm dark:border-slate-800">
                                                <p className="font-black text-slate-950 dark:text-white">{documentTypes[rule.document_type] ?? rule.document_type}</p>
                                                <p className="text-slate-500">{areas[rule.area] ?? rule.area} - {triggers[rule.trigger] ?? rule.trigger}</p>
                                                <p className="text-slate-500">{rule.template?.name ?? 'Plantilla automatica'} / {rule.printer_profile?.name ?? 'Impresora automatica'}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Panel>

                            <Panel title="Cola reciente" help="Seguimiento rapido de trabajos generados por reglas o pruebas.">
                                {jobs.length === 0 ? <Empty text="Sin trabajos recientes." /> : (
                                    <div className="space-y-3">
                                        {jobs.map((job) => (
                                            <div key={job.id} className="rounded-2xl border border-slate-200 p-3 text-sm dark:border-slate-800">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="font-black text-slate-950 dark:text-white">{documentTypes[job.document_type] ?? job.document_type}</p>
                                                        <p className="text-slate-500">{job.branch?.name ?? 'Global'} - {job.user?.name ?? '-'}</p>
                                                        <p className="text-xs text-slate-400">{statusLabel(job.status)} - {job.copies} copia(s)</p>
                                                    </div>
                                                    {canManageJobs && job.status !== 'printed' ? (
                                                        <Link href={route('printing.jobs.printed', job.id)} method="patch" as="button" preserveScroll className="rounded-full bg-brand-primary px-3 py-1 text-xs font-bold text-white">Impresa</Link>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Panel>
                        </aside>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function ModuleIntro() {
    return (
        <div className="mb-6">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Documentos e impresion</p>
            <h1 className="text-3xl font-black text-slate-950 dark:text-white">Motor de impresion por negocio</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                Configura documentos, impresoras logicas, areas y reglas para POS, cocina, servicios, reservas, etiquetas y cierres de caja.
            </p>
        </div>
    );
}

function Panel({ title, help, children }) {
    return (
        <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-4">
                <h2 className="text-xl font-bold text-slate-950 dark:text-white">{title}</h2>
                {help ? <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{help}</p> : null}
            </div>
            {children}
        </section>
    );
}

function InfoCard({ title, value, help }) {
    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{title}</p>
            <p className="mt-2 text-3xl font-black text-slate-950 dark:text-white">{value}</p>
            <p className="mt-1 text-xs text-slate-500">{help}</p>
        </div>
    );
}

function Field({ label, value, onChange, error, ...props }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</span>
            <input value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-950 outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white" {...props} />
            {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function Select({ label, value, onChange, error, children }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</span>
            <select value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-950 outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/20 dark:border-slate-800 dark:bg-slate-950 dark:text-white">
                {children}
            </select>
            {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function Toggle({ label, checked, onChange }) {
    return (
        <button type="button" onClick={() => onChange(!checked)} className="flex min-h-11 items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 text-left text-sm font-semibold text-slate-700 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <span>{label}</span>
            <span className={`relative h-6 w-11 rounded-full transition ${checked ? 'bg-brand-primary' : 'bg-slate-300 dark:bg-slate-700'}`}>
                <span className={`absolute top-1 h-4 w-4 rounded-full bg-white transition ${checked ? 'left-6' : 'left-1'}`} />
            </span>
        </button>
    );
}

function FieldPicker({ form, documentType }) {
    const defaultFields = {
        kitchen_order: ['numero', 'mesa', 'items', 'notas', 'hora'],
        service_order: ['numero', 'cliente', 'tecnico', 'diagnostico', 'materiales', 'garantia'],
        reservation_receipt: ['numero', 'cliente', 'recurso', 'inicio', 'fin', 'anticipo', 'garantia'],
        simple_contract: ['cliente', 'condiciones', 'firma_cliente', 'firma_empresa'],
        barcode_label: ['producto', 'sku', 'precio', 'barcode'],
        cash_closing: ['sucursal', 'usuario', 'efectivo', 'qr_banco', 'diferencia'],
    };
    const fields = defaultFields[documentType] ?? ['empresa', 'numero', 'cliente', 'items', 'total', 'metodo_pago'];
    const selected = form.data.fields.length > 0 ? form.data.fields : fields;

    const toggle = (field) => {
        if (selected.includes(field)) {
            form.setData('fields', selected.filter((candidate) => candidate !== field));
            return;
        }

        form.setData('fields', [...selected, field]);
    };

    return (
        <div className="rounded-2xl border border-slate-200 p-3 dark:border-slate-800">
            <p className="mb-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Campos visibles</p>
            <div className="flex flex-wrap gap-2">
                {fields.map((field) => (
                    <button key={field} type="button" onClick={() => toggle(field)} className={`rounded-full border px-3 py-1.5 text-xs font-bold ${selected.includes(field) ? 'border-brand-primary bg-brand-primary text-white' : 'border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300'}`}>
                        {field.replaceAll('_', ' ')}
                    </button>
                ))}
            </div>
        </div>
    );
}

function ResponsiveTable({ headers, rows }) {
    if (rows.length === 0) {
        return <Empty text="No hay datos para mostrar." />;
    }

    return (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
            <table className="min-w-[760px] w-full text-left text-sm">
                <thead className="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-950">
                    <tr>
                        {headers.map((header, index) => <th key={header} className={`${index === 0 ? 'sticky left-0 z-10 bg-slate-100 dark:bg-slate-950' : ''} px-4 py-3`}>{header}</th>)}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                    {rows.map((row, rowIndex) => (
                        <tr key={rowIndex}>
                            {row.map((cell, index) => <td key={index} className={`${index === 0 ? 'sticky left-0 z-10 bg-white dark:bg-slate-900' : ''} px-4 py-3`}>{cell}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Empty({ text }) {
    return <div className="rounded-2xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">{text}</div>;
}

function Submit({ processing, children }) {
    return <button type="submit" disabled={processing} className="h-11 rounded-2xl bg-brand-primary px-4 text-sm font-black text-white shadow-lg shadow-brand-primary/20 disabled:opacity-60">{children}</button>;
}

function submit(event, form, url, resetFields = []) {
    event.preventDefault();
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => resetFields.length > 0 ? form.reset(...resetFields) : undefined,
    });
}

function options(values) {
    return Object.entries(values).map(([value, label]) => <option key={value} value={value}>{label}</option>);
}

function statusLabel(status) {
    return {
        queued: 'En cola',
        printed: 'Impresa',
        failed: 'Fallida',
    }[status] ?? status;
}
