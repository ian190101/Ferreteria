import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, Link, useForm } from '@inertiajs/react';
import PurchaseLotChoice, { normalizeLotCode } from '../../../Components/PurchaseLotChoice';

export default function Receive({ order, lotTrackingEnabled = false }) {
    const decimalFormat = useDecimalFormatter('purchases');
    const { data, setData, post, processing, errors, transform } = useForm({
        received_at: new Date().toISOString().slice(0, 10),
        notes: '',
        items: order.items.map((item) => ({
            purchase_order_item_id: item.id,
            meters: '',
            weight_unit: 'kg',
            kilograms: '',
            coil_barcode: item.product?.inventory_tracking_mode === 'coil' ? item.coil_barcode ?? '' : '',
            lot_id: item.product?.requires_lot ? item.product_coil_id ?? '' : '',
            new_lot_code: item.product_coil_id ? '' : item.lot_number ?? '',
            lot_mode: item.product_coil_id ? 'existing' : 'new',
        })),
    });

    const updateItem = (index, field, value) => {
        setData('items', data.items.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)));
    };
    const addAnotherLot = (index) => {
        const source = data.items[index];
        setData('items', [...data.items.slice(0, index + 1), { ...source, meters: '', kilograms: '', coil_barcode: '', lot_id: '', new_lot_code: '', lot_mode: 'new' }, ...data.items.slice(index + 1)]);
    };

    const submit = (event) => {
        event.preventDefault();
        transform((payload) => ({ ...payload, items: payload.items.map((item) => ({ ...item, new_lot_code: item.lot_mode === 'new' ? normalizeLotCode(item.new_lot_code) : '', lot_id: item.lot_mode === 'existing' ? item.lot_id : '' })) }));
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
                        <FormField label="Fecha recepcion" name="received_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={errors.received_at} />
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
                                    <th className="px-4 py-3 font-medium">Cantidad a recibir (unidad base)</th>
                                    <th className="px-4 py-3 font-medium">Peso</th>
                                    <th className="px-4 py-3 font-medium">Unidad</th>
                                    <th className="px-4 py-3 font-medium">Barcode bobina</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {data.items.map((entry, index) => {
                                    const item = order.items.find((row) => String(row.id) === String(entry.purchase_order_item_id));
                                    const pending = Math.max(Number(item.meters ?? 0) - Number(item.received_meters ?? 0), 0);
                                    const isCoil = item.product?.inventory_tracking_mode === 'coil';

                                    return (
                                        <tr key={`${item.id}-${index}`} className={pending <= 0 ? 'bg-slate-50 opacity-70 dark:bg-slate-950' : ''}>
                                            <td className="px-4 py-3">
                                                <p className="font-semibold text-slate-900 dark:text-slate-100">{item.product?.name ?? '-'}</p>
                                                <p className="text-xs text-slate-500">{item.product?.sku ?? ''}</p>
                                                {lotTrackingEnabled && item.product?.requires_lot && pending > 0 ? <button type="button" onClick={() => addAnotherLot(index)} className="mt-3 text-left text-sm font-semibold text-brand-primary">+ Agregar otro lote de este producto</button> : null}
                                                {data.items.filter((row) => row.purchase_order_item_id === item.id).length > 1 ? <button type="button" onClick={() => setData('items', data.items.filter((_, position) => position !== index))} className="mt-2 block text-sm text-red-700">Quitar línea de lote</button> : null}
                                            </td>
                                            <td className="px-4 py-3 text-right">{decimalFormat.measure(item.meters ?? 0)}</td>
                                            <td className="px-4 py-3 text-right">{decimalFormat.measure(item.received_meters ?? 0)}</td>
                                            <td className="px-4 py-3 text-right font-semibold">{decimalFormat.measure(pending)}</td>
                                            <td className="px-4 py-3">
                                                <FormField
                                                    label={`Cantidad (${item.product?.base_unit ?? 'm'})`}
                                                    helpText={lotTrackingEnabled && item.product?.requires_lot ? 'Ingresa los metros conocidos. Si los omites, se calculan desde el peso y el espesor del producto.' : undefined}
                                                    name={`items.${index}.meters`}
                                                    type="number"
                                                    min="0"
                                                    max={pending}
                                                    step={decimalStep(decimalFormat.decimalsFor('measure'))}
                                                    value={data.items[index]?.meters ?? ''}
                                                    disabled={pending <= 0}
                                                    onChange={(event) => updateItem(index, 'meters', event.target.value)}
                                                    error={errors[`items.${index}.meters`]}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <FormField
                                                    label={lotTrackingEnabled && item.product?.requires_lot ? 'Peso (referencia si indicas metros)' : 'Peso de referencia'}
                                                    name={`items.${index}.kilograms`}
                                                    type="number"
                                                    min="0"
                                                    step={decimalStep(decimalFormat.decimalsFor('weight'))}
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
                                                {lotTrackingEnabled && item.product?.requires_lot ? <PurchaseLotChoice productId={item.product_id} branchId={order.branch_id} unit={item.product?.base_unit ?? 'm'} context="receipt" item={data.items[index]} index={index} errors={errors} onChange={(patch) => setData('items', data.items.map((entry, itemIndex) => itemIndex === index ? { ...entry, ...patch } : entry))} /> : <FormField
                                                    label="Barcode bobina"
                                                    name={`items.${index}.coil_barcode`}
                                                    value={data.items[index]?.coil_barcode ?? ''}
                                                    disabled={!isCoil || pending <= 0}
                                                    onChange={(event) => updateItem(index, 'coil_barcode', event.target.value)}
                                                    error={errors[`items.${index}.coil_barcode`]}
                                                />}
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
