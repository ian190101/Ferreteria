import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { promptAction } from '@/Utils/alerts';
import ActionLink from '../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Index({ sales, workflow, documentPolicy = {} }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('sales.manage');
    const canManagePayments = permissions.includes('payments.manage');
    const canManageSettings = permissions.includes('settings.manage');

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}
        >
            <Head title="Ventas" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Ventas" description={salesDescription(workflow)} />
                    <div className="flex flex-wrap gap-2">
                        {canManage ? (
                            <>
                                {workflow?.canCreateQuotation ? <ActionLink href={route('sales.create', { type: 'quotation' })}>Nueva {documentLabel('quotation', documentPolicy).toLowerCase()}</ActionLink> : null}
                                {workflow?.canCreateSaleNote ? <ActionLink href={route('sales.create', { type: 'sale_note' })}>{workflow?.requiresSourceQuotationForSaleNote ? `${documentLabel('sale_note', documentPolicy)} desde ${documentLabel('quotation', documentPolicy).toLowerCase()}` : `Nueva ${documentLabel('sale_note', documentPolicy).toLowerCase()}`}</ActionLink> : null}
                            </>
                        ) : null}
                        {canManageSettings ? (
                            <>
                                <ActionLink href={route('sales.settings.index')}>Catalogos</ActionLink>
                                <ActionLink href={route('sales.templates.index')}>Plantillas</ActionLink>
                            </>
                        ) : null}
                    </div>
                </div>

                <div className="my-5 rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                    Flujo activo: {workflowLabel(workflow)}. Cliente: {workflow?.customerRequired ? 'obligatorio' : 'opcional'}. Inventario: {inventoryTimingLabel(workflow?.inventoryDiscountTiming)}.
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Recibo</th>
                                <th className="px-4 py-3 font-medium">Tipo</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Cliente</th>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Total</th>
                                <th className="px-4 py-3 font-medium">Saldo</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Formato</th>
                                {canManage ? <th className="px-4 py-3 text-right font-medium">Acciones</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {sales.data.map((sale) => (
                                <tr key={sale.id}>
                                    <td className="px-4 py-3">{sale.receipt_number}</td>
                                    <td className="px-4 py-3">{documentTypeLabel(sale.document_type, documentPolicy)}</td>
                                    <td className="px-4 py-3">{sale.branch?.name}</td>
                                    <td className="px-4 py-3">{sale.customer_name ?? 'Consumidor final'}</td>
                                    <td className="px-4 py-3">{sale.sold_at}</td>
                                    <td className="px-4 py-3">{sale.currency?.symbol} {sale.total}</td>
                                    <td className="px-4 py-3">{sale.currency?.symbol} {sale.balance_due}</td>
                                    <td className="px-4 py-3">{statusLabel(sale.status)}</td>
                                    <td className="px-4 py-3">
                                        <IconButton href={route('sales.show', sale.id)} icon="eye" label="Ver / imprimir" />
                                    </td>
                                    {canManage ? (
                                        <td className="px-4 py-3 text-right">
                                            {sale.status === 'void' ? (
                                                <span className="text-slate-400">Anulado</span>
                                            ) : (
                                                <div className="flex justify-end gap-2">
                                                    {canManagePayments && sale.document_type === 'sale_note' && Number(sale.balance_due) > 0 ? (
                                                        <IconButton href={route('sales.show', sale.id)} icon="check" label="Pagar" tone="success" />
                                                    ) : null}
                                                    <IconButton icon="close" label="Anular" tone="danger" onClick={() => voidSale(sale)} />
                                                </div>
                                            )}
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={sales.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function salesDescription(workflow) {
    if (workflow?.mode === 'pos') {
        return 'Venta directa tipo POS. Para venta por lector usa POS rapido; esta vista queda para documentos administrativos.';
    }

    if (workflow?.quotationMode === 'disabled') {
        return 'Notas de venta directas sin cotizacion previa, segun la configuracion del negocio.';
    }

    if (workflow?.requiresSourceQuotationForSaleNote) {
        return 'El negocio trabaja con cotizacion obligatoria y luego conversion a nota de venta.';
    }

    return 'Cotizaciones y notas de venta con formato imprimible segun el comprobante configurado.';
}

function workflowLabel(workflow) {
    if (workflow?.mode === 'pos') return 'POS rapido';
    if (workflow?.mode === 'direct_sale') return 'Venta directa';
    if (workflow?.mode === 'service_sale') return 'Venta de servicios';
    if (workflow?.requiresSourceQuotationForSaleNote) return 'Cotizacion obligatoria';

    return 'Cotizacion opcional';
}

function inventoryTimingLabel(value) {
    return {
        sale_note: 'se descuenta al generar nota',
        payment: 'se descuenta al cobrar',
        delivery: 'se descuenta al despachar',
        manual: 'movimiento manual',
    }[value] ?? 'segun configuracion';
}

function documentLabel(type, policy) {
    if (type === 'quotation') return policy?.quotationLabel ?? 'Cotizacion';

    return policy?.documentMain === 'ticket' ? (policy?.ticketLabel ?? 'Ticket POS') : (policy?.saleNoteLabel ?? 'Nota de venta');
}

function documentTypeLabel(type, policy) {
    return documentLabel(type, policy);
}

function statusLabel(status) {
    return {
        quoted: 'Cotizada',
        issued: 'Emitida',
        partial_paid: 'Pago parcial',
        paid: 'Pagada',
        void: 'Anulada',
        converted: 'Convertida',
    }[status] ?? status;
}

async function voidSale(sale) {
    const reason = await promptAction({
        title: 'Anular documento',
        text: `Motivo para anular ${sale.receipt_number}`,
        confirmButtonText: 'Anular',
    });

    if (!reason) {
        return;
    }

    router.patch(route('sales.void', sale.id), { reason }, { preserveScroll: true });
}
