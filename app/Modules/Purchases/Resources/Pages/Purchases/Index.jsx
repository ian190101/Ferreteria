import IconButton from '@/Components/IconButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { confirmPurchaseVoid, submitPurchaseVoid } from '@/Utils/purchaseVoid';
import ActionLink from '../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Index({ purchases }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('purchases.manage');

    const voidPurchase = async (purchase) => {
        const reason = await confirmPurchaseVoid(purchase.id);

        if (!reason) {
            return;
        }

        submitPurchaseVoid(route('purchases.void', purchase.id), reason);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Compras</h2>}>
            <Head title="Compras" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Compras" description="Ingreso de mercaderia con conversion de kg o toneladas a metros y actualizacion transaccional de inventario." />
                    <div className="flex flex-wrap gap-2">
                        {canManage ? <ActionLink href={route('purchases.create')}>Nueva compra</ActionLink> : null}
                        <ActionLink href={route('purchases.orders.index')}>Ordenes</ActionLink>
                        <ActionLink href={route('purchases.suppliers.index')}>Proveedores</ActionLink>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Documento</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Proveedor</th>
                                <th className="px-4 py-3 font-medium">Fecha</th>
                                <th className="px-4 py-3 font-medium">Total</th>
                                <th className="px-4 py-3 font-medium">Saldo</th>
                                <th className="px-4 py-3 font-medium">Pago</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 text-right font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {purchases.data.map((purchase) => (
                                <tr key={purchase.id}>
                                    <td className="px-4 py-3">{purchase.document_number}</td>
                                    <td className="px-4 py-3">{purchase.branch?.name}</td>
                                    <td className="px-4 py-3">{purchase.supplier?.name ?? 'Sin proveedor'}</td>
                                    <td className="px-4 py-3">{purchase.purchase_date}</td>
                                    <td className="px-4 py-3">{purchase.total_amount}</td>
                                    <td className="px-4 py-3">{purchase.balance_due}</td>
                                    <td className="px-4 py-3">{paymentStatusLabel(purchase.payment_status)}</td>
                                    <td className="px-4 py-3">{purchaseStatusBadge(purchase.status)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <Link href={route('purchases.show', purchase.id)} className="text-sm font-medium text-brand-primary hover:underline">
                                                Ver
                                            </Link>
                                            {purchase.can_void && purchase.status !== 'voided' && purchase.status !== 'void' ? (
                                                <IconButton
                                                    icon="close"
                                                    label="Anular compra y pagos"
                                                    tone="danger"
                                                    onClick={() => voidPurchase(purchase)}
                                                />
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={purchases.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function purchaseStatusBadge(status) {
    if (status === 'voided' || status === 'void') {
        return (
            <span className="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                Anulada
            </span>
        );
    }

    if (status === 'received') {
        return (
            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                Recibida
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-800 dark:bg-slate-800 dark:text-slate-300">
            Borrador
        </span>
    );
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
