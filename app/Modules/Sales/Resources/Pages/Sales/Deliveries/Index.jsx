import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ deliveries, branches, sales, saleItems, drivers = [], trucks = [], statuses, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('sales.deliveries.manage');
    const decimalFormat = useDecimalFormatter('sales');
    const [editingDriver, setEditingDriver] = useState(null);
    const [editingTruck, setEditingTruck] = useState(null);
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        status: filters.status ?? '',
        sale_id: filters.sale_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 15,
    });
    const deliveryForm = useForm({
        sale_id: sales[0]?.id ?? '',
        delivery_number: nextDeliveryNumber(),
        delivered_at: currentDateTimeLocal(),
        delivery_driver_id: '',
        delivery_truck_id: '',
        manual_driver: false,
        manual_truck: false,
        recipient_name: '',
        recipient_document: '',
        recipient_phone: '',
        driver_name: '',
        vehicle_plate: '',
        notes: '',
        items: [
            {
                sale_item_id: '',
                quantity: '',
            },
        ],
    });
    const driverForm = useForm(driverDefaults());
    const truckForm = useForm(truckDefaults());

    const availableItems = saleItems.filter((item) => String(item.sale_id) === String(deliveryForm.data.sale_id));
    const selectedSale = sales.find((sale) => String(sale.id) === String(deliveryForm.data.sale_id));
    const saleBranchId = selectedSale?.branch_id ?? '';
    const branchDrivers = drivers.filter((driver) => !driver.branch_id || String(driver.branch_id) === String(saleBranchId));
    const branchTrucks = trucks.filter((truck) => !truck.branch_id || String(truck.branch_id) === String(saleBranchId));

    const submitFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('sales.deliveries.index'), { preserveScroll: true, preserveState: true });
    };

    const submitDelivery = (event) => {
        event.preventDefault();
        deliveryForm.post(route('sales.deliveries.store'), {
            preserveScroll: true,
            onSuccess: () => {
                deliveryForm.reset('recipient_name', 'recipient_document', 'recipient_phone', 'driver_name', 'vehicle_plate', 'notes', 'items');
                deliveryForm.setData({
                    ...deliveryForm.data,
                    delivery_number: nextDeliveryNumber(),
                    recipient_name: '',
                    recipient_document: '',
                    recipient_phone: '',
                    driver_name: '',
                    vehicle_plate: '',
                    notes: '',
                    items: [{ sale_item_id: '', quantity: '' }],
                });
            },
        });
    };

    const setSale = (saleId) => {
        deliveryForm.setData({
            ...deliveryForm.data,
            sale_id: saleId,
            delivery_driver_id: '',
            delivery_truck_id: '',
            manual_driver: false,
            manual_truck: false,
            driver_name: '',
            vehicle_plate: '',
            items: [{ sale_item_id: '', quantity: '' }],
        });
    };

    const updateItem = (index, field, value) => {
        deliveryForm.setData('items', deliveryForm.data.items.map((item, itemIndex) => (
            itemIndex === index ? { ...item, [field]: value } : item
        )));
    };

    const addItem = () => {
        deliveryForm.setData('items', [...deliveryForm.data.items, { sale_item_id: '', quantity: '' }]);
    };

    const removeItem = (index) => {
        deliveryForm.setData('items', deliveryForm.data.items.filter((_, itemIndex) => itemIndex !== index));
    };

    const fillPending = (index) => {
        const item = itemForRow(availableItems, deliveryForm.data.items[index]);

        if (item) {
            updateItem(index, 'quantity', item.pending_quantity);
        }
    };

    const selectDriver = (value) => {
        const driver = drivers.find((item) => String(item.id) === String(value));

        deliveryForm.setData({
            ...deliveryForm.data,
            delivery_driver_id: value,
            manual_driver: value === 'manual',
            driver_name: value === 'manual' ? deliveryForm.data.driver_name : (driver?.name ?? ''),
        });
    };

    const selectTruck = (value) => {
        const truck = trucks.find((item) => String(item.id) === String(value));

        deliveryForm.setData({
            ...deliveryForm.data,
            delivery_truck_id: value,
            manual_truck: value === 'manual',
            vehicle_plate: value === 'manual' ? deliveryForm.data.vehicle_plate : (truck?.plate ?? ''),
        });
    };

    const submitDriver = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => {
            driverForm.setData(driverDefaults());
            setEditingDriver(null);
        } };

        if (editingDriver) {
            driverForm.put(route('sales.deliveries.drivers.update', editingDriver.id), options);
            return;
        }

        driverForm.post(route('sales.deliveries.drivers.store'), options);
    };

    const submitTruck = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => {
            truckForm.setData(truckDefaults());
            setEditingTruck(null);
        } };

        if (editingTruck) {
            truckForm.put(route('sales.deliveries.trucks.update', editingTruck.id), options);
            return;
        }

        truckForm.post(route('sales.deliveries.trucks.store'), options);
    };

    const editDriver = (driver) => {
        setEditingDriver(driver);
        driverForm.setData({
            branch_id: driver.branch_id ?? '',
            name: driver.name ?? '',
            document_number: driver.document_number ?? '',
            phone: driver.phone ?? '',
            license_number: driver.license_number ?? '',
            is_active: driver.is_active ?? true,
        });
    };

    const editTruck = (truck) => {
        setEditingTruck(truck);
        truckForm.setData({
            branch_id: truck.branch_id ?? '',
            plate: truck.plate ?? '',
            description: truck.description ?? '',
            brand: truck.brand ?? '',
            model: truck.model ?? '',
            capacity: truck.capacity ?? '',
            is_active: truck.is_active ?? true,
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Ventas</h2>}>
            <Head title="Despachos" />

            <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <ModuleHeader title="Despachos" description="Registro de entregas fisicas parciales o completas vinculadas a notas de venta." />

                {canManage ? (
                    <form onSubmit={submitDelivery} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                        <SelectField label="Nota de venta" name="sale_id" value={deliveryForm.data.sale_id} onChange={(event) => setSale(event.target.value)} error={deliveryForm.errors.sale_id} required>
                            <option value="">Seleccionar</option>
                            {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number} - {sale.customer_name ?? 'Cliente'}</option>)}
                        </SelectField>
                        <SelectField label="Conductor" name="delivery_driver_id" value={deliveryForm.data.delivery_driver_id} onChange={(event) => selectDriver(event.target.value)} error={deliveryForm.errors.delivery_driver_id}>
                            <option value="">Sin conductor</option>
                            <option value="manual">Conductor manual</option>
                            {branchDrivers.map((driver) => <option key={driver.id} value={driver.id}>{driver.name} {driver.license_number ? `- ${driver.license_number}` : ''}</option>)}
                        </SelectField>
                        <SelectField label="Camion" name="delivery_truck_id" value={deliveryForm.data.delivery_truck_id} onChange={(event) => selectTruck(event.target.value)} error={deliveryForm.errors.delivery_truck_id}>
                            <option value="">Sin camion</option>
                            <option value="manual">Camion manual</option>
                            {branchTrucks.map((truck) => <option key={truck.id} value={truck.id}>{truck.plate} {truck.description ? `- ${truck.description}` : ''}</option>)}
                        </SelectField>
                        <FormField label="Numero" name="delivery_number" value={deliveryForm.data.delivery_number} onChange={(event) => deliveryForm.setData('delivery_number', event.target.value)} error={deliveryForm.errors.delivery_number} required />
                        <FormField label="Fecha" name="delivered_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={deliveryForm.errors.delivered_at} />
                        <FormField label="Recibe" name="recipient_name" value={deliveryForm.data.recipient_name} onChange={(event) => deliveryForm.setData('recipient_name', event.target.value)} error={deliveryForm.errors.recipient_name} />
                        <FormField label="Documento recibe" name="recipient_document" value={deliveryForm.data.recipient_document} onChange={(event) => deliveryForm.setData('recipient_document', event.target.value)} error={deliveryForm.errors.recipient_document} />
                        <FormField label="Telefono recibe" name="recipient_phone" value={deliveryForm.data.recipient_phone} onChange={(event) => deliveryForm.setData('recipient_phone', event.target.value)} error={deliveryForm.errors.recipient_phone} />
                        <FormField label="Nombre conductor" name="driver_name" value={deliveryForm.data.driver_name} onChange={(event) => deliveryForm.setData('driver_name', event.target.value)} error={deliveryForm.errors.driver_name} disabled={!deliveryForm.data.manual_driver && deliveryForm.data.delivery_driver_id !== ''} />
                        <FormField label="Placa camion" name="vehicle_plate" value={deliveryForm.data.vehicle_plate} onChange={(event) => deliveryForm.setData('vehicle_plate', event.target.value.toUpperCase())} error={deliveryForm.errors.vehicle_plate} disabled={!deliveryForm.data.manual_truck && deliveryForm.data.delivery_truck_id !== ''} />
                        <div className="sm:col-span-2 lg:col-span-4">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Productos a despachar</h3>
                                <button type="button" onClick={addItem} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">
                                    Agregar producto
                                </button>
                            </div>
                            <div className="space-y-3">
                                {deliveryForm.data.items.map((item, index) => {
                                    const selectedItem = itemForRow(availableItems, item);

                                    return (
                                        <div key={index} className="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-950 sm:grid-cols-[1.5fr_0.7fr_auto_auto]">
                                            <SelectField label="Producto pendiente" name={`items.${index}.sale_item_id`} value={item.sale_item_id ?? ''} onChange={(event) => updateItem(index, 'sale_item_id', event.target.value)} error={deliveryForm.errors[`items.${index}.sale_item_id`]} required>
                                                <option value="">Seleccionar</option>
                                                {availableItems.map((saleItem) => (
                                                    <option key={saleItem.id} value={saleItem.id}>
                                                        {itemOptionLabel(saleItem, decimalFormat)}
                                                    </option>
                                                ))}
                                            </SelectField>
                                            <FormField
                                                label={`Cantidad${selectedItem?.display_unit_label ? ` (${selectedItem.display_unit_label})` : ''}`}
                                                name={`items.${index}.quantity`}
                                                type="number"
                                                step={decimalStep(decimalFormat.decimalsFor(precisionKindForUnit(selectedItem?.display_unit_label)))}
                                                min={decimalStep(decimalFormat.decimalsFor(precisionKindForUnit(selectedItem?.display_unit_label)))}
                                                max={selectedItem?.pending_quantity ?? undefined}
                                                value={item.quantity ?? ''}
                                                onChange={(event) => updateItem(index, 'quantity', event.target.value)}
                                                error={deliveryForm.errors[`items.${index}.quantity`] ?? deliveryForm.errors.items}
                                                required
                                            />
                                            <div className="flex items-end">
                                                <button type="button" onClick={() => fillPending(index)} disabled={!selectedItem} className="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700">
                                                    Todo pendiente
                                                </button>
                                            </div>
                                            <div className="flex items-end">
                                                <button type="button" onClick={() => removeItem(index)} disabled={deliveryForm.data.items.length === 1} className="rounded-md border border-red-200 px-3 py-2 text-sm text-red-600 disabled:opacity-40 dark:border-red-900/60">
                                                    Quitar
                                                </button>
                                            </div>
                                            {selectedItem ? (
                                                <p className="text-xs text-slate-500 dark:text-slate-400 sm:col-span-4">
                                                    Pendiente: {quantityLabel(selectedItem.pending_quantity, selectedItem.display_unit_label, decimalFormat)} ({decimalFormat.measure(selectedItem.pending_meters)} base). Entregado: {quantityLabel(selectedItem.delivered_quantity, selectedItem.display_unit_label, decimalFormat)}. Devuelto: {quantityLabel(selectedItem.returned_quantity, selectedItem.display_unit_label, decimalFormat)}. Origen: {selectedItem.coil?.barcode ?? 'Stock global'}.
                                                </p>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                        <div className="sm:col-span-2">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300" htmlFor="notes">Notas</label>
                            <textarea id="notes" name="notes" rows="2" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" value={deliveryForm.data.notes} onChange={(event) => deliveryForm.setData('notes', event.target.value)} />
                            {deliveryForm.errors.notes ? <p className="mt-2 text-sm text-red-600">{deliveryForm.errors.notes}</p> : null}
                        </div>
                        <div className="flex items-end">
                            <button disabled={deliveryForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                                Registrar despacho
                            </button>
                        </div>
                    </form>
                ) : null}

                {canManage ? (
                    <div className="mb-6 grid gap-6 lg:grid-cols-2">
                        <CatalogPanel title={editingDriver ? 'Editar conductor' : 'Nuevo conductor'} onSubmit={submitDriver} processing={driverForm.processing} buttonLabel={editingDriver ? 'Actualizar conductor' : 'Crear conductor'} onCancel={editingDriver ? () => { setEditingDriver(null); driverForm.setData(driverDefaults()); } : null}>
                            <SelectField label="Sucursal" name="driver_branch_id" value={driverForm.data.branch_id} onChange={(event) => driverForm.setData('branch_id', event.target.value)} error={driverForm.errors.branch_id}>
                                <option value="">Global</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Nombre" name="driver_name_catalog" value={driverForm.data.name} onChange={(event) => driverForm.setData('name', event.target.value)} error={driverForm.errors.name} required />
                            <FormField label="Documento" name="driver_document" value={driverForm.data.document_number} onChange={(event) => driverForm.setData('document_number', event.target.value)} error={driverForm.errors.document_number} />
                            <FormField label="Telefono" name="driver_phone" value={driverForm.data.phone} onChange={(event) => driverForm.setData('phone', event.target.value)} error={driverForm.errors.phone} />
                            <FormField label="Licencia" name="driver_license" value={driverForm.data.license_number} onChange={(event) => driverForm.setData('license_number', event.target.value)} error={driverForm.errors.license_number} />
                            <SelectField label="Estado" name="driver_active" value={driverForm.data.is_active ? '1' : '0'} onChange={(event) => driverForm.setData('is_active', event.target.value === '1')} error={driverForm.errors.is_active}>
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </SelectField>
                            <CatalogList items={drivers} renderItem={(driver) => (
                                <button key={driver.id} type="button" onClick={() => editDriver(driver)} className="rounded-md border border-slate-200 px-3 py-2 text-left text-sm dark:border-slate-800">
                                    <span className="font-semibold">{driver.name}</span>
                                    <span className="block text-xs text-slate-500">{driver.license_number ?? 'Sin licencia'} {driver.branch_id ? '- Sucursal' : '- Global'}</span>
                                </button>
                            )} />
                        </CatalogPanel>

                        <CatalogPanel title={editingTruck ? 'Editar camion' : 'Nuevo camion'} onSubmit={submitTruck} processing={truckForm.processing} buttonLabel={editingTruck ? 'Actualizar camion' : 'Crear camion'} onCancel={editingTruck ? () => { setEditingTruck(null); truckForm.setData(truckDefaults()); } : null}>
                            <SelectField label="Sucursal" name="truck_branch_id" value={truckForm.data.branch_id} onChange={(event) => truckForm.setData('branch_id', event.target.value)} error={truckForm.errors.branch_id}>
                                <option value="">Global</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Placa" name="truck_plate" value={truckForm.data.plate} onChange={(event) => truckForm.setData('plate', event.target.value.toUpperCase())} error={truckForm.errors.plate} required />
                            <FormField label="Descripcion" name="truck_description" value={truckForm.data.description} onChange={(event) => truckForm.setData('description', event.target.value)} error={truckForm.errors.description} />
                            <FormField label="Marca" name="truck_brand" value={truckForm.data.brand} onChange={(event) => truckForm.setData('brand', event.target.value)} error={truckForm.errors.brand} />
                            <FormField label="Modelo" name="truck_model" value={truckForm.data.model} onChange={(event) => truckForm.setData('model', event.target.value)} error={truckForm.errors.model} />
                            <FormField label="Capacidad" name="truck_capacity" type="number" step={decimalStep(decimalFormat.decimalsFor('measure'))} min="0" value={truckForm.data.capacity} onChange={(event) => truckForm.setData('capacity', event.target.value)} error={truckForm.errors.capacity} />
                            <SelectField label="Estado" name="truck_active" value={truckForm.data.is_active ? '1' : '0'} onChange={(event) => truckForm.setData('is_active', event.target.value === '1')} error={truckForm.errors.is_active}>
                                <option value="1">Activo</option>
                                <option value="0">Inactivo</option>
                            </SelectField>
                            <CatalogList items={trucks} renderItem={(truck) => (
                                <button key={truck.id} type="button" onClick={() => editTruck(truck)} className="rounded-md border border-slate-200 px-3 py-2 text-left text-sm dark:border-slate-800">
                                    <span className="font-semibold">{truck.plate}</span>
                                    <span className="block text-xs text-slate-500">{truck.description ?? 'Sin descripcion'} {truck.branch_id ? '- Sucursal' : '- Global'}</span>
                                </button>
                            )} />
                        </CatalogPanel>
                    </div>
                ) : null}

                <form onSubmit={submitFilters} className="mb-6 grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-7">
                    <SelectField label="Sucursal" name="branch_id" value={filterForm.data.branch_id} onChange={(event) => filterForm.setData('branch_id', event.target.value)}>
                        <option value="">Todas</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </SelectField>
                    <SelectField label="Estado" name="status" value={filterForm.data.status} onChange={(event) => filterForm.setData('status', event.target.value)}>
                        <option value="">Todos</option>
                        {statuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
                    </SelectField>
                    <SelectField label="Venta" name="sale_id" value={filterForm.data.sale_id} onChange={(event) => filterForm.setData('sale_id', event.target.value)}>
                        <option value="">Todas</option>
                        {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.receipt_number}</option>)}
                    </SelectField>
                    <FormField label="Desde" name="from" type="date" value={filterForm.data.from} onChange={(event) => filterForm.setData('from', event.target.value)} />
                    <FormField label="Hasta" name="to" type="date" value={filterForm.data.to} onChange={(event) => filterForm.setData('to', event.target.value)} />
                    <FormField label="Buscar" name="search" value={filterForm.data.search} onChange={(event) => filterForm.setData('search', event.target.value)} />
                    <div className="flex items-end gap-2">
                        <button disabled={filterForm.processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                            Filtrar
                        </button>
                        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm dark:border-slate-700" type="button" onClick={() => router.get(route('sales.deliveries.index'))}>
                            Limpiar
                        </button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead className="bg-slate-100 text-left text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <tr>
                                <th className="px-4 py-3 font-medium">Despacho</th>
                                <th className="px-4 py-3 font-medium">Venta</th>
                                <th className="px-4 py-3 font-medium">Entrega</th>
                                <th className="px-4 py-3 font-medium">Items</th>
                                <th className="px-4 py-3 text-right font-medium">Metros</th>
                                <th className="px-4 py-3 font-medium">Estado</th>
                                <th className="px-4 py-3 font-medium">Usuario</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {deliveries.data.map((delivery) => (
                                <tr key={delivery.id}>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <p className="font-medium">{delivery.delivery_number}</p>
                                        <p className="text-xs text-slate-500">{formatDate(delivery.delivered_at)}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{delivery.sale?.receipt_number ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{delivery.sale?.customer_name ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p>{delivery.recipient_name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{delivery.driver_name ?? '-'}</p>
                                        <p className="text-xs text-slate-500">{delivery.vehicle_plate ?? '-'}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {delivery.items.map((item) => (
                                            <p key={item.id} className="text-xs">
                                                {item.product?.name ?? '-'} - {quantityLabel(item.display_quantity || item.meters, item.display_unit_label || 'base', decimalFormat)} {item.coil ? `(${item.coil.barcode})` : '(global)'}
                                            </p>
                                        ))}
                                    </td>
                                    <td className="px-4 py-3 text-right">{decimalFormat.measure(delivery.total_meters ?? 0)} m</td>
                                    <td className="px-4 py-3">{statusLabel(delivery.status)}</td>
                                    <td className="px-4 py-3">{delivery.user?.name ?? '-'}</td>
                                </tr>
                            ))}
                            {deliveries.data.length === 0 ? (
                                <tr>
                                    <td className="px-4 py-6 text-center text-slate-500" colSpan="7">
                                        No hay despachos registrados.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6">
                    <Pagination links={deliveries.links} />
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function statusLabel(status) {
    return {
        partial: 'Parcial',
        completed: 'Completo',
    }[status] ?? status;
}

function itemForRow(availableItems, row) {
    return availableItems.find((item) => String(item.id) === String(row?.sale_item_id));
}

function itemOptionLabel(item, decimalFormat) {
    return `${item.product?.name ?? item.description} - ${quantityLabel(item.pending_quantity, item.display_unit_label, decimalFormat)} pend.`;
}

function quantityLabel(quantity, unit, decimalFormat) {
    return `${decimalFormat.format(quantity ?? 0, precisionKindForUnit(unit))} ${unit ?? ''}`.trim();
}

function precisionKindForUnit(unit) {
    const normalized = String(unit ?? '').toLowerCase();

    if (['m', 'mt', 'mts', 'metro', 'metros', 'base'].includes(normalized)) {
        return 'measure';
    }

    if (['kg', 'kilo', 'kilos', 'ton', 'tn', 'tonelada', 'toneladas', 'lb', 'lbs'].includes(normalized)) {
        return 'weight';
    }

    return 'quantity';
}

function CatalogPanel({ title, children, onSubmit, processing, buttonLabel, onCancel }) {
    return (
        <form onSubmit={onSubmit} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <h3 className="mb-4 text-base font-semibold text-slate-950 dark:text-white">{title}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
                {children}
            </div>
            <div className="mt-4 flex items-center gap-3">
                <button disabled={processing} className="rounded-md bg-brand-primary px-4 py-2 text-sm font-semibold text-white" type="submit">
                    {buttonLabel}
                </button>
                {onCancel ? (
                    <button type="button" onClick={onCancel} className="text-sm text-slate-500">
                        Cancelar
                    </button>
                ) : null}
            </div>
        </form>
    );
}

function CatalogList({ items, renderItem }) {
    return (
        <div className="sm:col-span-2">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Registrados</p>
            <div className="grid max-h-48 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                {items.length ? items.map(renderItem) : <p className="text-sm text-slate-500">Sin registros.</p>}
            </div>
        </div>
    );
}

function driverDefaults() {
    return {
        branch_id: '',
        name: '',
        document_number: '',
        phone: '',
        license_number: '',
        is_active: true,
    };
}

function truckDefaults() {
    return {
        branch_id: '',
        plate: '',
        description: '',
        brand: '',
        model: '',
        capacity: '',
        is_active: true,
    };
}

function nextDeliveryNumber() {
    return `DESP-${new Date().getFullYear()}-${String(Date.now()).slice(-6)}`;
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('es-BO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
