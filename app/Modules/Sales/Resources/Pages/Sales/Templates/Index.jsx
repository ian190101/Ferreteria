import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmAction } from '@/Utils/alerts';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, router } from '@inertiajs/react';

export default function Index({ templates }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title="Plantillas de comprobantes" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Plantillas de comprobantes" description="Configura branding, hoja, impresora termica, campos visibles y orden de secciones." />
                    <ActionLink href={route('sales.templates.create')}>Nueva plantilla</ActionLink>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nombre</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Documento</th>
                                <th className="px-4 py-3 font-medium">Hoja</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {templates.data.map((template) => (
                                <tr key={template.id}>
                                    <td className="px-4 py-3">{template.name}</td>
                                    <td className="px-4 py-3">{template.branch?.name ?? 'Global'}</td>
                                    <td className="px-4 py-3">{labelDocument(template.document_type)}</td>
                                    <td className="px-4 py-3">{labelPaper(template.paper_type, template.thermal_width_mm)}</td>
                                    <td className="px-4 py-3">{template.is_active ? 'Activa' : 'Inactiva'}{template.is_default ? ' / Predeterminada' : ''}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                        <IconButton href={route('sales.templates.edit', template.id)} icon="edit" label="Editar" />
                                        <IconButton
                                            icon="power"
                                            label="Desactivar"
                                            tone="danger"
                                            onClick={async () => {
                                                if (await confirmAction({ title: 'Desactivar plantilla', text: 'La plantilla dejara de estar disponible para impresion.', confirmButtonText: 'Desactivar' })) {
                                                    router.delete(route('sales.templates.destroy', template.id), { preserveScroll: true });
                                                }
                                            }}
                                        />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={templates.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function labelDocument(type) {
    return { both: 'Ambos', quotation: 'Cotizacion', sale_note: 'Nota de venta' }[type] ?? type;
}

function labelPaper(type, width) {
    return {
        letter: 'Bond carta',
        half_letter: 'Bond carta media hoja',
        legal: 'Oficio',
        half_legal: 'Oficio media hoja',
        full_page: 'Hoja completa',
        thermal: `Termica ${width}mm`,
    }[type] ?? type;
}
