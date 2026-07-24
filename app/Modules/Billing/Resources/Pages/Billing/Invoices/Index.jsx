import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head, Link } from '@inertiajs/react';

export default function Index({ settings = [], invoices, stats = {} }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Facturacion SIAT</h2>}>
            <Head title="Facturacion SIAT" />
            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Facturacion SIAT" description="Control fiscal para CUIS, CUFD, XML, envio, anulación y contingencia." />

                <div className="mb-5 flex flex-wrap gap-3">
                    <Link href={route('billing.settings.index')} className="rounded-full bg-brand-primary px-4 py-2 text-sm font-semibold text-white">Configurar SIAT</Link>
                    <Link href={route('billing.products.index')} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">Homologar productos</Link>
                    <Link href={route('billing.events.index')} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">Eventos y paquetes</Link>
                </div>

                <div className="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                    {[
                        ['Validadas', stats.validated ?? 0],
                        ['Observadas', stats.observed ?? 0],
                        ['Pendientes', stats.pending ?? 0],
                        ['Catalogos', stats.catalogs ?? 0],
                        ['CUIS activos', stats.cuis ?? 0],
                        ['CUFD activos', stats.cufd ?? 0],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{label}</p>
                            <p className="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{value}</p>
                        </div>
                    ))}
                </div>

                <div className="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-slate-200 p-4 dark:border-slate-800">
                        <h3 className="font-semibold text-slate-950 dark:text-white">Facturas fiscales</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950">
                                <tr>
                                    <th className="px-4 py-3">Numero</th>
                                    <th className="px-4 py-3">Sucursal</th>
                                    <th className="px-4 py-3">Venta</th>
                                    <th className="px-4 py-3">CUF</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3 text-right">Total</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <td className="px-4 py-3 font-semibold">{invoice.invoice_number}</td>
                                        <td className="px-4 py-3">{invoice.branch?.name}</td>
                                        <td className="px-4 py-3">{invoice.sale?.receipt_number ?? '-'}</td>
                                        <td className="max-w-xs truncate px-4 py-3">{invoice.cuf ?? '-'}</td>
                                        <td className="px-4 py-3">{statusLabel(invoice.status)}</td>
                                        <td className="px-4 py-3 text-right">Bs {Number(invoice.total_amount).toFixed(2)}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={route('billing.invoices.show', invoice.id)} className="text-brand-primary font-semibold">Ver</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={invoices.links} />
                </div>

                {settings.length === 0 ? (
                    <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        No hay configuracion SIAT activa. Ingresa a Configurar SIAT antes de emitir facturas.
                    </div>
                ) : null}
            </section>
        </AuthenticatedLayout>
    );
}

function statusLabel(status) {
    return {
        draft: 'Borrador',
        pending: 'Pendiente',
        validated: 'Validada',
        observed: 'Observada',
        contingency: 'Contingencia',
        temporary: 'Recibo temporal',
        voided: 'Anulada',
    }[status] ?? status;
}
