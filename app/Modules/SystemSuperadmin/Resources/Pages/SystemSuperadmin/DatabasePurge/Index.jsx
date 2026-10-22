import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { dangerConfirmAction } from '@/Utils/alerts';
import { showSystemError } from '@/Utils/systemErrors';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

export default function Index({ stats, protectedTables = [], recentRuns = [] }) {
    const [options, setOptions] = useState({ scope: 'selected', tables: [], only_soft_deleted: false, include_dependencies: false, create_backup: true, reset_sequences: false, reset_auto_increment: false });
    const [preview, setPreview] = useState(null);
    const [processing, setProcessing] = useState(false);
    const [runs, setRuns] = useState(recentRuns);
    useEffect(() => setRuns(recentRuns), [recentRuns]);
    const hasPendingRun = runs.some((run) => ['pending', 'running', 'committed'].includes(run.status));
    useEffect(() => {
        if (!hasPendingRun) return undefined;
        const controller = new AbortController();
        const timer = setInterval(async () => {
            try {
                const { data } = await axios.get(route('system-superadmin.database-purge.runs'), { signal: controller.signal });
                setRuns(data.runs);
                if (!data.runs.some((run) => ['pending', 'running', 'committed'].includes(run.status))) {
                    router.reload({ only: ['stats', 'recentRuns'], preserveScroll: true });
                }
            } catch { /* La siguiente consulta recupera el estado tras un fallo de red. */ }
        }, 2500);
        return () => { clearInterval(timer); controller.abort(); };
    }, [hasPendingRun]);
    const modules = Object.values(stats.modules ?? {});
    const change = (next) => {
        setOptions((previous) => {
            const updated = { ...previous, ...next };
            if (updated.scope !== 'full_reset') updated.reset_auto_increment = false;
            if (updated.only_soft_deleted) { updated.reset_sequences = false; updated.reset_auto_increment = false; }
            return updated;
        });
        setPreview(null);
    };
    const toggle = (tables, enabled) => change({ tables: enabled ? [...new Set([...options.tables, ...tables])] : options.tables.filter((table) => !tables.includes(table)) });

    const prepare = async () => {
        setProcessing(true);
        try {
            const params = Object.fromEntries(Object.entries(options).map(([key, value]) => [key, typeof value === 'boolean' ? Number(value) : value]));
            const { data } = await axios.get(route('system-superadmin.database-purge.preview'), { params });
            setPreview(data);
        } catch (error) {
            await showSystemError(error);
        } finally {
            setProcessing(false);
        }
    };

    const execute = async () => {
        if (!preview?.can_execute || processing) return;
        const confirmation = options.scope === 'full_reset' ? 'RESET TOTAL' : options.scope === 'all_operational' ? 'BORRAR OPERATIVO' : 'BORRAR';
        const confirmed = await dangerConfirmAction({ title: 'Confirmar la purga revisada', text: `Se eliminarán ${Object.values(preview.counts).reduce((sum, count) => sum + count, 0)} registros. El servidor comprobará nuevamente el alcance antes de borrar.`, requiredText: confirmation });
        if (!confirmed) return;
        router.post(route('system-superadmin.database-purge.execute'), { ...options, preview_token: preview.preview_token, confirmation }, { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => { setProcessing(false); setPreview(null); } });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Sistema Maestro</h2>}>
            <Head title="Purga validada de datos" />
            <section className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
                <ModuleHeader title="Purga y limpieza de datos" description="Selecciona el alcance y revisa sus dependencias antes de confirmar la eliminación definitiva." />
                {processing ? <p role="status" className="rounded border border-blue-300 bg-blue-50 p-3 text-sm text-blue-900 dark:bg-slate-900 dark:text-blue-200">Procesando la operación. Espera el resultado antes de volver a confirmar.</p> : null}
                {runs.length ? <section aria-label="Ejecuciones de mantenimiento" aria-live="polite" className="space-y-2 rounded-lg border border-slate-300 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"><h3 className="font-semibold">Ejecuciones recientes</h3>{runs.map((run) => <div key={run.id} className="border-b border-slate-200 py-2 text-sm dark:border-slate-700"><p>{run.status === 'completed' ? 'Terminada' : run.status === 'failed' ? 'Fallida' : run.status === 'pending' ? 'En cola' : 'En ejecución'}: {stageLabel(run.stage)}</p><p>{run.message ?? 'La operación todavía no ha terminado.'}</p>{run.result?.backup_id ? <p>Respaldo asociado: #{run.result.backup_id}</p> : null}</div>)}</section> : null}
                <fieldset disabled={processing} className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <legend className="px-2 font-semibold">Alcance de la operación</legend>
                    <label className="block">Alcance
                        <select value={options.scope} onChange={(event) => change({ scope: event.target.value })} className="mt-1 block w-full rounded border-slate-300 dark:bg-slate-800">
                            <option value="selected">Tablas o módulos seleccionados</option>
                            <option value="all_operational">Toda la operación</option>
                            <option value="full_reset">Reinicio completo de datos permitidos</option>
                        </select>
                    </label>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={options.only_soft_deleted} onChange={(event) => change({ only_soft_deleted: event.target.checked })} />Eliminar únicamente registros de la papelera</label>
                    <label className="flex items-center gap-2"><input type="checkbox" aria-describedby="purge-dependencies-help" checked={options.include_dependencies} onChange={(event) => change({ include_dependencies: event.target.checked })} />Eliminar también las tablas dependientes por llaves foráneas</label>
                    <p id="purge-dependencies-help" className="text-sm text-slate-600 dark:text-slate-300">Incluye automáticamente todos los niveles de dependencias permitidas y las referencias polimórficas. Se vacían las tablas añadidas, incluso sus registros no relacionados con la selección inicial. Si seleccionas solo papelera, se eliminan únicamente registros de la papelera y los hijos activos siguen bloqueando el borrado.</p>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={options.create_backup} onChange={(event) => change({ create_backup: event.target.checked })} />Crear y verificar un respaldo previo (obligatorio en producción)</label>
                    <label className="flex items-center gap-2"><input type="checkbox" disabled={options.only_soft_deleted} checked={options.reset_sequences && !options.only_soft_deleted} onChange={(event) => change({ reset_sequences: event.target.checked })} />Reiniciar solo los correlativos de documentos incluidos en el alcance</label>
                    <label className="flex items-center gap-2"><input type="checkbox" disabled={options.only_soft_deleted || options.scope !== 'full_reset'} checked={options.reset_auto_increment && !options.only_soft_deleted && options.scope === 'full_reset'} onChange={(event) => change({ reset_auto_increment: event.target.checked })} />Reiniciar identificadores después del borrado (solo reinicio completo sin historia protegida)</label>
                    <p className="text-sm text-slate-600 dark:text-slate-300">La purga conserva las restricciones de integridad, la auditoría y los respaldos. La papelera nunca modifica la numeración.</p>
                    {options.scope === 'selected' ? modules.map((module) => (
                        <details key={module.id} className="rounded border border-slate-200 p-3 dark:border-slate-700">
                            <summary className="cursor-pointer font-medium">{module.name}</summary>
                            <label className="mt-3 flex items-center gap-2"><input type="checkbox" checked={module.tables.length > 0 && module.tables.every((table) => options.tables.includes(table.table))} onChange={(event) => toggle(module.tables.map((table) => table.table), event.target.checked)} />Seleccionar módulo</label>
                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                {module.tables.map((table) => <label key={table.table} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={options.tables.includes(table.table)} onChange={(event) => toggle([table.table], event.target.checked)} /><span className="break-all">{table.table} ({table.row_count}; papelera: {table.soft_deleted_count})</span></label>)}
                            </div>
                        </details>
                    )) : null}
                    <button type="button" onClick={prepare} className="rounded bg-blue-700 px-4 py-2 font-semibold text-white disabled:opacity-50">{processing ? 'Procesando…' : 'Calcular vista previa'}</button>
                    <button type="button" className="ml-3 rounded border border-slate-400 px-4 py-2 disabled:opacity-50" onClick={() => router.post(route('system-superadmin.database-purge.backup'), {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })}>Crear respaldo SQL</button>
                </fieldset>

                {preview ? <section aria-label="Vista previa de la purga" aria-live="polite" className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <h3 className="text-lg font-semibold">Vista previa validada por el servidor</h3>
                    {preview.added_dependency_tables?.length ? <p role="status" className="rounded border border-amber-500 p-3 text-sm">Tablas añadidas automáticamente por dependencias: {preview.added_dependency_tables.join(', ')}. Revisa sus cantidades antes de confirmar.</p> : null}
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th className="p-2">Tabla</th><th className="p-2">Se eliminarán</th><th className="p-2">Se conservarán</th></tr></thead><tbody>{preview.tables.map((table) => <tr key={table}><td className="p-2">{table}</td><td className="p-2">{preview.counts[table]}</td><td className="p-2">{preview.preserved[table]}</td></tr>)}</tbody></table></div>
                    <p>Respaldo: {preview.backup.status}. {preview.backup.required ? 'Requerido antes de borrar.' : 'No solicitado.'}</p>
                    <p>Correlativos: {preview.sequences.length ? preview.sequences.length : 'ninguno se reiniciará'}.</p>
                    {preview.sequences.length ? <ul className="list-inside list-disc text-sm">{preview.sequences.map((sequence) => <li key={sequence.id}>Sucursal {sequence.branch_id}, {sequence.document_type}: {sequence.before} → {sequence.after} ({sequence.prefix})</li>)}</ul> : null}
                    {preview.auto_increment_tables?.length ? <p className="text-sm">Identificadores que se reiniciarán después de confirmar el borrado: {preview.auto_increment_tables.join(', ')}.</p> : null}
                    <p className="text-sm">Se conservan: {protectedTables.join(', ')}.</p>
                    {preview.dependencies.length ? <details><summary>Dependencias relacionadas</summary><ul className="list-inside list-disc text-sm">{preview.dependencies.map((dependency, index) => <li key={index}>{dependency.table} → {dependency.parent}: {dependency.references} referencias; {dependency.retained} fuera del alcance.</li>)}</ul></details> : null}
                    {preview.nullable_breaks.length ? <p className="text-sm">Los enlaces anulables del ciclo se retirarán únicamente de registros incluidos en el borrado, dentro de su transacción.</p> : null}
                    {preview.blockers.length ? <div role="alert" className="rounded border border-rose-400 p-3"><h4 className="font-semibold">Bloqueos que impiden continuar</h4><ul className="list-inside list-disc">{preview.blockers.map((blocker) => <li key={blocker}>{blocker}</li>)}</ul>{!options.include_dependencies && preview.dependencies.some((dependency) => dependency.retained > 0) ? <button type="button" disabled={processing} className="mt-3 rounded border px-3 py-2" onClick={() => change({ include_dependencies: true })}>Activar eliminación de dependencias (vuelve a calcular la vista previa)</button> : null}</div> : null}
                    <button type="button" disabled={!preview.can_execute || processing} onClick={execute} className="rounded bg-rose-700 px-4 py-2 font-semibold text-white disabled:opacity-50">{processing ? 'Procesando…' : 'Confirmar purga del alcance revisado'}</button>
                </section> : null}
            </section>
        </AuthenticatedLayout>
    );
}

function stageLabel(stage) {
    return ({ queued: 'Pendiente de inicio', validating: 'Validando alcance', backing_up: 'Creando respaldo', verifying_recovery: 'Ensayando restauración', deleting: 'Eliminando dentro de la transacción', committed: 'Borrado confirmado; terminando etapas posteriores', finished: 'Proceso finalizado', failed: 'Sin completar', post_commit_incomplete: 'Revisión de etapas posteriores pendiente' })[stage] ?? stage;
}
