import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, useForm } from '@inertiajs/react';

export default function Index({ settings, backups }) {
    const decimalSettingIndex = settings.findIndex((setting) => setting.key === 'decimal_precision');
    const form = useForm({
        settings: settings.map((setting) => ({
            key: setting.key,
            value: setting.key === 'decimal_precision' ? decimalDefaults(setting.value) : (setting.value?.value ?? ''),
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

    const updateDecimal = (path, value) => {
        if (decimalSettingIndex < 0) {
            return;
        }

        const current = structuredClone(form.data.settings[decimalSettingIndex]?.value ?? decimalDefaults());
        setNested(current, path, Number(value));
        updateSetting(decimalSettingIndex, current);
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
                            {settings.map((setting, index) => setting.key === 'decimal_precision' ? (
                                <DecimalPrecisionEditor
                                    key={setting.id}
                                    value={form.data.settings[index]?.value ?? decimalDefaults()}
                                    errors={form.errors}
                                    onChange={updateDecimal}
                                />
                            ) : (
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

function DecimalPrecisionEditor({ value, onChange }) {
    const modules = [
        ['sales', 'Ventas'],
        ['purchases', 'Compras'],
        ['inventory', 'Inventario'],
        ['finance', 'Finanzas'],
    ];
    const fields = [
        ['quantity', 'Cantidades'],
        ['measure', 'Medidas'],
        ['money', 'Dinero/contabilidad'],
        ['weight', 'Peso'],
        ['cost', 'Costos/precios'],
        ['percent', 'Porcentajes'],
        ['exchange_rate', 'Tipo de cambio'],
    ];

    return (
        <div className="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 dark:border-slate-800 dark:bg-slate-950/30">
            <h4 className="text-sm font-semibold text-slate-950 dark:text-white">Decimales del sistema</h4>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Controla cuantos decimales se muestran por defecto y por modulo.</p>

            <div className="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {fields.map(([key, label]) => (
                    <DecimalInput
                        key={key}
                        label={label}
                        value={value[key] ?? 0}
                        onChange={(nextValue) => onChange([key], nextValue)}
                    />
                ))}
            </div>

            <div className="mt-5 space-y-4">
                {modules.map(([module, label]) => (
                    <div key={module} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{label}</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            {Object.entries(value.modules?.[module] ?? {}).map(([key, decimals]) => (
                                <DecimalInput
                                    key={`${module}-${key}`}
                                    label={decimalLabel(key)}
                                    value={decimals}
                                    onChange={(nextValue) => onChange(['modules', module, key], nextValue)}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DecimalInput({ label, value, onChange }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</span>
            <input
                type="number"
                min="0"
                max="6"
                step="1"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-2xl border-slate-200 bg-white/80 text-sm shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-100"
            />
        </label>
    );
}

function decimalDefaults(value = {}) {
    return {
        quantity: Number(value.quantity ?? 0),
        measure: Number(value.measure ?? 2),
        money: Number(value.money ?? 1),
        percent: Number(value.percent ?? 2),
        exchange_rate: Number(value.exchange_rate ?? 6),
        weight: Number(value.weight ?? 2),
        cost: Number(value.cost ?? 1),
        modules: {
            sales: { quantity: Number(value.modules?.sales?.quantity ?? 0), measure: Number(value.modules?.sales?.measure ?? 2), money: Number(value.modules?.sales?.money ?? 1) },
            purchases: { quantity: Number(value.modules?.purchases?.quantity ?? 0), measure: Number(value.modules?.purchases?.measure ?? 2), money: Number(value.modules?.purchases?.money ?? 1), weight: Number(value.modules?.purchases?.weight ?? 2), cost: Number(value.modules?.purchases?.cost ?? 1) },
            inventory: { quantity: Number(value.modules?.inventory?.quantity ?? 0), measure: Number(value.modules?.inventory?.measure ?? 2), weight: Number(value.modules?.inventory?.weight ?? 2), cost: Number(value.modules?.inventory?.cost ?? 1) },
            finance: { money: Number(value.modules?.finance?.money ?? 1) },
        },
    };
}

function setNested(target, path, value) {
    const normalized = Math.max(0, Math.min(6, Number(value || 0)));
    let cursor = target;

    path.slice(0, -1).forEach((segment) => {
        cursor[segment] ??= {};
        cursor = cursor[segment];
    });

    cursor[path.at(-1)] = normalized;
}

function decimalLabel(key) {
    return {
        quantity: 'Cant.',
        measure: 'Medidas',
        money: 'Dinero',
        weight: 'Peso',
        cost: 'Costo/precio',
        percent: 'Porcentaje',
        exchange_rate: 'Cambio',
    }[key] ?? key;
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
