import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ContextHelp from '../../../../../Shared/Resources/Components/ContextHelp';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({
    backups,
    logs = [],
    imports = [],
    licenses = [],
    branches = [],
    productionSettings = {},
    finePermissionSettings = {},
    health = { checks: [] },
    updateStatus = {},
    isSystemSuperadmin = false,
}) {
    const backupForm = useForm({ format: 'json' });
    const productionForm = useForm({
        backup_frequency: productionSettings.backup_frequency ?? 'daily',
        backup_format: productionSettings.backup_format ?? 'sql',
        backup_retention_days: productionSettings.backup_retention_days ?? 14,
        siat_auto_cufd: Boolean(productionSettings.siat_auto_cufd),
        siat_auto_catalog_sync: Boolean(productionSettings.siat_auto_catalog_sync),
        technical_alert_email: productionSettings.technical_alert_email ?? '',
        offline_pos_enabled: Boolean(productionSettings.offline_pos_enabled),
        license_validation_mode: productionSettings.license_validation_mode ?? 'offline',
    });
    const fineForm = useForm({
        cost_visibility_requires_permission: Boolean(finePermissionSettings.cost_visibility_requires_permission ?? true),
        void_sales_requires_permission: Boolean(finePermissionSettings.void_sales_requires_permission ?? true),
        void_payments_requires_permission: Boolean(finePermissionSettings.void_payments_requires_permission ?? true),
        sensitive_exports_requires_permission: Boolean(finePermissionSettings.sensitive_exports_requires_permission ?? true),
        max_discount_percent: finePermissionSettings.max_discount_percent ?? 0,
    });
    const importForm = useForm({
        module: 'products',
        branch_id: branches[0]?.id ?? '',
        file: null,
    });
    const licenseForm = useForm({
        holder_name: '',
        nit: '',
        domain: '',
        license_key: '',
        support_until: '',
        activation_mode: 'offline',
        status: 'active',
        notes: '',
    });
    const setupForm = useForm({
        business_name: '',
        branch_name: 'Sucursal Central',
        branch_code: 'CENTRAL',
        branch_address: '',
        branch_phone: '',
        primary_color: '#0ea5e9',
        secondary_color: '#0f172a',
        admin_name: 'Administrador',
        admin_email: '',
        admin_password: '',
    });
    const updateForm = useForm({ target_version: '' });

    const submitBackup = (event) => {
        event.preventDefault();
        backupForm.post(route('settings.system.backups.store'), { preserveScroll: true });
    };

    const submitProduction = (event) => {
        event.preventDefault();
        productionForm.put(route('settings.system.production.update'), { preserveScroll: true });
    };

    const submitFinePermissions = (event) => {
        event.preventDefault();
        fineForm.put(route('settings.system.fine-permissions.update'), { preserveScroll: true });
    };

    const submitImport = (event) => {
        event.preventDefault();
        importForm.post(route('settings.system.imports.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => importForm.setData('file', null),
        });
    };

    const submitLicense = (event) => {
        event.preventDefault();
        licenseForm.post(route('settings.system.licenses.store'), { preserveScroll: true });
    };

    const submitSetupWizard = (event) => {
        event.preventDefault();
        setupForm.post(route('settings.system.setup-wizard.run'), { preserveScroll: true });
    };

    const submitPrepareUpdate = (event) => {
        event.preventDefault();
        updateForm.post(route('settings.system.updates.prepare'), { preserveScroll: true });
    };

    const restoreBackup = (backupId) => {
        const confirmation = window.prompt('Escribe RESTAURAR para confirmar. Esta accion reemplaza la base de datos con el backup SQL seleccionado.');
        if (confirmation !== 'RESTAURAR') return;
        router.post(route('settings.system.backups.restore', backupId), { confirmation }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Sistema</h2>}>
            <Head title="Sistema" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Centro de produccion"
                    description="Backups, salud, actualizaciones, importaciones, licencia, asistente inicial y operaciones tecnicas para produccion real."
                />

                <div className="mt-6 grid gap-4 lg:grid-cols-4">
                    <StatusCard label="Salud general" value={health.status === 'ok' ? 'Correcta' : 'Revisar'} tone={health.status === 'ok' ? 'green' : 'amber'} />
                    <StatusCard label="Version" value={updateStatus.version ?? 'No definida'} tone="blue" />
                    <StatusCard label="Migraciones pendientes" value={updateStatus.pending_migrations?.length ?? 0} tone={(updateStatus.pending_migrations?.length ?? 0) > 0 ? 'amber' : 'green'} />
                    <StatusCard label="Backup previo" value={updateStatus.latest_pre_update_backup?.path ? 'Disponible' : 'No generado'} tone={updateStatus.latest_pre_update_backup?.path ? 'green' : 'slate'} />
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_0.95fr]">
                    <Panel title="Configuracion productiva" help="Define automatizacion real. En hosting debe existir cron ejecutando php artisan schedule:run y, si usas colas, un queue worker activo.">
                        <form onSubmit={submitProduction} className="grid gap-4 md:grid-cols-2">
                            <SelectField label="Backups automaticos" name="backup_frequency" value={productionForm.data.backup_frequency} onChange={(event) => productionForm.setData('backup_frequency', event.target.value)} error={productionForm.errors.backup_frequency}>
                                <option value="daily">Diario</option>
                                <option value="weekly">Semanal</option>
                                <option value="disabled">Desactivado</option>
                            </SelectField>
                            <SelectField label="Formato automatico" name="backup_format" value={productionForm.data.backup_format} onChange={(event) => productionForm.setData('backup_format', event.target.value)} error={productionForm.errors.backup_format}>
                                <option value="sql">SQL dump completo</option>
                                <option value="json">JSON operativo</option>
                            </SelectField>
                            <FormField label="Retencion dias" name="backup_retention_days" type="number" min="1" max="365" value={productionForm.data.backup_retention_days} onChange={(event) => productionForm.setData('backup_retention_days', event.target.value)} error={productionForm.errors.backup_retention_days} />
                            <FormField label="Correo alertas tecnicas" name="technical_alert_email" type="email" value={productionForm.data.technical_alert_email} onChange={(event) => productionForm.setData('technical_alert_email', event.target.value)} error={productionForm.errors.technical_alert_email} />
                            <SelectField label="Validacion de licencia" name="license_validation_mode" value={productionForm.data.license_validation_mode} onChange={(event) => productionForm.setData('license_validation_mode', event.target.value)} error={productionForm.errors.license_validation_mode}>
                                <option value="offline">Offline por instalacion</option>
                                <option value="online">Online contra servidor de soporte</option>
                                <option value="hybrid">Hibrida</option>
                            </SelectField>
                            <Toggle label="Renovar CUFD automaticamente" checked={productionForm.data.siat_auto_cufd} onChange={(checked) => productionForm.setData('siat_auto_cufd', checked)} />
                            <Toggle label="Sincronizar catalogos SIAT" checked={productionForm.data.siat_auto_catalog_sync} onChange={(checked) => productionForm.setData('siat_auto_catalog_sync', checked)} />
                            <Toggle label="POS offline parcial" checked={productionForm.data.offline_pos_enabled} onChange={(checked) => productionForm.setData('offline_pos_enabled', checked)} />
                            <div className="flex flex-wrap gap-2 md:col-span-2">
                                <PrimarySubmit processing={productionForm.processing}>Guardar configuracion</PrimarySubmit>
                                <button type="button" onClick={() => router.post(route('settings.system.alerts.test'), {}, { preserveScroll: true })} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                                    Probar alerta
                                </button>
                            </div>
                        </form>
                    </Panel>

                    <Panel title="Chequeo de salud" help="Detecta problemas de BD, cache, colas, storage, backups, licencia, SIAT y hardening antes de que afecten al usuario final.">
                        <div className="space-y-3">
                            {health.checks?.map((check) => (
                                <div key={check.name} className="rounded-md border border-slate-200 p-3 dark:border-slate-800">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold text-slate-900 dark:text-white">{check.name}</p>
                                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{check.message}</p>
                                        </div>
                                        <Badge status={check.status} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <Panel title="Backups y rollback" help="Genera, descarga, verifica y restaura backups SQL. Antes de actualizar se crea un backup SQL de seguridad.">
                        <form onSubmit={submitBackup} className="mb-5 grid gap-3 sm:grid-cols-[1fr_auto]">
                            <SelectField label="Formato" name="format" value={backupForm.data.format} onChange={(event) => backupForm.setData('format', event.target.value)} error={backupForm.errors.format}>
                                <option value="json">JSON operativo</option>
                                <option value="sql">SQL dump completo</option>
                            </SelectField>
                            <div className="flex items-end"><PrimarySubmit processing={backupForm.processing}>Generar backup</PrimarySubmit></div>
                        </form>
                        <ResponsiveTable headers={['Archivo', 'Formato', 'Tamano', 'Estado', 'Acciones']}>
                            {backups.data.length === 0 ? (
                                <EmptyRow colSpan={5} text="Sin backups generados." />
                            ) : backups.data.map((backup) => (
                                <tr key={backup.id}>
                                    <td className="px-4 py-3 font-medium">{backup.path}</td>
                                    <td className="px-4 py-3 uppercase">{backup.metadata?.format ?? extensionFromPath(backup.path)}</td>
                                    <td className="px-4 py-3">{formatBytes(backup.size_bytes)}</td>
                                    <td className="px-4 py-3">{statusLabel(backup.status)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-2">
                                            <a href={route('settings.system.backups.download', backup.id)} className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">Descargar</a>
                                            <button type="button" className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200" onClick={() => router.post(route('settings.system.backups.verify', backup.id), {}, { preserveScroll: true })}>Verificar</button>
                                            {isSystemSuperadmin && (backup.metadata?.format ?? extensionFromPath(backup.path)) === 'sql' ? (
                                                <button type="button" className="rounded-md border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-500/40 dark:text-red-300" onClick={() => restoreBackup(backup.id)}>Restaurar</button>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </ResponsiveTable>
                        <div className="mt-3"><Pagination links={backups.links} /></div>
                    </Panel>

                    <Panel title="Actualizacion segura" help="Prepara actualizaciones con backup previo, listado de migraciones pendientes y ejecucion controlada.">
                        {isSystemSuperadmin ? (
                            <>
                                <form onSubmit={submitPrepareUpdate} className="grid gap-3 sm:grid-cols-[1fr_auto]">
                                    <FormField label="Version destino" name="target_version" value={updateForm.data.target_version} onChange={(event) => updateForm.setData('target_version', event.target.value)} error={updateForm.errors.target_version} />
                                    <div className="flex items-end"><PrimarySubmit processing={updateForm.processing}>Preparar</PrimarySubmit></div>
                                </form>
                                <div className="mt-4 rounded-md bg-slate-50 p-4 text-sm dark:bg-slate-950">
                                    <p className="font-semibold text-slate-900 dark:text-white">Migraciones pendientes</p>
                                    <p className="mt-1 text-slate-600 dark:text-slate-400">{updateStatus.pending_migrations?.length ? updateStatus.pending_migrations.join(', ') : 'No hay migraciones pendientes.'}</p>
                                    <button type="button" onClick={() => router.post(route('settings.system.updates.migrate'), {}, { preserveScroll: true })} className="mt-3 rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white">
                                        Aplicar migraciones
                                    </button>
                                </div>
                            </>
                        ) : (
                            <p className="text-sm text-slate-600 dark:text-slate-400">Solo sistemas puede preparar, migrar o restaurar actualizaciones.</p>
                        )}
                    </Panel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <Panel title="Importacion masiva" help="Carga inicial de datos. Soporta CSV, Excel .xlsx y PDF con tabla de texto legible. PDF escaneado como imagen no se puede interpretar sin OCR externo.">
                        <form onSubmit={submitImport} className="grid gap-4 md:grid-cols-2">
                            <SelectField label="Modulo" name="module" value={importForm.data.module} onChange={(event) => importForm.setData('module', event.target.value)} error={importForm.errors.module}>
                                <option value="products">Productos</option>
                                <option value="customers">Clientes</option>
                                <option value="suppliers">Proveedores</option>
                            </SelectField>
                            <SelectField label="Sucursal para stock inicial" name="branch_id" value={importForm.data.branch_id} onChange={(event) => importForm.setData('branch_id', event.target.value)} error={importForm.errors.branch_id}>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Archivo CSV, Excel o PDF" name="file" type="file" accept=".csv,.txt,.xlsx,.pdf" onChange={(event) => importForm.setData('file', event.target.files?.[0] ?? null)} error={importForm.errors.file} />
                            <div className="flex items-end"><PrimarySubmit processing={importForm.processing}>Importar</PrimarySubmit></div>
                        </form>
                        <div className="mt-4 space-y-2">
                            {imports.length === 0 ? <p className="text-sm text-slate-500">Sin importaciones registradas.</p> : imports.map((batch) => (
                                <div key={batch.id} className="rounded-md border border-slate-200 p-3 text-sm dark:border-slate-800">
                                    <p className="font-semibold text-slate-900 dark:text-white">{moduleLabel(batch.module)} - {batch.file_name}</p>
                                    <p className="text-slate-600 dark:text-slate-400">{batch.created_rows} creados, {batch.updated_rows} actualizados, {batch.failed_rows} errores.</p>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel title="Logs productivos" help="Eventos tecnicos relevantes. El sistema protege claves, tokens y contrasenas antes de guardar el contexto.">
                        <div className="space-y-2">
                            {logs.length === 0 ? <p className="text-sm text-slate-500">Sin logs tecnicos registrados.</p> : logs.map((log) => (
                                <div key={log.id} className="rounded-md border border-slate-200 p-3 text-sm dark:border-slate-800">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold text-slate-900 dark:text-white">{log.event}</p>
                                            <p className="text-slate-600 dark:text-slate-400">{log.message}</p>
                                        </div>
                                        <Badge status={log.level === 'error' ? 'error' : log.level === 'warning' ? 'warning' : 'ok'} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <Panel title="Asistente inicial" help="Configura una instalacion nueva sin consola: negocio, sucursal, branding y superadministrador del cliente con cambio obligatorio de clave.">
                        {isSystemSuperadmin ? (
                            <form onSubmit={submitSetupWizard} className="grid gap-4 md:grid-cols-2">
                                <FormField label="Nombre del negocio" name="business_name" value={setupForm.data.business_name} onChange={(event) => setupForm.setData('business_name', event.target.value)} error={setupForm.errors.business_name} required />
                                <FormField label="Sucursal inicial" name="branch_name" value={setupForm.data.branch_name} onChange={(event) => setupForm.setData('branch_name', event.target.value)} error={setupForm.errors.branch_name} required />
                                <FormField label="Codigo sucursal" name="branch_code" value={setupForm.data.branch_code} onChange={(event) => setupForm.setData('branch_code', event.target.value)} error={setupForm.errors.branch_code} required />
                                <FormField label="Telefono sucursal" name="branch_phone" value={setupForm.data.branch_phone} onChange={(event) => setupForm.setData('branch_phone', event.target.value)} error={setupForm.errors.branch_phone} />
                                <FormField label="Direccion" name="branch_address" value={setupForm.data.branch_address} onChange={(event) => setupForm.setData('branch_address', event.target.value)} error={setupForm.errors.branch_address} />
                                <FormField label="Admin cliente" name="admin_name" value={setupForm.data.admin_name} onChange={(event) => setupForm.setData('admin_name', event.target.value)} error={setupForm.errors.admin_name} required />
                                <FormField label="Correo admin" name="admin_email" type="email" value={setupForm.data.admin_email} onChange={(event) => setupForm.setData('admin_email', event.target.value)} error={setupForm.errors.admin_email} required />
                                <FormField label="Clave temporal" name="admin_password" type="password" value={setupForm.data.admin_password} onChange={(event) => setupForm.setData('admin_password', event.target.value)} error={setupForm.errors.admin_password} required />
                                <div className="md:col-span-2"><PrimarySubmit processing={setupForm.processing}>Aplicar configuracion inicial</PrimarySubmit></div>
                            </form>
                        ) : (
                            <p className="text-sm text-slate-600 dark:text-slate-400">Solo sistemas puede ejecutar el asistente inicial.</p>
                        )}
                    </Panel>

                    <Panel title="Permisos finos" help="Refuerza acciones sensibles: ver costos, anular, exportar datos delicados y aplicar descuentos.">
                        <form onSubmit={submitFinePermissions} className="grid gap-4">
                            <Toggle label="Ver costos requiere permiso explicito" checked={fineForm.data.cost_visibility_requires_permission} onChange={(checked) => fineForm.setData('cost_visibility_requires_permission', checked)} />
                            <Toggle label="Anular ventas requiere permiso explicito" checked={fineForm.data.void_sales_requires_permission} onChange={(checked) => fineForm.setData('void_sales_requires_permission', checked)} />
                            <Toggle label="Anular pagos requiere permiso explicito" checked={fineForm.data.void_payments_requires_permission} onChange={(checked) => fineForm.setData('void_payments_requires_permission', checked)} />
                            <Toggle label="Exportar datos sensibles requiere permiso explicito" checked={fineForm.data.sensitive_exports_requires_permission} onChange={(checked) => fineForm.setData('sensitive_exports_requires_permission', checked)} />
                            <FormField label="Descuento maximo permitido %" name="max_discount_percent" type="number" min="0" max="100" step="0.1" value={fineForm.data.max_discount_percent} onChange={(event) => fineForm.setData('max_discount_percent', event.target.value)} error={fineForm.errors.max_discount_percent} />
                            <PrimarySubmit processing={fineForm.processing}>Guardar permisos finos</PrimarySubmit>
                        </form>
                    </Panel>
                </div>

                {isSystemSuperadmin ? (
                    <Panel className="mt-6" title="Licencia de instalacion" help="Control interno por cliente: dominio, NIT, soporte vigente, modo de activacion y modulos incluidos. No se muestra al cliente normal.">
                        <form onSubmit={submitLicense} className="grid gap-4 md:grid-cols-3">
                            <FormField label="Cliente titular" name="holder_name" value={licenseForm.data.holder_name} onChange={(event) => licenseForm.setData('holder_name', event.target.value)} error={licenseForm.errors.holder_name} required />
                            <FormField label="NIT" name="nit" value={licenseForm.data.nit} onChange={(event) => licenseForm.setData('nit', event.target.value)} error={licenseForm.errors.nit} />
                            <FormField label="Dominio" name="domain" value={licenseForm.data.domain} onChange={(event) => licenseForm.setData('domain', event.target.value)} error={licenseForm.errors.domain} />
                            <FormField label="Clave licencia" name="license_key" value={licenseForm.data.license_key} onChange={(event) => licenseForm.setData('license_key', event.target.value)} error={licenseForm.errors.license_key} />
                            <FormField label="Soporte hasta" name="support_until" type="date" value={licenseForm.data.support_until} onChange={(event) => licenseForm.setData('support_until', event.target.value)} error={licenseForm.errors.support_until} />
                            <SelectField label="Estado" name="status" value={licenseForm.data.status} onChange={(event) => licenseForm.setData('status', event.target.value)} error={licenseForm.errors.status}>
                                <option value="active">Activa</option>
                                <option value="suspended">Suspendida</option>
                                <option value="expired">Vencida</option>
                            </SelectField>
                            <div className="md:col-span-3"><PrimarySubmit processing={licenseForm.processing}>Guardar licencia</PrimarySubmit></div>
                        </form>
                        <div className="mt-4 grid gap-3 md:grid-cols-2">
                            {licenses.map((license) => (
                                <div key={license.id} className="rounded-md border border-slate-200 p-3 text-sm dark:border-slate-800">
                                    <p className="font-semibold text-slate-900 dark:text-white">{license.holder_name}</p>
                                    <p className="text-slate-600 dark:text-slate-400">NIT {license.nit ?? '-'} - {license.domain ?? 'Sin dominio'} - {statusLabel(license.status)}</p>
                                </div>
                            ))}
                        </div>
                    </Panel>
                ) : null}
            </section>
        </AuthenticatedLayout>
    );
}

function Panel({ title, help, children, className = '' }) {
    return (
        <section className={`rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 ${className}`}>
            <div className="mb-4 flex items-center gap-2">
                <h3 className="text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
                {help ? <ContextHelp title={title}>{help}</ContextHelp> : null}
            </div>
            {children}
        </section>
    );
}

function StatusCard({ label, value, tone }) {
    const tones = {
        green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
        amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
        blue: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-200',
        slate: 'bg-slate-50 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };

    return (
        <div className={`rounded-lg border border-slate-200 p-4 shadow-sm dark:border-slate-800 ${tones[tone] ?? tones.slate}`}>
            <p className="text-xs font-bold uppercase tracking-[0.14em] opacity-75">{label}</p>
            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function Toggle({ label, checked, onChange }) {
    return (
        <label className="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">
            <span>{label}</span>
            <input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="h-5 w-5 rounded border-slate-300 text-brand-primary focus:ring-brand-primary" />
        </label>
    );
}

function PrimarySubmit({ children, processing }) {
    return (
        <button type="submit" disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white shadow-sm disabled:opacity-60">
            {processing ? 'Procesando...' : children}
        </button>
    );
}

function ResponsiveTable({ headers, children }) {
    return (
        <div className="overflow-x-auto rounded-md border border-slate-200 dark:border-slate-800">
            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>{headers.map((header) => <th key={header} className="px-4 py-3 font-medium">{header}</th>)}</tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">{children}</tbody>
            </table>
        </div>
    );
}

function EmptyRow({ colSpan, text }) {
    return <tr><td colSpan={colSpan} className="px-4 py-6 text-center text-sm text-slate-500">{text}</td></tr>;
}

function Badge({ status }) {
    const labels = { ok: 'Correcto', warning: 'Atencion', error: 'Error' };
    const tones = {
        ok: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200',
        warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        error: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-200',
    };

    return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tones[status] ?? tones.warning}`}>{labels[status] ?? status}</span>;
}

function formatBytes(value) {
    const bytes = Number(value ?? 0);
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function extensionFromPath(path) {
    return String(path ?? '').split('.').pop() || 'json';
}

function statusLabel(status) {
    const labels = {
        created: 'Creado',
        verified: 'Verificado',
        failed_verification: 'Fallo verificacion',
        restored: 'Restaurado',
        active: 'Activa',
        suspended: 'Suspendida',
        expired: 'Vencida',
    };

    return labels[status] ?? status;
}

function moduleLabel(module) {
    const labels = { products: 'Productos', customers: 'Clientes', suppliers: 'Proveedores' };
    return labels[module] ?? module;
}
