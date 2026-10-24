import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmPurchaseVoid, submitPurchaseVoid } from '@/Utils/purchaseVoid';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link } from '@inertiajs/react';

export default function Show({ purchase }) {
    const voidPurchase = async () => {
        const reason = await confirmPurchaseVoid(purchase.id);

        if (!reason) {
            return;
        }

        submitPurchaseVoid(route('purchases.void', purchase.id), reason);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title={`Compra ${purchase.document_number}`} />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title={`Compra ${purchase.document_number}`} description="Detalle del ingreso y trazabilidad hacia inventario." />

                <div className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-6 text-sm shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                    <p><span className="font-semibold">Sucursal:</span> {purchase.branch?.name}</p>
                    <p><span className="font-semibold">Proveedor:</span> {purchase.supplier?.name ?? 'Sin proveedor'}</p>
                    <p><span className="font-semibold">Fecha:</span> {purchase.purchase_date}</p>
                    <p><span className="font-semibold">Total:</span> {purchase.total_amount}</p>
                    <p><span className="font-semibold">Pagado:</span> {purchase.paid_amount}</p>
                    <p><span className="font-semibold">Saldo:</span> {purchase.balance_due}</p>
                    <p><span className="font-semibold">Estado de pago:</span> {paymentStatusLabel(purchase.payment_status)}</p>
                    <p><span className="font-semibold">Estado de compra:</span> {purchaseStatusLabel(purchase.status)}</p>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Cantidad</th>
                                <th className="px-4 py-3 font-medium">Kg</th>
                                <th className="px-4 py-3 font-medium">Base</th>
                                <th className="px-4 py-3 font-medium">Costo</th>
                                <th className="px-4 py-3 font-medium">Lote/Bobina</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {purchase.items.map((item) => (
                                <tr key={item.id}>
                                    <td className="px-4 py-3">{item.product?.name}</td>
                                    <td className="px-4 py-3">{item.display_quantity ?? item.meters} {item.display_unit_label ?? 'unidad'}</td>
                                    <td className="px-4 py-3">{item.kilograms ?? '-'}</td>
                                    <td className="px-4 py-3">{(item.calculation_mode ?? 'direct') === 'direct' ? '-' : `${item.meters} m`}</td>
                                    <td className="px-4 py-3">{item.unit_cost}</td>
                                    <td className="px-4 py-3">{item.lot_code_snapshot ?? item.lot_number ?? item.coil?.barcode ?? '-'}{item.lot?.lot_code && item.lot_code_snapshot !== item.lot.lot_code ? <p className="text-xs text-slate-500">Actual: {item.lot.lot_code}</p> : null}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6 flex items-center justify-between">
                    <Link href={route('purchases.index')} className="text-sm text-brand-primary hover:underline">Volver a compras</Link>
                    {purchase.can_void && purchase.status !== 'voided' && purchase.status !== 'void' ? (
                        <button
                            type="button"
                            onClick={voidPurchase}
                            className="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2"
                        >
                            Anular compra y pagos
                        </button>
                    ) : null}
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function purchaseStatusLabel(status) {
    if (status === 'voided' || status === 'void') {
        return 'Anulada';
    }

    if (status === 'received') {
        return 'Recibida';
    }

    return 'Borrador';
}

function paymentStatusLabel(status) {
    if (status === 'paid') {
        return 'Pagada';
    }

    if (status === 'partial_paid') {
        return 'Parcial';
    }

    if (status === 'voided') {
        return 'Anulada';
    }

    return 'Pendiente';
}
