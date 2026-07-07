import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Form({ branch }) {
    const isEditing = Boolean(branch);
    const { data, setData, post, put, processing, errors } = useForm({
        name: branch?.name ?? '',
        code: branch?.code ?? '',
        barcode: branch?.barcode ?? '',
        phone: branch?.phone ?? '',
        secondary_phone: branch?.secondary_phone ?? '',
        point_of_sale_name: branch?.point_of_sale_name ?? '',
        address: branch?.address ?? '',
        is_active: branch?.is_active ?? true,
        setting: {
            primary_color: branch?.setting?.primary_color ?? '#2563eb',
            secondary_color: branch?.setting?.secondary_color ?? '#0f172a',
            logo_path: branch?.setting?.logo_path ?? '',
            theme_mode: branch?.setting?.theme_mode ?? 'system',
        },
    });

    const setSetting = (field, value) => {
        setData('setting', { ...data.setting, [field]: value });
    };
    const logoSrc = assetUrl(data.setting.logo_path);

    const submit = (event) => {
        event.preventDefault();

        if (isEditing) {
            put(route('branches.update', branch.id), { preserveScroll: true });
            return;
        }

        post(route('branches.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Sucursales</h2>}>
            <Head title={isEditing ? 'Editar sucursal' : 'Nueva sucursal'} />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={isEditing ? 'Editar sucursal' : 'Nueva sucursal'} description="Configura datos operativos, contacto, punto de venta y branding usado en interfaz y comprobantes." />

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    <div className="space-y-6">
                        <Panel title="Datos de sucursal">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <FormField label="Nombre" name="name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} required />
                                <FormField label="Codigo" name="code" value={data.code} onChange={(event) => setData('code', event.target.value)} error={errors.code} required />
                                <FormField label="Barcode" name="barcode" value={data.barcode} onChange={(event) => setData('barcode', event.target.value)} error={errors.barcode} required />
                                <FormField label="Punto de venta" name="point_of_sale_name" value={data.point_of_sale_name} onChange={(event) => setData('point_of_sale_name', event.target.value)} error={errors.point_of_sale_name} />
                                <FormField label="Telefono principal" name="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} error={errors.phone} />
                                <FormField label="Telefono secundario" name="secondary_phone" value={data.secondary_phone} onChange={(event) => setData('secondary_phone', event.target.value)} error={errors.secondary_phone} />
                                <div className="sm:col-span-2">
                                    <FormField label="Direccion" name="address" value={data.address} onChange={(event) => setData('address', event.target.value)} error={errors.address} />
                                </div>
                                <SelectField label="Estado" name="is_active" value={data.is_active ? '1' : '0'} onChange={(event) => setData('is_active', event.target.value === '1')} error={errors.is_active}>
                                    <option value="1">Activa</option>
                                    <option value="0">Inactiva</option>
                                </SelectField>
                            </div>
                        </Panel>

                        <Panel title="Branding y tema">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <FormField label="Color primario" name="primary_color" type="color" value={data.setting.primary_color} onChange={(event) => setSetting('primary_color', event.target.value)} error={errors['setting.primary_color']} />
                                <FormField label="Color secundario" name="secondary_color" type="color" value={data.setting.secondary_color} onChange={(event) => setSetting('secondary_color', event.target.value)} error={errors['setting.secondary_color']} />
                                <FormField label="Ruta del logo" name="logo_path" value={data.setting.logo_path} onChange={(event) => setSetting('logo_path', event.target.value)} error={errors['setting.logo_path']} />
                                <SelectField label="Tema" name="theme_mode" value={data.setting.theme_mode} onChange={(event) => setSetting('theme_mode', event.target.value)} error={errors['setting.theme_mode']}>
                                    <option value="system">Sistema</option>
                                    <option value="light">Claro</option>
                                    <option value="dark">Oscuro</option>
                                </SelectField>
                            </div>
                        </Panel>

                        <div className="flex items-center gap-3">
                            <PrimaryButton disabled={processing}>{isEditing ? 'Actualizar' : 'Crear'}</PrimaryButton>
                            <Link href={route('branches.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                        </div>
                    </div>

                    <aside className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">Vista previa</h3>
                        <div className="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-800">
                            <div className="px-4 py-3 text-white" style={{ backgroundColor: data.setting.primary_color }}>
                                <div className="flex items-center gap-3">
                                    {logoSrc ? <img src={logoSrc} alt="Logo de sucursal" className="h-10 w-10 rounded bg-white/90 object-contain p-1" /> : null}
                                    <div>
                                        <p className="font-semibold">{data.name || 'Sucursal'}</p>
                                        <p className="text-sm opacity-90">{data.point_of_sale_name || 'Punto de venta'}</p>
                                    </div>
                                </div>
                            </div>
                            <div className="p-4" style={{ color: data.setting.secondary_color }}>
                                <p>{data.address || 'Direccion'}</p>
                                <p>{data.phone || 'Telefono'} {data.secondary_phone ? `- ${data.secondary_phone}` : ''}</p>
                                <p className="mt-3 text-sm">Tema: {data.setting.theme_mode}</p>
                            </div>
                        </div>
                    </aside>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function assetUrl(path) {
    if (!path) {
        return null;
    }

    const value = String(path).trim();

    if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:')) {
        return value;
    }

    return value.startsWith('/') ? value : `/${value}`;
}

function Panel({ title, children }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            {children}
        </section>
    );
}
