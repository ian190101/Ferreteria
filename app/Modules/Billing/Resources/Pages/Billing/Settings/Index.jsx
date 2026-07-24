import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

function settingFormData(setting = {}, branchId = '') {
    return {
        branch_id: setting.branch_id ?? branchId,
        nit: setting.nit ?? '',
        business_name: setting.business_name ?? '',
        municipality: setting.municipality ?? 'Santa Cruz',
        phone: setting.phone ?? '',
        system_code: setting.system_code ?? '',
        environment_code: setting.environment_code ?? 2,
        modality_code: setting.modality_code ?? 2,
        emission_type_code: setting.emission_type_code ?? 1,
        invoice_type_code: setting.invoice_type_code ?? 1,
        document_sector_code: setting.document_sector_code ?? 1,
        economic_activity_code: setting.economic_activity_code ?? '',
        sin_product_code: setting.sin_product_code ?? '',
        siat_branch_code: setting.siat_branch_code ?? 0,
        point_of_sale_code: setting.point_of_sale_code ?? 0,
        token: '',
        clear_token: false,
        certificate_path: setting.certificate_path ?? '',
        certificate_password: '',
        mock_siat: setting.options?.mock_siat ?? true,
        is_active: setting.is_active ?? true,
    };
}

export default function Index({ settings = [], branches = [] }) {
    const first = settings[0] ?? {};
    const form = useForm(settingFormData(first, first.branch_id ?? branches[0]?.id ?? ''));

    const submit = (event) => {
        event.preventDefault();
        form.post(route('billing.settings.store'), { preserveScroll: true });
    };

    const branchId = form.data.branch_id;
    const selectedSetting = settings.find((setting) => Number(setting.branch_id) === Number(branchId));
    const isProduction = Number(form.data.environment_code) === 1;
    const isElectronic = Number(form.data.modality_code) === 1;

    useEffect(() => {
        const current = settings.find((setting) => Number(setting.branch_id) === Number(branchId));
        form.setData(settingFormData(current ?? {}, branchId));
    }, [branchId]);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Configuracion SIAT</h2>}>
            <Head title="Configuracion SIAT" />
            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Configuracion SIAT" description="Configura los datos fiscales de la sucursal para ambiente piloto o produccion." />

                <div className="mb-5 grid gap-4 lg:grid-cols-3">
                    <div className="rounded-2xl border border-sky-100 bg-sky-50 p-4 text-sm leading-relaxed text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100 lg:col-span-2">
                        <p className="font-semibold">Cambio seguro de pruebas a produccion</p>
                        <p className="mt-1">
                            Para pruebas usa ambiente piloto, tu NIT de prueba y el token delegado de pruebas. Para produccion cambia al NIT del cliente, token oficial y datos SIAT de su sucursal. Al guardar cambios fiscales criticos se vencen los CUIS/CUFD activos para que solicites nuevos codigos.
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <p className="font-semibold text-slate-900 dark:text-white">Estado actual</p>
                        <div className="mt-3 space-y-2 text-slate-600 dark:text-slate-300">
                            <p>Ambiente: <span className="font-semibold">{isProduction ? 'Produccion' : 'Piloto / pruebas'}</span></p>
                            <p>Token: <span className="font-semibold">{selectedSetting?.has_token ? 'Configurado' : 'Sin configurar'}</span></p>
                            <p>Certificado: <span className="font-semibold">{selectedSetting?.has_certificate_password ? 'Clave guardada' : 'Sin clave guardada'}</span></p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white">Datos del emisor</h3>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Estos datos salen en la factura y deben coincidir con el NIT configurado en SIAT.</p>
                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                            <SelectField label="Sucursal" value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)} required>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="NIT emisor" value={form.data.nit} onChange={(event) => form.setData('nit', event.target.value)} error={form.errors.nit} required helpTooltip="En piloto puedes usar el NIT de pruebas. En produccion debe ser el NIT real del cliente autorizado para facturar." />
                            <FormField label="Razon social" value={form.data.business_name} onChange={(event) => form.setData('business_name', event.target.value)} error={form.errors.business_name} required />
                            <FormField label="Municipio" value={form.data.municipality} onChange={(event) => form.setData('municipality', event.target.value)} required />
                            <FormField label="Telefono factura" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                            <FormField label="Codigo sistema" value={form.data.system_code} onChange={(event) => form.setData('system_code', event.target.value)} required helpTooltip="Codigo entregado por SIAT para el sistema certificado. Si cambia, se deben solicitar nuevamente CUIS y CUFD." />
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white">Ambiente y operacion SIAT</h3>
                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                            <SelectField label="Ambiente" value={form.data.environment_code} onChange={(event) => form.setData('environment_code', Number(event.target.value))} helpTooltip="Piloto es para pruebas y certificacion. Produccion es para facturas reales del cliente. Cambiar esto vence los codigos diarios activos.">
                                <option value={2}>Piloto / pruebas</option>
                                <option value={1}>Produccion</option>
                            </SelectField>
                            <SelectField label="Modalidad" value={form.data.modality_code} onChange={(event) => form.setData('modality_code', Number(event.target.value))} helpTooltip="Computarizada en linea usa hash. Electronica en linea requiere certificado digital y firma XML.">
                                <option value={2}>Computarizada en linea</option>
                                <option value={1}>Electronica en linea</option>
                            </SelectField>
                            <SelectField label="Tipo de emision" value={form.data.emission_type_code} onChange={(event) => form.setData('emission_type_code', Number(event.target.value))} helpTooltip="Normalmente se usa emision en linea. La emision fuera de linea se usa para contingencias/eventos significativos.">
                                <option value={1}>En linea</option>
                                <option value={2}>Fuera de linea / contingencia</option>
                            </SelectField>
                            <SelectField label="Tipo de factura" value={form.data.invoice_type_code} onChange={(event) => form.setData('invoice_type_code', Number(event.target.value))} helpTooltip="Para venta comun se mantiene Factura con derecho a credito fiscal. Cambialo solo si SIAT o el contador lo indica.">
                                <option value={1}>Factura con derecho a credito fiscal</option>
                            </SelectField>
                            <SelectField label="Sector documento" value={form.data.document_sector_code} onChange={(event) => form.setData('document_sector_code', Number(event.target.value))} helpTooltip="Compra venta es el sector usado para ferreteria, tienda, supermercado y ventas generales.">
                                <option value={1}>Compra venta</option>
                            </SelectField>
                            <FormField label="Codigo sucursal SIAT" type="number" value={form.data.siat_branch_code} onChange={(event) => form.setData('siat_branch_code', event.target.value)} />
                            <FormField label="Codigo punto venta" type="number" value={form.data.point_of_sale_code} onChange={(event) => form.setData('point_of_sale_code', event.target.value)} />
                            <FormField label="Actividad economica base" type="number" value={form.data.economic_activity_code} onChange={(event) => form.setData('economic_activity_code', event.target.value)} />
                            <FormField label="Producto SIN base" type="number" value={form.data.sin_product_code} onChange={(event) => form.setData('sin_product_code', event.target.value)} />
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-base font-semibold text-slate-900 dark:text-white">Credenciales y firma</h3>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">El token y la clave del certificado se guardan cifrados. Por seguridad no se muestran despues de guardar.</p>
                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                            <div className="md:col-span-2">
                                <FormField
                                    label="Token delegado SIAT"
                                    type="password"
                                    value={form.data.token}
                                    onChange={(event) => form.setData('token', event.target.value)}
                                    error={form.errors.token}
                                    placeholder={selectedSetting?.has_token ? 'Token ya configurado. Escribe uno nuevo solo para reemplazarlo.' : 'Pega aqui el token delegado del SIAT.'}
                                    helpTooltip="El token se genera en el portal SIAT. Si dejas este campo vacio, el sistema conserva el token guardado."
                                />
                            </div>
                            <label className="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-700 dark:border-white/10 dark:text-slate-200">
                                <input
                                    type="checkbox"
                                    checked={form.data.clear_token}
                                    disabled={!selectedSetting?.has_token}
                                    onChange={(event) => form.setData('clear_token', event.target.checked)}
                                />
                                Borrar token guardado
                            </label>
                            <FormField label="Ruta certificado .p12" value={form.data.certificate_path} onChange={(event) => form.setData('certificate_path', event.target.value)} error={form.errors.certificate_path} helpTooltip="Solo se usa en modalidad Electronica en linea. Guarda la ruta segura del certificado en el servidor, no en el navegador." />
                            <FormField label="Clave certificado" type="password" value={form.data.certificate_password} onChange={(event) => form.setData('certificate_password', event.target.value)} error={form.errors.certificate_password} placeholder={selectedSetting?.has_certificate_password ? 'Clave ya configurada. Escribe una nueva para reemplazarla.' : ''} disabled={!isElectronic} helpTooltip="La clave se guarda cifrada. En modalidad computarizada no es necesaria." />
                            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">
                                <p className="font-semibold">Despues de cambiar credenciales</p>
                                <p className="mt-1">Guarda, solicita CUIS, solicita CUFD y sincroniza catalogos antes de emitir facturas.</p>
                            </div>
                        </div>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-3">
                        <label className="flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm dark:bg-white/10">
                            <input type="checkbox" checked={form.data.mock_siat} onChange={(event) => form.setData('mock_siat', event.target.checked)} />
                            Respuestas simuladas para piloto local
                        </label>
                        <label className="flex items-center gap-2 rounded-full bg-slate-100 px-4 py-2 text-sm dark:bg-white/10">
                            <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                            Configuracion activa
                        </label>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-3">
                        <button disabled={form.processing} className="rounded-full bg-brand-primary px-5 py-2 text-sm font-semibold text-white">Guardar configuracion</button>
                        <button type="button" onClick={() => router.post(route('billing.codes.cuis'), { branch_id: branchId }, { preserveScroll: true })} className="rounded-full border border-slate-300 px-5 py-2 text-sm font-semibold">Solicitar CUIS</button>
                        <button type="button" onClick={() => router.post(route('billing.codes.cufd'), { branch_id: branchId }, { preserveScroll: true })} className="rounded-full border border-slate-300 px-5 py-2 text-sm font-semibold">Solicitar CUFD</button>
                        <button type="button" onClick={() => router.post(route('billing.catalogs.sync'), { branch_id: branchId }, { preserveScroll: true })} className="rounded-full border border-slate-300 px-5 py-2 text-sm font-semibold">Sincronizar catalogos</button>
                    </div>
                </form>

                <div className="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-slate-200 p-5 dark:border-slate-800">
                        <h3 className="font-semibold text-slate-900 dark:text-white">Configuraciones guardadas</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-[0.18em] text-slate-500 dark:bg-white/5">
                                <tr>
                                    <th className="px-5 py-3">Sucursal</th>
                                    <th className="px-5 py-3">NIT</th>
                                    <th className="px-5 py-3">Ambiente</th>
                                    <th className="px-5 py-3">Modalidad</th>
                                    <th className="px-5 py-3">Token</th>
                                    <th className="px-5 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {settings.map((setting) => (
                                    <tr key={setting.id} className="text-slate-700 dark:text-slate-200">
                                        <td className="px-5 py-4 font-semibold">{setting.branch?.name ?? 'Sin sucursal'}</td>
                                        <td className="px-5 py-4">{setting.nit}</td>
                                        <td className="px-5 py-4">{setting.environment_label}</td>
                                        <td className="px-5 py-4">{setting.modality_label}</td>
                                        <td className="px-5 py-4">
                                            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${setting.has_token ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-200'}`}>
                                                {setting.has_token ? 'Configurado' : 'Sin token'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4">{setting.is_active ? 'Activa' : 'Inactiva'}</td>
                                    </tr>
                                ))}
                                {settings.length === 0 ? (
                                    <tr>
                                        <td className="px-5 py-8 text-center text-slate-500 dark:text-slate-400" colSpan="6">Todavia no hay configuraciones SIAT guardadas.</td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
