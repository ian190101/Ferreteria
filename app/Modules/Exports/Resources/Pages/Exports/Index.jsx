import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Index({ catalog, branches, defaults, csrfToken }) {
    const moduleKeys = Object.keys(catalog);
    const [format, setFormat] = useState('xlsx');
    const [branchId, setBranchId] = useState('');
    const [from, setFrom] = useState(defaults.from);
    const [to, setTo] = useState(defaults.to);
    const [modules, setModules] = useState(['inventory']);
    const [fields, setFields] = useState(() => defaultFields(catalog));
    const csrf = csrfToken ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const selectedModules = useMemo(() => moduleKeys.filter((key) => modules.includes(key)), [moduleKeys, modules]);

    const toggleModule = (module) => {
        setModules((current) => current.includes(module)
            ? current.filter((item) => item !== module)
            : [...current, module]);
    };

    const toggleField = (module, field) => {
        setFields((current) => ({
            ...current,
            [module]: current[module]?.includes(field)
                ? current[module].filter((item) => item !== field)
                : [...(current[module] ?? []), field],
        }));
    };

    const selectAllFields = (module) => {
        setFields((current) => ({ ...current, [module]: Object.keys(catalog[module].fields) }));
    };

    const clearFields = (module) => {
        setFields((current) => ({ ...current, [module]: [] }));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Exportaciones</h2>}>
            <Head title="Exportaciones" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader
                    title="Exportaciones"
                    description="Genera archivos Excel o PDF seleccionando modulos, campos, sucursal y rango de fechas."
                />

                <form method="post" action={route('exports.download')} className="mt-6 grid gap-6 xl:grid-cols-[360px_1fr]">
                    <input type="hidden" name="_token" value={csrf} />
                    <input type="hidden" name="format" value={format} />
                    <input type="hidden" name="branch_id" value={branchId} />
                    <input type="hidden" name="from" value={from} />
                    <input type="hidden" name="to" value={to} />
                    {selectedModules.map((module) => <input key={module} type="hidden" name="modules[]" value={module} />)}
                    {selectedModules.flatMap((module) => (fields[module] ?? []).map((field) => (
                        <input key={`${module}-${field}`} type="hidden" name={`fields[${module}][]`} value={field} />
                    )))}

                    <aside className="space-y-5">
                        <Panel title="Formato y filtros">
                            <div className="grid gap-4">
                                <SelectField label="Formato" name="format_selector" value={format} onChange={(event) => setFormat(event.target.value)}>
                                    <option value="xlsx">Excel (.xlsx)</option>
                                    <option value="pdf">PDF (.pdf)</option>
                                </SelectField>
                                <SelectField label="Sucursal" name="branch_selector" value={branchId} onChange={(event) => setBranchId(event.target.value)}>
                                    <option value="">Todas las permitidas</option>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <FormField label="Desde" name="from_selector" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
                                <FormField label="Hasta" name="to_selector" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
                            </div>
                        </Panel>

                        <Panel title="Modulos">
                            <div className="space-y-2">
                                {moduleKeys.map((module) => (
                                    <label key={module} className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm transition hover:border-brand-primary dark:border-slate-800">
                                        <input type="checkbox" checked={modules.includes(module)} onChange={() => toggleModule(module)} className="mt-1" />
                                        <span>
                                            <span className="block font-semibold text-slate-900 dark:text-white">{catalog[module].label}</span>
                                            <span className="block text-xs text-slate-500 dark:text-slate-400">{catalog[module].description}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </Panel>

                        <button
                            type="submit"
                            disabled={selectedModules.length === 0}
                            className="w-full rounded-lg bg-brand-primary px-4 py-3 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Descargar exportacion
                        </button>
                    </aside>

                    <div className="space-y-5">
                        {selectedModules.length === 0 ? (
                            <Panel title="Sin modulos seleccionados">
                                <p className="text-sm text-slate-500 dark:text-slate-400">Selecciona al menos un modulo para configurar sus campos.</p>
                            </Panel>
                        ) : selectedModules.map((module) => (
                            <Panel key={module} title={catalog[module].label}>
                                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-sm text-slate-500 dark:text-slate-400">{catalog[module].description}</p>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => selectAllFields(module)} className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold dark:border-slate-700">
                                            Seleccionar todo
                                        </button>
                                        <button type="button" onClick={() => clearFields(module)} className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold dark:border-slate-700">
                                            Limpiar
                                        </button>
                                    </div>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {Object.entries(catalog[module].fields).map(([field, label]) => (
                                        <label key={field} className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                                            <input type="checkbox" checked={(fields[module] ?? []).includes(field)} onChange={() => toggleField(module, field)} />
                                            <span>{label}</span>
                                        </label>
                                    ))}
                                </div>
                            </Panel>
                        ))}
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function defaultFields(catalog) {
    return Object.fromEntries(Object.entries(catalog).map(([module, definition]) => [module, Object.keys(definition.fields)]));
}

function Panel({ title, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {children}
        </section>
    );
}
