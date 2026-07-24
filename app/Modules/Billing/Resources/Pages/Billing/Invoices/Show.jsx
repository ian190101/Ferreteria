import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link, router } from '@inertiajs/react';
import { promptAction } from '@/Utils/alerts';

export default function Show({ invoice }) {
    const voidInvoice = async () => {
        const reason = await promptAction({
            title: 'Anular factura SIAT',
            text: 'Esta accion se enviara al SIAT y quedara registrada en auditoria.',
            inputLabel: 'Motivo claro de anulacion',
            confirmButtonText: 'Anular factura',
            placeholder: 'Ejemplo: datos del cliente incorrectos',
        });
        if (!reason) return;
        router.patch(route('billing.invoices.void', invoice.id), { reason_code: 1, reason }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-slate-800 dark:text-slate-200">Factura SIAT</h2>}>
            <Head title={`Factura SIAT ${invoice.invoice_number}`} />
            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={`Factura SIAT ${invoice.invoice_number}`} description="Detalle fiscal, respuesta SIAT, XML y estado de validacion." />
                <div className="mb-5 flex flex-wrap gap-3">
                    <Link href={route('billing.index')} className="rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold">Volver</Link>
                    {invoice.status === 'validated' ? <button onClick={voidInvoice} className="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white">Anular factura</button> : null}
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    <Card title="Estado" value={statusLabel(invoice.status)} />
                    <Card title="CUF" value={invoice.cuf ?? '-'} />
                    <Card title="Codigo recepcion" value={invoice.reception_code ?? '-'} />
                    <Card title="Cliente" value={`${invoice.customer_name ?? '-'} (${invoice.customer_document})`} />
                    <Card title="Total" value={`Bs ${Number(invoice.total_amount).toFixed(2)}`} />
                    <Card title="Fecha emision" value={invoice.issued_at ?? '-'} />
                </div>
                <div className="mt-5 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-3 font-semibold">Detalle</h3>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs uppercase text-slate-500">
                                    <th className="px-3 py-2">Producto</th>
                                    <th className="px-3 py-2">Codigo SIN</th>
                                    <th className="px-3 py-2 text-right">Cantidad</th>
                                    <th className="px-3 py-2 text-right">Precio</th>
                                    <th className="px-3 py-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={item.id} className="border-t border-slate-100 dark:border-slate-800">
                                        <td className="px-3 py-2">{item.description}</td>
                                        <td className="px-3 py-2">{item.sin_product_code}</td>
                                        <td className="px-3 py-2 text-right">{item.quantity}</td>
                                        <td className="px-3 py-2 text-right">{item.unit_price}</td>
                                        <td className="px-3 py-2 text-right">{item.subtotal}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div className="mt-5 rounded-2xl border border-slate-200 bg-slate-950 p-4 text-xs text-slate-100">
                    <h3 className="mb-3 font-semibold">XML generado</h3>
                    <pre className="max-h-96 overflow-auto whitespace-pre-wrap">{invoice.signed_xml ?? invoice.xml ?? 'Sin XML generado.'}</pre>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function Card({ title, value }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{title}</p>
            <p className="mt-2 break-words text-sm font-semibold text-slate-950 dark:text-white">{value}</p>
        </div>
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
