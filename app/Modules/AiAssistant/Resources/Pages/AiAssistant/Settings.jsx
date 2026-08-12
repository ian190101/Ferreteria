import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, useForm } from '@inertiajs/react';

export default function Settings({ channels = [], apiClients = [], providers = {}, scopes = [], modelModes = [] }) {
    const channelForm = useForm({
        provider: 'telegram',
        name: '',
        status: 'inactive',
        branch_id: '',
        webhook_url: '',
        credentials_json: '',
        settings_json: '{}',
        allowed_scopes: ['chat'],
    });
    const clientForm = useForm({
        name: '',
        scopes: ['chat'],
        rate_limit_per_minute: 30,
    });
    const healthForm = useForm({});
    const knowledgeForm = useForm({});

    const toggleScope = (form, value, scope) => {
        const current = form.data[value] ?? [];
        form.setData(value, current.includes(scope) ? current.filter((item) => item !== scope) : [...current, scope]);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Configuracion Chatbot IA</h2>}>
            <Head title="Configuracion Chatbot IA" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Configuracion Chatbot IA"
                    description="Gestiona FastAPI, canales, credenciales cifradas y acceso API externo desde sistemasuperadmin."
                />

                <div className="mb-6 grid gap-4 lg:grid-cols-3">
                    <Info title="Modo por defecto" value={modelModes[0] ?? 'cpu_light'} />
                    <Info title="Canales preparados" value={Object.values(providers).join(', ')} />
                    <form onSubmit={(event) => { event.preventDefault(); healthForm.post(route('ai-assistant.settings.health'), { preserveScroll: true }); }} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">FastAPI</p>
                        <p className="mt-1 text-sm text-slate-500">Prueba la conexion configurada en el perfil activo.</p>
                        <button className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">Probar conexion</button>
                    </form>
                    <form onSubmit={(event) => { event.preventDefault(); knowledgeForm.post(route('ai-assistant.settings.knowledge.index'), { preserveScroll: true }); }} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">RAG / conocimiento</p>
                        <p className="mt-1 text-sm text-slate-500">Indexa productos y servicios permitidos sin incluir datos sensibles.</p>
                        <button className="mt-4 rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary" type="submit">Indexar catalogo</button>
                    </form>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            channelForm.post(route('ai-assistant.settings.channels.store'), { preserveScroll: true });
                        }}
                        className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                    >
                        <h3 className="text-lg font-semibold">Canal y credenciales</h3>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <SelectField label="Proveedor" name="provider" value={channelForm.data.provider} onChange={(event) => channelForm.setData('provider', event.target.value)}>
                                {Object.entries(providers).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                            </SelectField>
                            <SelectField label="Estado" name="status" value={channelForm.data.status} onChange={(event) => channelForm.setData('status', event.target.value)}>
                                <option value="inactive">Inactivo</option>
                                <option value="active">Activo</option>
                            </SelectField>
                            <FormField label="Nombre" name="name" value={channelForm.data.name} onChange={(event) => channelForm.setData('name', event.target.value)} />
                            <FormField label="Webhook URL" name="webhook_url" value={channelForm.data.webhook_url} onChange={(event) => channelForm.setData('webhook_url', event.target.value)} />
                        </div>
                        <label className="mt-4 block text-sm font-medium text-slate-700 dark:text-slate-300">
                            Credenciales JSON
                            <textarea className="mt-1 min-h-24 w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" value={channelForm.data.credentials_json} onChange={(event) => channelForm.setData('credentials_json', event.target.value)} placeholder='{"bot_token":"..."}' />
                        </label>
                        <ScopePicker scopes={scopes} selected={channelForm.data.allowed_scopes} onToggle={(scope) => toggleScope(channelForm, 'allowed_scopes', scope)} />
                        <button className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">Guardar canal</button>
                    </form>

                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            clientForm.post(route('ai-assistant.settings.api-clients.store'), { preserveScroll: true });
                        }}
                        className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"
                    >
                        <h3 className="text-lg font-semibold">API externa</h3>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <FormField label="Sistema externo" name="name" value={clientForm.data.name} onChange={(event) => clientForm.setData('name', event.target.value)} />
                            <FormField label="Limite por minuto" name="rate_limit_per_minute" type="number" value={clientForm.data.rate_limit_per_minute} onChange={(event) => clientForm.setData('rate_limit_per_minute', event.target.value)} />
                        </div>
                        <ScopePicker scopes={scopes} selected={clientForm.data.scopes} onToggle={(scope) => toggleScope(clientForm, 'scopes', scope)} />
                        <button className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">Crear token</button>
                    </form>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-2">
                    <Listing title="Canales configurados" rows={channels} />
                    <Listing title="Clientes API" rows={apiClients} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Info({ title, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-sm font-semibold text-slate-900 dark:text-slate-100">{title}</p>
            <p className="mt-2 text-sm text-slate-500">{value}</p>
        </div>
    );
}

function ScopePicker({ scopes, selected, onToggle }) {
    return (
        <div className="mt-4 flex flex-wrap gap-2">
            {scopes.map((scope) => (
                <label key={scope} className="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700">
                    <input type="checkbox" checked={selected.includes(scope)} onChange={() => onToggle(scope)} />
                    {scope}
                </label>
            ))}
        </div>
    );
}

function Listing({ title, rows }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="text-lg font-semibold">{title}</h3>
            <div className="mt-4 space-y-3">
                {rows.length === 0 ? <p className="text-sm text-slate-500">Sin registros.</p> : rows.map((row) => (
                    <div key={row.id} className="rounded-md border border-slate-100 p-3 text-sm dark:border-slate-800">
                        <div className="font-semibold">{row.name}</div>
                        <div className="text-slate-500">{row.provider ?? 'api'} - {row.status}</div>
                        <div className="text-xs text-slate-400">{Array.isArray(row.scopes) ? row.scopes.join(', ') : (row.allowed_scopes ?? []).join(', ')}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}
