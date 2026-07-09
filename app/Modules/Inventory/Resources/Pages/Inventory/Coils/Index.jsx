import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ActionLink from '../../../../../Shared/Resources/Components/ActionLink';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import { Head } from '@inertiajs/react';
import { useDecimalFormatter } from '@/Utils/formatters';

export default function Index({ coils }) {
    const decimalFormat = useDecimalFormatter('inventory');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Inventario</h2>}>
            <Head title="Lotes y unidades fisicas" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title="Lotes y unidades fisicas" description="Rastreo individual por lote, caja, paquete, rollo o pieza fisica segun la unidad configurada del producto." />
                    <ActionLink href={route('inventory.coils.create')}>Nuevo lote/unidad</ActionLink>
                </div>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Barcode</th>
                                <th className="px-4 py-3 font-medium">Lote</th>
                                <th className="px-4 py-3 font-medium">Producto</th>
                                <th className="px-4 py-3 font-medium">Sucursal</th>
                                <th className="px-4 py-3 font-medium">Cantidad disponible</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {coils.data.map((coil) => (
                                <tr key={coil.id}>
                                    <td className="px-4 py-3">{coil.barcode}</td>
                                    <td className="px-4 py-3">{coil.lot_number}</td>
                                    <td className="px-4 py-3">{coil.product?.name}</td>
                                    <td className="px-4 py-3">{coil.branch?.name}</td>
                                    <td className="px-4 py-3">{formatTrackedQuantity(coil.available_meters, coil.product, decimalFormat)}</td>
                                    <td className="px-4 py-3">{statusLabel(coil.status)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={coils.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function formatTrackedQuantity(value, product, decimalFormat) {
    const unit = product?.unit?.symbol ?? product?.base_unit ?? 'unidad';
    const normalized = String(unit).toLowerCase();
    const formatted = ['m', 'metro', 'metros'].includes(normalized)
        ? decimalFormat.measure(value)
        : ['kg', 'lb'].includes(normalized)
            ? decimalFormat.weight(value)
            : decimalFormat.quantity(value);

    return `${formatted} ${unit}`;
}

function statusLabel(status) {
    return {
        available: 'Disponible',
        depleted: 'Agotada',
        reserved: 'Reservada',
        inactive: 'Inactiva',
    }[status] ?? status;
}
