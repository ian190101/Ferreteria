import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { confirmAction } from '@/Utils/alerts';
import { showSystemError } from '@/Utils/systemErrors';

const button = 'rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:hover:bg-slate-800';
const panel = 'space-y-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900';
const input = 'w-full rounded-lg border border-slate-300 bg-transparent p-2 text-sm dark:border-slate-600';
const operationNames = { pull: 'Actualizar desde Git', reset: 'Sustituir con la rama remota', previous: 'Volver al commit anterior', revision: 'Volver a esta versión', branch: 'Cambiar de rama' };

export default function Index({ gitStatus = {}, deploymentRuns = [] }) {
    const [status, setStatus] = useState(gitStatus);
    const [runs, setRuns] = useState(deploymentRuns);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [tab, setTab] = useState('files');
    const [selected, setSelected] = useState([]);
    const [message, setMessage] = useState('');
    const [search, setSearch] = useState('');
    const [historyRef, setHistoryRef] = useState('HEAD');
    const [offset, setOffset] = useState(0);
    const [history, setHistory] = useState(gitStatus.history ?? []);
    const [detail, setDetail] = useState(null);
    const [preview, setPreview] = useState(null);
    const [confirmation, setConfirmation] = useState('');
    const closePreview = useRef(null);
    const openPreviewButton = useRef(null);
    const previewDialog = useRef(null);
    const base = route('system-superadmin.git-versioning.index');
    const files = status.working_tree?.all_files ?? status.working_tree?.files ?? [];
    const references = status.references ?? [];
    const hasPending = runs.some((run) => ['pending', 'running'].includes(run.status));
    const blocked = busy || hasPending;

    async function refresh() {
        const { data } = await axios.get(`${base}/status`);
        setStatus(data);
        setRuns(data.deployment_runs ?? []);
        setSelected((current) => current.filter((path) => (data.working_tree?.all_files ?? []).some((file) => file.can_commit && file.path === path)));
        return data;
    }
    async function execute(action) {
        setBusy(true);
        setNotice('');
        try { await action(); } catch (error) { showSystemError(error); } finally { setBusy(false); }
    }
    useEffect(() => {
        if (!hasPending) return undefined;
        let active = true;
        const controller = new AbortController();
        const timer = setInterval(async () => {
            try {
                const { data } = await axios.get(`${base}/status`, { signal: controller.signal });
                if (active) { setStatus(data); setRuns(data.deployment_runs ?? []); }
            } catch { /* El servicio puede estar temporalmente en mantenimiento durante el despliegue. */ }
        }, 5000);
        return () => { active = false; clearInterval(timer); controller.abort(); };
    }, [hasPending, base]);
    useEffect(() => {
        if (preview) closePreview.current?.focus();
        else openPreviewButton.current?.focus();
    }, [preview]);

    async function inspect(ref = historyRef, nextOffset = 0) {
        const { data } = await axios.get(`${base}/inspect`, { params: { ref, offset: nextOffset } });
        setHistoryRef(ref); setOffset(nextOffset); setHistory(data.history); setDetail(data); setTab('history');
    }
    async function plan(operation, ref = '') {
        openPreviewButton.current = document.activeElement;
        await execute(async () => {
            const { data } = await axios.post(`${base}/preview`, { operation, ref });
            setConfirmation(''); setPreview(data);
        });
    }
    async function commit() {
        if (!await confirmAction({ title: 'Crear commit local', text: `Se confirmarán ${selected.length} archivo(s) en la rama ${status.branch}. Mensaje: ${message}`, confirmButtonText: 'Crear commit' })) return;
        await execute(async () => {
            const { data } = await axios.post(`${base}/commit`, { message, files: selected, push: false });
            setMessage(''); setSelected([]); setNotice(data.message); await refresh(); await inspect('HEAD');
        });
    }
    async function push() {
        if (!await confirmAction({ title: 'Publicar en Git', text: `Se enviarán los commits locales de ${status.branch} a origin/${status.branch}. Si GitHub tiene un despliegue automático, podría activarse.`, confirmButtonText: 'Publicar commits' })) return;
        await execute(async () => {
            const { data } = await axios.post(`${base}/push`);
            setNotice(data.message); await refresh();
        });
    }
    function trapDialog(event) {
        if (event.key === 'Escape' && !busy) setPreview(null);
        if (event.key !== 'Tab') return;
        const elements = [...previewDialog.current.querySelectorAll('button:not(:disabled), input:not(:disabled)')];
        const first = elements[0]; const last = elements[elements.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === closePreview.current)) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }

    if (!status.is_available) return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Control de versiones</h2>}>
            <Head title="Control de versiones" />
            <section className="mx-auto max-w-5xl space-y-5 px-4 py-8">
                <ModuleHeader title="Versión instalada" description="Información de esta publicación y su actualización." />
                <div className={panel}><p>Versión: {status.release?.version ?? 'Sin identificar'}</p><p className="break-all font-mono">Commit: {status.release?.commit ?? 'Sin identificar'}</p><p>Construcción: {status.release?.built_at ?? 'Sin identificar'}</p></div>
                <p role="status">{status.error}</p>
                <p>Una imagen Docker se actualiza reconstruyéndola y sustituyendo el contenedor. La gestión de ramas y publicaciones requiere un repositorio Git en el entorno que construye la imagen.</p>
                {status.technical_error && <details><summary>Diagnóstico técnico</summary><pre className="whitespace-pre-wrap break-all">{status.technical_error}</pre></details>}
                {status.pipeline_url ? <a className={button} href={status.pipeline_url} target="_blank" rel="noreferrer">Abrir flujo de despliegue</a> : <p>Falta vincular el flujo de despliegue de esta instalación.</p>}
            </section>
        </AuthenticatedLayout>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Control de versiones</h2>}>
            <Head title="Control de versiones" />
            <section className="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6">
                <ModuleHeader title="Control de versiones" description="Consulta ramas, etiquetas y commits. Publica tus cambios o selecciona la versión que deseas instalar." />
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className={panel}><h3 className="font-semibold">Rama activa</h3><p className="break-all font-mono">{status.branch}</p><p className="text-sm">{status.git_version}</p></div>
                    <div className={panel}><h3 className="font-semibold">Último commit local</h3><p className="font-mono">{status.commit?.short_hash}</p><p>{status.commit?.message}</p><p className="text-sm">{status.commit?.date}</p>{files.length > 0 && <p className="text-sm text-amber-700 dark:text-amber-300">El código actual también contiene cambios sin confirmar.</p>}</div>
                    <div className={panel}><h3 className="font-semibold">Estado frente a origin</h3><p>{status.remote?.ahead ?? 0} commits por publicar · {status.remote?.behind ?? 0} por recibir</p><p className="text-sm">{status.remote?.sync_status === 'untracked' ? 'Sin rama remota comparable' : status.remote?.sync_status === 'diverged' ? 'Historial divergente: requiere resolver los cambios' : 'Según las últimas referencias descargadas'}</p><p>{files.length} archivos modificados</p></div>
                </div>
                <div className={panel}>
                    <div className="flex flex-wrap gap-3">
                        <button className={button} disabled={blocked} onClick={() => execute(async () => { await refresh(); await inspect(historyRef, offset); })}>Revisar estado local</button>
                        <button className={button} disabled={blocked} onClick={() => execute(async () => { const { data } = await axios.post(`${base}/fetch`); await refresh(); setNotice(data.message); })}>Descargar ramas y etiquetas</button>
                        <button className={button} disabled={blocked || status.detached} onClick={push}>Publicar commits en Git</button>
                        <button className={button} disabled={blocked} onClick={() => plan('pull')}>Actualizar desde Git</button>
                        <button className={button} disabled={blocked} onClick={() => plan('reset')}>Sustituir con versión remota</button>
                    </div>
                    <p className="text-sm">Descargar referencias consulta Git sin cambiar el código instalado. Publicar commits puede activar los despliegues automáticos del repositorio.</p>
                    {!status.updates_enabled && <div role="status" className="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"><p className="font-semibold">El despliegue de código requiere configurar esta instalación:</p>{status.detached && <p>HEAD está separado de una rama. Es necesario asociar la instalación a una rama antes de publicar o desplegar.</p>}{!status.deployment_requirements?.enabled && <p>Habilitar MAINTENANCE_GIT_UPDATES_ENABLED después de validar el entorno.</p>}{!status.deployment_requirements?.supervised_restart && <p>Definir DEPLOY_RESTART_COMMAND_JSON con el reinicio supervisado real del servicio.</p>}<p>El trabajador de mantenimiento debe ejecutarse en la conexión {status.deployment_requirements?.queue_connection ?? 'maintenance'}, cola maintenance. Puedes explorar y revisar destinos mientras se configura.</p></div>}
                    <p role="status" aria-live="polite">{busy ? 'Procesando…' : notice}</p>
                </div>
                <nav aria-label="Secciones del control de versiones" className="flex flex-wrap gap-2">
                    {[['files', 'Cambios locales'], ['refs', 'Ramas y versiones'], ['history', 'Historial de commits'], ['runs', 'Despliegues']].map(([id, label]) => <button key={id} className={button} aria-pressed={tab === id} onClick={() => setTab(id)}>{label}</button>)}
                </nav>
                {tab === 'files' && <section className={panel}>
                    <h3 className="text-lg font-semibold">Seleccionar cambios para un commit</h3>
                    <p className="text-sm">Los archivos de configuración privada, respaldos y datos operativos quedan excluidos. Los cambios preparados ajenos a la selección permanecen fuera del commit.</p>
                    <div className="flex flex-wrap gap-2"><button className={button} disabled={blocked} onClick={() => setSelected(files.filter((file) => file.can_commit).slice(0, 500).map((file) => file.path))}>Seleccionar permitidos (máximo 500)</button><button className={button} disabled={blocked} onClick={() => setSelected([])}>Quitar selección</button></div>
                    <div className="max-h-96 space-y-2 overflow-y-auto">{files.length ? files.map((file) => <label key={file.path} className="flex items-start gap-3 rounded border border-slate-200 p-2 text-sm dark:border-slate-700"><input type="checkbox" className="mt-1" disabled={blocked || !file.can_commit} checked={selected.includes(file.path)} onChange={(event) => setSelected((current) => event.target.checked ? [...current, file.path] : current.filter((path) => path !== file.path))} /><span className="min-w-0 break-all"><span className="font-mono">{file.path}</span><span className="block text-xs">{file.status}{!file.can_commit ? ' · Excluido de publicación' : ''}</span></span></label>) : <p>El árbol de trabajo está limpio.</p>}</div>
                    <label className="block space-y-2"><span>Mensaje del commit</span><textarea className={input} maxLength={500} rows={3} value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Describe el cambio y su propósito" /></label>
                    <button className={button} disabled={blocked || status.detached || !selected.length || message.trim().length < 3} onClick={commit}>Crear commit local ({selected.length} archivos)</button>
                </section>}
                {tab === 'refs' && <section className={panel}>
                    <h3 className="text-lg font-semibold">Ramas y etiquetas de versión</h3>
                    <label className="block space-y-2"><span>Buscar rama o versión</span><input className={input} value={search} onChange={(event) => setSearch(event.target.value)} /></label>
                    {references.filter((item) => item.name.toLowerCase().includes(search.toLowerCase())).map((item) => <div key={item.ref} className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 py-3 dark:border-slate-700"><div className="min-w-0"><p className="break-all font-semibold">{item.name}</p><p className="text-sm">{item.type === 'tag' ? 'Etiqueta de versión' : item.type === 'remote' ? 'Rama remota' : 'Rama local'} · {item.hash.slice(0, 10)}</p><p className="break-all text-sm">{item.message}</p></div><div className="flex flex-wrap gap-2"><button className={button} disabled={blocked} onClick={() => execute(() => inspect(item.ref))}>Ver commits</button><button className={button} disabled={blocked} onClick={() => plan(item.type === 'tag' ? 'revision' : 'branch', item.ref)}>{item.type === 'tag' ? 'Revisar instalación' : 'Revisar cambio de rama'}</button></div></div>)}
                    {!references.length && <p>No hay referencias disponibles. Descarga las ramas y etiquetas del remoto.</p>}
                </section>}
                {tab === 'history' && <section className={panel}>
                    <h3 className="text-lg font-semibold">Historial de commits</h3>
                    <form className="flex flex-wrap items-end gap-3" onSubmit={(event) => { event.preventDefault(); execute(() => inspect(historyRef)); }}><label className="min-w-0 flex-1 space-y-2"><span>Rama, etiqueta o hash de commit</span><input className={input} maxLength={250} value={historyRef} onChange={(event) => setHistoryRef(event.target.value)} /></label><button className={button} disabled={blocked}>Consultar historial</button></form>
                    {history.map((item) => <div key={item.hash} className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 py-3 dark:border-slate-700"><div className="min-w-0"><p className="break-all font-semibold">{item.message}</p><p className="text-sm"><span className="font-mono">{item.short_hash}</span> · {item.author} · {item.date}{item.hash === status.commit?.hash ? ' · Instalado' : ''}</p></div><div className="flex gap-2"><button className={button} disabled={blocked} onClick={() => execute(async () => { const { data } = await axios.get(`${base}/inspect`, { params: { ref: item.hash } }); setDetail(data); })}>Detalles</button><button className={button} disabled={blocked} onClick={() => plan('revision', item.hash)}>Volver a esta versión</button></div></div>)}
                    <div className="flex items-center gap-3"><button className={button} disabled={blocked || offset === 0} onClick={() => execute(() => inspect(historyRef, Math.max(0, offset - 30)))}>Más recientes</button><span className="text-sm">Desde el commit {offset + 1}</span><button className={button} disabled={blocked || history.length < 30} onClick={() => execute(() => inspect(historyRef, offset + 30))}>Más antiguos</button></div>
                    {detail && <details open className="space-y-3"><summary className="cursor-pointer font-semibold">Archivos y comparación: {detail.commit?.short_hash}</summary><p className="break-all font-mono text-xs">{detail.commit?.hash}</p><h4 className="font-semibold">Cambios introducidos por ese commit</h4><pre className="overflow-x-auto whitespace-pre-wrap text-xs">{detail.summary || 'Sin cambios en archivos.'}</pre><h4 className="font-semibold">Diferencias respecto al código instalado</h4><pre className="overflow-x-auto whitespace-pre-wrap text-xs">{detail.comparison || 'Coincide con el código instalado.'}</pre></details>}
                </section>}
                {tab === 'runs' && <section className={panel}><h3 className="text-lg font-semibold">Últimos despliegues</h3>{!runs.length && <p>Todavía no hay despliegues registrados.</p>}{runs.map((run) => <article key={run.id} className="space-y-2 border-b border-slate-200 py-3 dark:border-slate-700"><p className="font-semibold">{operationNames[run.operation] ?? run.operation} · {{ pending: 'En cola', running: 'En ejecución', completed: 'Completado', failed: 'Fallido' }[run.status] ?? run.status}</p><p>{run.result?.message ?? 'Esperando el resultado del trabajador de mantenimiento.'}</p><p className="break-all text-xs">Referencia: {run.id} · {run.created_at}</p>{run.result?.error && <pre className="whitespace-pre-wrap break-all text-sm text-red-700 dark:text-red-300">{run.result.error}</pre>}{run.result?.target_commit && <p className="break-all font-mono text-xs">Destino: {run.result.target_commit}</p>}{run.result?.recovery?.path && <p className="break-all text-xs">Recuperación privada: {run.result.recovery.path}</p>}</article>)}</section>}
            </section>
            {preview && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><section ref={previewDialog} role="dialog" aria-modal="true" aria-labelledby="git-preview-title" onKeyDown={trapDialog} className={`${panel} max-h-[90vh] w-full max-w-3xl overflow-y-auto text-slate-900 dark:text-slate-100`}>
                <h2 ref={closePreview} tabIndex={-1} id="git-preview-title" className="text-xl font-semibold outline-none">Vista previa: {operationNames[preview.plan.operation]}</h2>
                <p className="break-all text-sm">Desde {preview.plan.source_branch}: <span className="font-mono">{preview.plan.before_commit}</span></p><p className="break-all text-sm">Destino {preview.plan.target_branch ?? preview.plan.reference}: <span className="font-mono">{preview.plan.target_commit}</span></p><p>{preview.plan.target?.message}</p>
                <pre className="max-h-52 overflow-auto whitespace-pre-wrap text-xs">{preview.plan.changed_files || 'No hay diferencias de código entre los commits.'}</pre>
                <p>{preview.plan.database_policy}</p><p>Se verificará un respaldo del código y de los cambios locales antes de sustituir archivos. Los commits nuevos del remoto posteriores a esta vista previa no se instalarán automáticamente.</p>
                {preview.plan.local_changes > 0 && <p className="font-semibold text-amber-700 dark:text-amber-300">Hay {preview.plan.local_changes} cambios locales. Al sustituir la versión, los cambios de código se guardarán en la recuperación y saldrán del árbol de trabajo.</p>}
                {['revision', 'reset', 'previous'].includes(preview.plan.operation) && <p className="text-sm">Esta operación mueve la rama local al commit elegido. Publicar después puede ser rechazado si el historial remoto es más reciente; no se fuerza la publicación.</p>}
                <label className="block space-y-2"><span>Escribe ACTUALIZAR para confirmar el destino revisado (válido durante 10 minutos)</span><input className={input} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="off" /></label>
                <div className="flex flex-wrap gap-3"><button className={button} disabled={busy} onClick={() => setPreview(null)}>Cancelar</button><button className={button} disabled={blocked || !status.updates_enabled || confirmation !== 'ACTUALIZAR'} onClick={() => execute(async () => { const { data } = await axios.post(`${base}/deploy`, { token: preview.token }); setPreview(null); setTab('runs'); setNotice(data.message); await refresh(); })}>Instalar versión revisada</button></div>
                {!status.updates_enabled && <p role="status">La vista previa está disponible. Falta configurar el despliegue de esta instalación para ejecutar la actualización.</p>}
            </section></div>}
        </AuthenticatedLayout>
    );
}
