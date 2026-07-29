import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link, router } from '@inertiajs/react';
import { promptAction } from '@/Utils/alerts';

export default function Show({ invoice, qrUrl }) {
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
                    <button type="button" onClick={() => window.print()} className="rounded-full bg-brand-primary px-4 py-2 text-sm font-semibold text-white">Imprimir factura</button>
                    {invoice.status === 'validated' ? <button onClick={voidInvoice} className="rounded-full bg-red-600 px-4 py-2 text-sm font-semibold text-white">Anular factura</button> : null}
                </div>
                <style>{`
                    @media print {
                        body * { visibility: hidden !important; }
                        .siat-print, .siat-print * { visibility: visible !important; }
                        .siat-print { position: absolute; inset: 0; margin: 0; background: white !important; color: black !important; box-shadow: none !important; border: 0 !important; }
                    }
                `}</style>
                <div className="grid gap-4 md:grid-cols-3">
                    <Card title="Estado" value={statusLabel(invoice.status)} />
                    <Card title="CUF" value={invoice.cuf ?? '-'} />
                    <Card title="Codigo recepcion" value={invoice.reception_code ?? '-'} />
                    <Card title="Cliente" value={`${invoice.customer_name ?? '-'} (${invoice.customer_document})`} />
                    <Card title="Total" value={`Bs ${Number(invoice.total_amount).toFixed(2)}`} />
                    <Card title="Fecha emision" value={invoice.issued_at ?? '-'} />
                </div>
                <div className="siat-print mt-5 rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-950 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-white">
                    <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-300 pb-4">
                        <div>
                            <p className="text-lg font-black uppercase">{invoice.branch?.siat_setting?.business_name ?? invoice.branch?.siatSetting?.business_name ?? 'Factura SIAT'}</p>
                            <p>NIT: {invoice.branch?.siat_setting?.nit ?? invoice.branch?.siatSetting?.nit ?? '-'}</p>
                            <p>{invoice.branch?.address ?? '-'}</p>
                        </div>
                        <div className="text-right">
                            <p className="font-black uppercase">Factura</p>
                            <p>Nro.: {invoice.invoice_number}</p>
                            <p>CUF: <span className="break-all">{invoice.cuf ?? '-'}</span></p>
                        </div>
                    </div>
                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        <p><strong>Cliente:</strong> {invoice.customer_name ?? '-'}</p>
                        <p><strong>Documento:</strong> {invoice.customer_document ?? '-'}</p>
                        <p><strong>Fecha:</strong> {invoice.issued_at ?? '-'}</p>
                        <p><strong>Metodo pago SIAT:</strong> {invoice.payment_method_code}</p>
                    </div>
                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-y border-slate-300 text-left text-xs uppercase">
                                    <th className="px-2 py-2">Descripcion</th>
                                    <th className="px-2 py-2">Codigo SIN</th>
                                    <th className="px-2 py-2 text-right">Cantidad</th>
                                    <th className="px-2 py-2 text-right">Precio</th>
                                    <th className="px-2 py-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.items.map((item) => (
                                    <tr key={`print-${item.id}`} className="border-b border-slate-200">
                                        <td className="px-2 py-2">{item.description}</td>
                                        <td className="px-2 py-2">{item.sin_product_code}</td>
                                        <td className="px-2 py-2 text-right">{item.quantity}</td>
                                        <td className="px-2 py-2 text-right">{item.unit_price}</td>
                                        <td className="px-2 py-2 text-right">{item.subtotal}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-4 flex flex-wrap items-end justify-between gap-4 border-t border-slate-300 pt-4">
                        <div className="max-w-xl">
                            <p><strong>CUFD:</strong> <span className="break-all">{invoice.cufd ?? '-'}</span></p>
                            <p><strong>Verificacion QR:</strong> <span className="break-all">{qrUrl ?? 'Sin CUF validado'}</span></p>
                            <p className="mt-2 text-xs">Esta representacion grafica debe usarse junto al codigo QR generado desde la URL oficial SIAT.</p>
                        </div>
                        <div className="text-right">
                            <p>Subtotal: Bs {Number(invoice.taxable_amount ?? 0).toFixed(2)}</p>
                            <p className="text-lg font-black">Total: Bs {Number(invoice.total_amount ?? 0).toFixed(2)}</p>
                        </div>
                    </div>
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
