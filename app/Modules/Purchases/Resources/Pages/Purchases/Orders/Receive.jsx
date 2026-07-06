import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { Head, Link, useForm } from '@inertiajs/react';

const numberFormatter = new Intl.NumberFormat('es-BO', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
});

export default function Receive({ order }) {
    const { data, setData, post, processing, errors } = useForm({
        received_at: new Date().toISOString().slice(0, 10),
        notes: '',
        items: order.items.map((item) => ({
            purchase_order_item_id: item.id,
            meters: '',
            weight_unit: 'kg',
            kilograms: '',
            coil_barcode: item.product?.inventory_tracking_mode === 'coil' ? item.coil_barcode ?? '' : '',
        })),
    });

    const updateItem = (index, field, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)));
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('purchases.orders.receipts.store', order.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Recepcion de orden</h2>}>
            <Head title={`Recibir ${order.order_number}`} />

            <section className="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <ModuleHeader title={`Recibir ${order.order_number}`} description="Registra entregas parciales o completas y mueve inventario solo por la cantidad recibida." />
                    <Link href={route('purchases.orders.index')} className="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        Volver
                    </Link>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-4">
                        <Summary label="Proveedor" value={order.supplier?.name ?? 'Sin proveedor'} />
                        <Summary label="Sucursal" value={order.branch?.name ?? '-'} />
                        <Summary label="Estado" value={statusLabel(order.status)} />
                        <FormField label="Fecha recepcion" name="received_at" type="date" value={data.received_at} onChange={(event) => setData('received_at', event.target.value)} error={errors.received_at} required />
                        <div className="sm:col-span-4">
                            <FormField label="Notas" name="notes" value={data.notes} onChange={(event) => setData('notes', event.target.value)} error={errors.notes} />
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Producto</th>
                                    <th className="px-4 py-3 text-right font-medium">Pedido</th>
                                    <th className="px-4 py-3 text-right font-medium">Recibido</th>
                                    <th className="px-4 py-3 text-right font-medium">Pendiente</th>
                                    <th className="px-4 py-3 font-medium">Metros a recibir</th>
                                    <th className="px-4 py-3 font-medium">Peso</th>
                                    <th className="px-4 py-3 font-medium">Unidad</th>
                                    <th className="px-4 py-3 font-medium">Barcode bobina</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {order.items.map((item, index) => {
                                    const pending = Math.max(Number(item.meters ?? 0) - Number(item.received_meters ?? 0), 0);
                                    const isCoil = item.product?.inventory_tracking_mode === 'coil';

                                    return (
                                        <tr key={item.id} className={pending <= 0 ? 'bg-slate-50 opacity-70 dark:bg-slate-950' : ''}>
                                            <td className="px-4 py-3">
                                                <p className="font-semibold text-slate-900 dark:text-slate-100">{item.product?.name ?? '-'}</p>
                                                <p className="text-xs text-slate-500">{item.product?.sku ?? ''}</p>
                                            </td>
                                            <td className="px-4 py-3 text-right">{numberFormatter.format(Number(item.meters ?? 0))}</td>
                                            <td className="px-4 py-3 text-right">{numberFormatter.format(Number(item.received_meters ?? 0))}</td>
                                            <td className="px-4 py-3 text-right font-semibold">{numberFormatter.format(pending)}</td>
                                            <td className="px-4 py-3">
                                                <FormField
                                                    label="Metros"
                                                    name={`items.${index}.meters`}
                                                    type="number"
                                                    min="0"
                                                    max={pending}
                                                    step="0.001"
                                                    value={data.items[index]?.meters ?? ''}
                                                    disabled={pending <= 0}
                                                    onChange={(event) => updateItem(index, 'meters', event.target.value)}
                                                    error={errors[`items.${index}.meters`]}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <FormField
                                                    label="Peso"
                                                    name={`items.${index}.kilograms`}
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    value={data.items[index]?.kilograms ?? ''}
                                                    disabled={pending <= 0}
                                                    onChange={(event) => updateItem(index, 'kilograms', event.target.value)}
                                                    error={errors[`items.${index}.kilograms`]}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <SelectField
                                                    label="Unidad"
                                                    name={`items.${index}.weight_unit`}
                                                    value={data.items[index]?.weight_unit ?? 'kg'}
                                                    disabled={pending <= 0}
                                                    onChange={(event) => updateItem(index, 'weight_unit', event.target.value)}
                                                    error={errors[`items.${index}.weight_unit`]}
                                                >
                                                    <option value="kg">Kg</option>
                                                    <option value="ton">Toneladas</option>
                                                </SelectField>
                                            </td>
                                            <td className="px-4 py-3">
                                                <FormField
                                                    label="Barcode bobina"
                                                    name={`items.${index}.coil_barcode`}
                                                    value={data.items[index]?.coil_barcode ?? ''}
                                                    disabled={!isCoil || pending <= 0}
                                                    onChange={(event) => updateItem(index, 'coil_barcode', event.target.value)}
                                                    error={errors[`items.${index}.coil_barcode`]}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {errors.items ? <p className="text-sm text-red-600">{errors.items}</p> : null}

                    <div className="flex items-center gap-3">
                        <PrimaryButton disabled={processing}>Registrar recepcion</PrimaryButton>
                        <Link href={route('purchases.orders.index')} className="text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">Cancelar</Link>
                    </div>
                </form>
            </section>
        </AuthenticatedLayout>
    );
}

function Summary({ label, value }) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className="mt-1 font-semibold text-slate-900 dark:text-slate-100">{value}</p>
        </div>
    );
}

function statusLabel(status) {
    const labels = {
        approved: 'Aprobada',
        partial_received: 'Parcial',
    };

    return labels[status] ?? status;
}
