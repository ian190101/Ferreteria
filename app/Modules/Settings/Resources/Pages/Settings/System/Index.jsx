import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, useForm } from '@inertiajs/react';

export default function Index({ settings, backups }) {
    const form = useForm({
        settings: settings.map((setting) => ({
            key: setting.key,
            value: setting.value?.value ?? '',
        })),
    });
    const backupForm = useForm({
        format: 'json',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('settings.system.update'), { preserveScroll: true });
    };

    const updateSetting = (index, value) => {
        form.setData('settings', form.data.settings.map((setting, itemIndex) => (itemIndex === index ? { ...setting, value } : setting)));
    };

    const generateBackup = (event) => {
        event.preventDefault();
        backupForm.post(route('settings.system.backups.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Sistema</h2>}>
            <Head title="Sistema" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Configuracion general" description="Parametros globales y backups listos para hosting." />

                <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_0.8fr]">
                    <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-white">Parametros</h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {settings.map((setting, index) => (
                                <div key={setting.id}>
                                    <FormField
                                        label={setting.description ?? setting.key}
                                        name={`setting_${setting.key}`}
                                        value={form.data.settings[index]?.value ?? ''}
                                        onChange={(event) => updateSetting(index, event.target.value)}
                                        error={form.errors[`settings.${index}.value`]}
                                    />
                                    <p className="mt-1 text-xs text-slate-500">{setting.group} / {setting.key}</p>
                                </div>
                            ))}
                        </div>
                        <div className="mt-5">
                            <PrimaryButton disabled={form.processing}>Guardar configuracion</PrimaryButton>
                        </div>
                    </form>

                    <div className="space-y-6">
                        <form onSubmit={generateBackup} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <h3 className="mb-4 text-base font-semibold text-slate-900 dark:text-white">Backups</h3>
                            <SelectField
                                label="Formato"
                                name="format"
                                value={backupForm.data.format}
                                onChange={(event) => backupForm.setData('format', event.target.value)}
                                error={backupForm.errors.format}
                            >
                                <option value="json">JSON operativo</option>
                                <option value="sql">SQL dump completo</option>
                            </SelectField>
                            <button type="submit" disabled={backupForm.processing} className="mt-4 rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                Generar backup
                            </button>
                            <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">Se guarda en el disco local configurado por Laravel para descarga/traslado desde hosting.</p>
                        </form>
                    </div>
                </div>

                <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Archivo</th>
                                <th className="px-4 py-3 font-medium">Formato</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                                <th className="px-4 py-3 text-right font-medium">Tamano</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {backups.data.length === 0 ? (
                                <tr>
                                    <td colSpan="5" className="px-4 py-6 text-center text-sm text-slate-500">Sin backups generados.</td>
                                </tr>
                            ) : backups.data.map((backup) => (
                                <tr key={backup.id}>
                                    <td className="px-4 py-3 font-medium">{backup.path}</td>
                                    <td className="px-4 py-3 uppercase">{backup.metadata?.format ?? extensionFromPath(backup.path)}</td>
                                    <td className="px-4 py-3">{backup.user?.name ?? 'Sistema'}</td>
                                    <td className="px-4 py-3 text-right">{formatBytes(backup.size_bytes)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span>{backup.status}</span>
                                            <a href={route('settings.system.backups.download', backup.id)} className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                                                Descargar
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="px-4 py-3">
                        <Pagination links={backups.links} />
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function formatBytes(value) {
    const bytes = Number(value ?? 0);

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return `${(bytes / 1024).toFixed(1)} KB`;
}

function extensionFromPath(path) {
    return String(path ?? '').split('.').pop() || 'json';
}
