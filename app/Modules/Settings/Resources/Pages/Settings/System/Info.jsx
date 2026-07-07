import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head } from '@inertiajs/react';

export default function Info({ system, runtime, database, hosting }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Información del sistema</h2>}>
            <Head title="Información del sistema" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Información del sistema"
                    description="Datos clave de version, entorno, infraestructura y componentes activos sin exponer credenciales sensibles."
                />

                <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-brand-primary">Sistema ERP</p>
                            <h3 className="mt-3 text-3xl font-semibold text-slate-950 dark:text-white">{system.name}</h3>
                            <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-400">
                                Versión {system.version}. Sistema desarrollado por <strong className="font-semibold text-slate-900 dark:text-slate-100">{system.developer}</strong> {system.year}.
                            </p>
                            <div className="mt-5 flex flex-wrap gap-2">
                                <Badge label={`Entorno: ${environmentLabel(system.environment)}`} tone={system.environment === 'production' ? 'green' : 'amber'} />
                                <Badge label={`Debug: ${system.debug ? 'Activo' : 'Inactivo'}`} tone={system.debug ? 'red' : 'green'} />
                                <Badge label={`Zona horaria: ${system.timezone}`} tone="blue" />
                            </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-slate-800 dark:bg-slate-950/60">
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">Estado actual</p>
                            <dl className="mt-4 space-y-3 text-sm">
                                <InfoRow label="Fecha y hora servidor" value={system.server_time} />
                                <InfoRow label="URL principal" value={system.url} />
                                <InfoRow label="Soporte" value={system.support_email ?? 'No configurado'} />
                            </dl>
                        </div>
                    </div>
                </div>

                <div className="mt-6 grid gap-6 lg:grid-cols-3">
                    <InfoCard title="Aplicación" items={[
                        ['PHP', runtime.php],
                        ['Laravel', runtime.laravel],
                        ['Idioma', runtime.locale],
                        ['Cache', runtime.cache],
                        ['Sesiones', runtime.session],
                        ['Colas', runtime.queue],
                        ['Archivos', runtime.filesystem],
                    ]} />

                    <InfoCard title="Base de datos" items={[
                        ['Conexión', database.connection],
                        ['Motor', database.driver],
                        ['Base de datos', database.database],
                        ['Servidor', database.server],
                        ['Tablas detectadas', database.tables],
                    ]} />

                    <InfoCard title="Hosting y proxy" items={[
                        ['Proxies confiables', hosting.trusted_proxies],
                        ['Servicio Render', hosting.render_service],
                        ['URL Render', hosting.render_external_url],
                        ['Commit Render', shortCommit(hosting.render_commit)],
                    ]} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function InfoCard({ title, items }) {
    return (
        <article className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            <dl className="mt-4 space-y-3">
                {items.map(([label, value]) => (
                    <InfoRow key={label} label={label} value={value} />
                ))}
            </dl>
        </article>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="min-w-0 border-b border-slate-100 pb-3 last:border-0 last:pb-0 dark:border-slate-800">
            <dt className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-1 break-words text-sm font-medium text-slate-900 dark:text-slate-100">{String(value ?? 'No disponible')}</dd>
        </div>
    );
}

function Badge({ label, tone }) {
    const tones = {
        green: 'bg-emerald-100 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-500/20',
        amber: 'bg-amber-100 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/20',
        red: 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-200 dark:ring-red-500/20',
        blue: 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-200 dark:ring-sky-500/20',
    };

    return (
        <span className={`rounded-full px-3 py-1 text-xs font-semibold ring-1 ${tones[tone] ?? tones.blue}`}>
            {label}
        </span>
    );
}

function environmentLabel(environment) {
    const labels = {
        local: 'Local',
        production: 'Producción',
        staging: 'Pruebas',
        testing: 'Testing',
    };

    return labels[environment] ?? environment;
}

function shortCommit(commit) {
    if (!commit || commit === 'No detectado') {
        return 'No detectado';
    }

    return commit.slice(0, 12);
}
