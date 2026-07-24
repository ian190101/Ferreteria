import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({ events, branches = [] }) {
    const form = useForm({
        branch_id: '',
        event_code: 1,
        started_at: '',
        ended_at: '',
        description: 'Corte de internet o indisponibilidad de servicio.',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('billing.events.store'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Eventos SIAT</h2>}>
            <Head title="Eventos SIAT" />
            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Eventos significativos y paquetes" description="Registra contingencias y envia paquetes de facturas cuando vuelve la conexion." />
                <form onSubmit={submit} className="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 md:grid-cols-5">
                        <SelectField
                            label="Sucursal"
                            value={form.data.branch_id}
                            onChange={(event) => form.setData('branch_id', event.target.value)}
                            helpTooltip="Selecciona la sucursal donde ocurrio la contingencia. Solo veras las sucursales permitidas para tu usuario."
                            required
                        >
                            <option value="">Seleccione sucursal</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                        </SelectField>
                        <FormField label="Codigo evento" type="number" value={form.data.event_code} onChange={(event) => form.setData('event_code', event.target.value)} required />
                        <FormField label="Inicio" type="datetime-local" value={form.data.started_at} onChange={(event) => form.setData('started_at', event.target.value)} required />
                        <FormField label="Fin" type="datetime-local" value={form.data.ended_at} onChange={(event) => form.setData('ended_at', event.target.value)} required />
                        <div className="flex items-end"><button className="h-12 rounded-xl bg-brand-primary px-4 text-sm font-semibold text-white">Registrar evento</button></div>
                        <div className="md:col-span-5"><FormField label="Descripcion" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} required /></div>
                    </div>
                </form>
                <div className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Codigo</th>
                                    <th className="px-4 py-3">Inicio</th>
                                    <th className="px-4 py-3">Fin</th>
                                    <th className="px-4 py-3">Recepcion</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.data.map((event) => (
                                    <tr key={event.id} className="border-t border-slate-100 dark:border-slate-800">
                                        <td className="px-4 py-3">{event.branch?.name}</td>
                                        <td className="px-4 py-3">{event.event_code}</td>
                                        <td className="px-4 py-3">{event.started_at}</td>
                                        <td className="px-4 py-3">{event.ended_at}</td>
                                        <td className="px-4 py-3">{event.reception_code ?? '-'}</td>
                                        <td className="px-4 py-3">{event.status}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => router.post(route('billing.events.package', event.id), {}, { preserveScroll: true })} className="text-brand-primary font-semibold">Generar paquete</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={events.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}
