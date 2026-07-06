import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import { Head, Link } from '@inertiajs/react';

export default function Show({ purchase }) {
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
                                    <td className="px-4 py-3">{item.coil?.barcode ?? item.lot_number ?? '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Link href={route('purchases.index')} className="mt-6 inline-block text-sm text-brand-primary hover:underline">Volver a compras</Link>
            </section>
        </AuthenticatedLayout>
    );
}

function paymentStatusLabel(status) {
    if (status === 'paid') {
        return 'Pagada';
    }

    if (status === 'partial_paid') {
        return 'Parcial';
    }

    return 'Pendiente';
}
