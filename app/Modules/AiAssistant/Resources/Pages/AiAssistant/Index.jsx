import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({ conversations = [], pendingActions = [], voiceEnabled = false, limits = {} }) {
    const form = useForm({
        conversation_id: conversations[0]?.id ?? '',
        message: '',
        audio: null,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('ai-assistant.send'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, message: '', audio: null }),
        });
    };

    const activeConversation = conversations.find((conversation) => Number(conversation.id) === Number(form.data.conversation_id)) ?? conversations[0];
    const messages = [...(activeConversation?.messages ?? [])].reverse();

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Chatbot IA</h2>}>
            <Head title="Chatbot IA" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Chatbot IA"
                    description="Consulta datos, prepara pedidos y genera respuestas usando herramientas seguras segun tus permisos."
                />

                <div className="grid gap-6 xl:grid-cols-[320px_1fr]">
                    <aside className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Conversaciones</h3>
                        <div className="mt-4 space-y-2">
                            {conversations.length === 0 ? (
                                <p className="text-sm text-slate-500">Aun no hay conversaciones.</p>
                            ) : conversations.map((conversation) => (
                                <button
                                    key={conversation.id}
                                    type="button"
                                    onClick={() => form.setData('conversation_id', conversation.id)}
                                    className={[
                                        'w-full rounded-md border px-3 py-2 text-left text-sm transition',
                                        Number(form.data.conversation_id) === Number(conversation.id)
                                            ? 'border-brand-primary bg-brand-primary/10 text-brand-primary'
                                            : 'border-slate-200 text-slate-600 hover:border-brand-primary dark:border-slate-800 dark:text-slate-300',
                                    ].join(' ')}
                                >
                                    <span className="block font-semibold">{conversation.last_intent ?? 'Nueva consulta'}</span>
                                    <span className="text-xs text-slate-500">{conversation.last_message_at ?? 'Sin mensajes'}</span>
                                </button>
                            ))}
                        </div>
                    </aside>

                    <div className="rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="min-h-[420px] space-y-3 border-b border-slate-200 p-5 dark:border-slate-800">
                            {pendingActions.length > 0 && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100">
                                    <p className="font-semibold">Acciones pendientes de confirmacion</p>
                                    <div className="mt-3 space-y-2">
                                        {pendingActions.map((action) => (
                                            <div key={action.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-white/70 p-2 dark:bg-slate-900/60">
                                                <span>{action.tool} - {action.status}</span>
                                                <div className="flex gap-2">
                                                    {action.status === 'pending_confirmation' && (
                                                        <button type="button" onClick={() => router.post(route('ai-assistant.actions.confirm', action.id))} className="rounded-md bg-brand-primary px-3 py-1 text-xs font-semibold text-white">Confirmar</button>
                                                    )}
                                                    <button type="button" onClick={() => router.post(route('ai-assistant.actions.cancel', action.id))} className="rounded-md border border-amber-300 px-3 py-1 text-xs font-semibold text-amber-900 dark:text-amber-100">Cancelar</button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {messages.length === 0 ? (
                                <div className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500 dark:border-slate-700">
                                    Escribe una pregunta como: cuanto vendi este mes, que stock tengo o busca producto calamina.
                                </div>
                            ) : messages.map((message) => (
                                <div key={message.id} className={message.role === 'assistant' ? 'pr-10' : 'pl-10'}>
                                    <div className={[
                                        'rounded-lg px-4 py-3 text-sm',
                                        message.role === 'assistant'
                                            ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
                                            : 'bg-brand-primary text-white',
                                    ].join(' ')}>
                                        <div className="mb-1 text-xs font-semibold uppercase tracking-[0.16em] opacity-70">
                                            {message.role === 'assistant' ? 'Bot' : 'Usuario'}
                                        </div>
                                        <p className="whitespace-pre-wrap">{message.content}</p>
                                        {message.metadata?.tool_result?.path && (
                                            <a
                                                href={route('ai-assistant.files.download', message.id)}
                                                className="mt-3 inline-flex rounded-md border border-current px-3 py-1 text-xs font-semibold"
                                            >
                                                Descargar archivo generado
                                            </a>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <form onSubmit={submit} className="space-y-4 p-5">
                            <textarea
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                maxLength={limits.maxMessageLength ?? 2000}
                                className="min-h-28 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                placeholder="Pregunta sobre ventas, ganancias, stock, productos o pedidos..."
                            />
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                {voiceEnabled ? (
                                    <label className="text-sm text-slate-600 dark:text-slate-300">
                                        Audio
                                        <input
                                            type="file"
                                            accept="audio/*"
                                            onChange={(event) => form.setData('audio', event.target.files?.[0] ?? null)}
                                            className="ml-3 text-sm"
                                        />
                                    </label>
                                ) : <span className="text-sm text-slate-500">Audio desactivado por perfil.</span>}
                                <button disabled={form.processing} type="submit" className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                    Enviar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
