import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { currentDateTimeLocal } from '@/Utils/dateTime';
import FormField from '../../../../../Shared/Resources/Components/FormField';
import ModuleHeader from '../../../../../Shared/Resources/Components/ModuleHeader';
import Pagination from '../../../../../Shared/Resources/Components/Pagination';
import SelectField from '../../../../../Shared/Resources/Components/SelectField';
import { decimalStep, useDecimalFormatter } from '@/Utils/formatters';
import axios from 'axios';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ deliveries, branches, sales, saleItems, drivers = [], trucks = [], statuses, filters }) {
    const permissions = usePage().props.auth.permissions;
    const canManage = permissions.includes('sales.deliveries.manage');
    const decimalFormat = useDecimalFormatter('sales');
    const [editingDriver, setEditingDriver] = useState(null);
    const [editingTruck, setEditingTruck] = useState(null);
    const [geoQuery, setGeoQuery] = useState('');
    const [geoResults, setGeoResults] = useState([]);
    const [routeInfo, setRouteInfo] = useState(null);
    const [mapError, setMapError] = useState('');
    const [mapLoading, setMapLoading] = useState(false);
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
        destination_address: '',
        destination_latitude: '',
        destination_longitude: '',
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
    const selectedBranch = branches.find((branch) => String(branch.id) === String(saleBranchId));
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
                deliveryForm.reset('recipient_name', 'recipient_document', 'recipient_phone', 'driver_name', 'vehicle_plate', 'destination_address', 'destination_latitude', 'destination_longitude', 'notes', 'items');
                setGeoQuery('');
                setGeoResults([]);
                setRouteInfo(null);
                setMapError('');
                deliveryForm.setData({
                    ...deliveryForm.data,
                    delivery_number: nextDeliveryNumber(),
                    recipient_name: '',
                    recipient_document: '',
                    recipient_phone: '',
                    driver_name: '',
                    vehicle_plate: '',
                    destination_address: '',
                    destination_latitude: '',
                    destination_longitude: '',
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
            destination_address: '',
            destination_latitude: '',
            destination_longitude: '',
            items: [{ sale_item_id: '', quantity: '' }],
        });
        setGeoQuery('');
        setGeoResults([]);
        setRouteInfo(null);
        setMapError('');
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
            vehicle_type: truck.vehicle_type ?? 'truck',
            description: truck.description ?? '',
            brand: truck.brand ?? '',
            model: truck.model ?? '',
            capacity: truck.capacity ?? '',
            is_active: truck.is_active ?? true,
        });
    };

    const searchDestination = async () => {
        setMapError('');
        setGeoResults([]);

        if (geoQuery.trim().length < 3) {
            setMapError('Ingresa al menos 3 caracteres para buscar.');
            return;
        }

        setMapLoading(true);

        try {
            const response = await axios.get(route('sales.deliveries.geocode'), { params: { query: geoQuery } });
            setGeoResults(response.data.results ?? []);

            if ((response.data.results ?? []).length === 0) {
                setMapError('No se encontraron coincidencias para esa direccion.');
            }
        } catch (error) {
            setMapError(error.response?.data?.message ?? firstError(error.response?.data?.errors) ?? 'No se pudo buscar la direccion.');
        } finally {
            setMapLoading(false);
        }
    };

    const selectDestination = (place) => {
        deliveryForm.setData({
            ...deliveryForm.data,
            destination_address: place.label,
            destination_latitude: place.latitude,
            destination_longitude: place.longitude,
        });
        setGeoQuery(place.label);
        setRouteInfo(null);
        setMapError('');
    };

    const calculateRoute = async () => {
        setMapError('');
        setRouteInfo(null);

        if (!deliveryForm.data.sale_id) {
            setMapError('Selecciona una nota de venta antes de calcular ruta.');
            return;
        }

        if (!selectedBranch?.latitude || !selectedBranch?.longitude) {
            setMapError('Configura latitud y longitud de la sucursal para usarla como punto de salida.');
            return;
        }

        if (!deliveryForm.data.destination_latitude || !deliveryForm.data.destination_longitude) {
            setMapError('Selecciona un destino desde los resultados de busqueda.');
            return;
        }

        setMapLoading(true);

        try {
            const response = await axios.post(route('sales.deliveries.route'), {
                sale_id: deliveryForm.data.sale_id,
                destination_latitude: deliveryForm.data.destination_latitude,
                destination_longitude: deliveryForm.data.destination_longitude,
            });
            setRouteInfo(response.data.route ?? null);
        } catch (error) {
            setMapError(error.response?.data?.message ?? firstError(error.response?.data?.errors) ?? 'No se pudo calcular la ruta por carretera.');
        } finally {
            setMapLoading(false);
        }
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
                        <SelectField label="Vehiculo" name="delivery_truck_id" value={deliveryForm.data.delivery_truck_id} onChange={(event) => selectTruck(event.target.value)} error={deliveryForm.errors.delivery_truck_id}>
                            <option value="">Sin vehiculo</option>
                            <option value="manual">Vehiculo manual</option>
                            {branchTrucks.map((truck) => <option key={truck.id} value={truck.id}>{truck.plate} - {vehicleTypeLabel(truck.vehicle_type)} {truck.description ? `- ${truck.description}` : ''}</option>)}
                        </SelectField>
                        <FormField label="Numero" name="delivery_number" value={deliveryForm.data.delivery_number} onChange={(event) => deliveryForm.setData('delivery_number', event.target.value)} error={deliveryForm.errors.delivery_number} required />
                        <FormField label="Fecha" name="delivered_at" value="Se registrara automaticamente al guardar" disabled className="mt-1 block w-full rounded-md border-gray-300 bg-slate-100 shadow-sm dark:border-gray-700 dark:bg-slate-800 dark:text-gray-300" error={deliveryForm.errors.delivered_at} />
                        <FormField label="Recibe" name="recipient_name" value={deliveryForm.data.recipient_name} onChange={(event) => deliveryForm.setData('recipient_name', event.target.value)} error={deliveryForm.errors.recipient_name} />
                        <FormField label="Documento recibe" name="recipient_document" value={deliveryForm.data.recipient_document} onChange={(event) => deliveryForm.setData('recipient_document', event.target.value)} error={deliveryForm.errors.recipient_document} />
                        <FormField label="Telefono recibe" name="recipient_phone" value={deliveryForm.data.recipient_phone} onChange={(event) => deliveryForm.setData('recipient_phone', event.target.value)} error={deliveryForm.errors.recipient_phone} />
                        <FormField label="Nombre conductor" name="driver_name" value={deliveryForm.data.driver_name} onChange={(event) => deliveryForm.setData('driver_name', event.target.value)} error={deliveryForm.errors.driver_name} disabled={!deliveryForm.data.manual_driver && deliveryForm.data.delivery_driver_id !== ''} />
                        <FormField label="Placa vehiculo" name="vehicle_plate" value={deliveryForm.data.vehicle_plate} onChange={(event) => deliveryForm.setData('vehicle_plate', event.target.value.toUpperCase())} error={deliveryForm.errors.vehicle_plate} disabled={!deliveryForm.data.manual_truck && deliveryForm.data.delivery_truck_id !== ''} />
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-950 sm:col-span-2 lg:col-span-4">
                            <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Ruta transporte</h3>
                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        Busca el destino solo cuando sea necesario. La ruta definitiva se recalcula en backend al guardar.
                                    </p>
                                </div>
                                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">
                                    © OpenStreetMap · OSRM
                                </span>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
                                <div className="space-y-3">
                                    <div className="rounded-md border border-slate-200 bg-white p-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                                        <span className="font-semibold">Origen:</span> {selectedBranch?.name ?? 'Selecciona una nota'} · {selectedBranch?.address ?? 'Sin direccion'}
                                        {selectedBranch?.latitude && selectedBranch?.longitude ? (
                                            <span className="ml-1">({selectedBranch.latitude}, {selectedBranch.longitude})</span>
                                        ) : (
                                            <span className="ml-1 text-amber-600 dark:text-amber-300">Coordenadas no configuradas.</span>
                                        )}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                                        <FormField label="Buscar destino" name="destination_search" value={geoQuery} onChange={(event) => setGeoQuery(event.target.value)} error={deliveryForm.errors.destination_address} />
                                        <div className="flex items-end">
                                            <button type="button" onClick={searchDestination} disabled={mapLoading} className="w-full rounded-md border border-brand-primary px-4 py-2 text-sm font-semibold text-brand-primary disabled:opacity-50">
                                                Buscar
                                            </button>
                                        </div>
                                    </div>

                                    {geoResults.length > 0 ? (
                                        <div className="max-h-44 overflow-y-auto rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                                            {geoResults.map((place, index) => (
                                                <button key={`${place.latitude}-${place.longitude}-${index}`} type="button" onClick={() => selectDestination(place)} className="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800">
                                                    <span className="font-medium text-slate-900 dark:text-white">{place.label}</span>
                                                    <span className="mt-1 block text-xs text-slate-500">{place.latitude}, {place.longitude}</span>
                                                </button>
                                            ))}
                                        </div>
                                    ) : null}

                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <FormField label="Destino confirmado" name="destination_address" value={deliveryForm.data.destination_address} onChange={(event) => deliveryForm.setData('destination_address', event.target.value)} error={deliveryForm.errors.destination_address} />
                                        <FormField label="Latitud destino" name="destination_latitude" type="number" step="0.0000001" value={deliveryForm.data.destination_latitude} onChange={(event) => { deliveryForm.setData('destination_latitude', event.target.value); setRouteInfo(null); }} error={deliveryForm.errors.destination_latitude} />
                                        <FormField label="Longitud destino" name="destination_longitude" type="number" step="0.0000001" value={deliveryForm.data.destination_longitude} onChange={(event) => { deliveryForm.setData('destination_longitude', event.target.value); setRouteInfo(null); }} error={deliveryForm.errors.destination_longitude} />
                                    </div>

                                    <div className="flex flex-wrap items-center gap-3">
                                        <button type="button" onClick={calculateRoute} disabled={mapLoading || !deliveryForm.data.destination_latitude || !deliveryForm.data.destination_longitude} className="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-white dark:text-slate-950">
                                            Calcular ruta por carretera
                                        </button>
                                        {routeInfo ? (
                                            <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                                {routeInfo.distance_km} km · {routeInfo.duration_minutes} min aprox. {routeInfo.cached ? '(cache)' : ''}
                                            </p>
                                        ) : null}
                                        {mapError ? <p className="text-sm text-red-600 dark:text-red-300">{mapError}</p> : null}
                                    </div>
                                </div>

                                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                                    {deliveryForm.data.destination_latitude && deliveryForm.data.destination_longitude ? (
                                        <iframe
                                            title="Mapa destino transporte"
                                            className="h-64 w-full"
                                            loading="lazy"
                                            src={osmEmbedUrl(selectedBranch, deliveryForm.data.destination_latitude, deliveryForm.data.destination_longitude)}
                                        />
                                    ) : (
                                        <div className="flex h-64 items-center justify-center px-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                            Selecciona un destino para previsualizar el mapa.
                                        </div>
                                    )}
                                    <div className="border-t border-slate-200 p-3 text-xs text-slate-500 dark:border-slate-800">
                                        {deliveryForm.data.destination_latitude && deliveryForm.data.destination_longitude ? (
                                            <a className="font-semibold text-brand-primary" href={osmDirectionsUrl(selectedBranch, deliveryForm.data.destination_latitude, deliveryForm.data.destination_longitude)} target="_blank" rel="noreferrer">
                                                Abrir direccion en OpenStreetMap
                                            </a>
                                        ) : 'La vista usa tiles de OpenStreetMap con atribucion visible.'}
                                    </div>
                                </div>
                            </div>
                        </div>
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

                        <CatalogPanel title={editingTruck ? 'Editar vehiculo' : 'Nuevo vehiculo'} onSubmit={submitTruck} processing={truckForm.processing} buttonLabel={editingTruck ? 'Actualizar vehiculo' : 'Crear vehiculo'} onCancel={editingTruck ? () => { setEditingTruck(null); truckForm.setData(truckDefaults()); } : null}>
                            <SelectField label="Sucursal" name="truck_branch_id" value={truckForm.data.branch_id} onChange={(event) => truckForm.setData('branch_id', event.target.value)} error={truckForm.errors.branch_id}>
                                <option value="">Global</option>
                                {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                            </SelectField>
                            <FormField label="Placa" name="truck_plate" value={truckForm.data.plate} onChange={(event) => truckForm.setData('plate', event.target.value.toUpperCase())} error={truckForm.errors.plate} required />
                            <SelectField label="Tipo vehiculo" name="truck_vehicle_type" value={truckForm.data.vehicle_type} onChange={(event) => truckForm.setData('vehicle_type', event.target.value)} error={truckForm.errors.vehicle_type}>
                                <option value="motorcycle">Moto</option>
                                <option value="car">Auto</option>
                                <option value="pickup">Camioneta</option>
                                <option value="truck">Camion</option>
                                <option value="other">Otro</option>
                            </SelectField>
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
                                    <span className="block text-xs text-slate-500">{vehicleTypeLabel(truck.vehicle_type)} · {truck.description ?? 'Sin descripcion'} {truck.branch_id ? '- Sucursal' : '- Global'}</span>
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
                                <th className="px-4 py-3 font-medium">Ruta</th>
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
                                        {delivery.route_distance_meters ? (
                                            <>
                                                <p className="font-semibold text-emerald-700 dark:text-emerald-300">{formatKm(delivery.route_distance_meters)} km</p>
                                                <p className="text-xs text-slate-500">{formatMinutes(delivery.route_duration_seconds)} min · {delivery.route_provider ?? 'ruta'}</p>
                                                <p className="max-w-56 truncate text-xs text-slate-500">{delivery.destination_address ?? 'Destino con coordenadas'}</p>
                                            </>
                                        ) : (
                                            <p className="text-xs text-slate-500">Sin ruta calculada</p>
                                        )}
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
                                    <td className="px-4 py-6 text-center text-slate-500" colSpan="8">
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
        vehicle_type: 'truck',
        description: '',
        brand: '',
        model: '',
        capacity: '',
        is_active: true,
    };
}

function firstError(errors) {
    if (!errors) {
        return null;
    }

    const first = Object.values(errors)[0];

    return Array.isArray(first) ? first[0] : first;
}

function vehicleTypeLabel(type) {
    return {
        motorcycle: 'Moto',
        car: 'Auto',
        pickup: 'Camioneta',
        truck: 'Camion',
        other: 'Otro',
    }[type] ?? 'Vehiculo';
}

function osmEmbedUrl(branch, destinationLatitude, destinationLongitude) {
    const destination = {
        latitude: Number(destinationLatitude),
        longitude: Number(destinationLongitude),
    };
    const origin = branch?.latitude && branch?.longitude
        ? { latitude: Number(branch.latitude), longitude: Number(branch.longitude) }
        : destination;
    const minLon = Math.min(origin.longitude, destination.longitude) - 0.01;
    const minLat = Math.min(origin.latitude, destination.latitude) - 0.01;
    const maxLon = Math.max(origin.longitude, destination.longitude) + 0.01;
    const maxLat = Math.max(origin.latitude, destination.latitude) + 0.01;

    return `https://www.openstreetmap.org/export/embed.html?bbox=${minLon}%2C${minLat}%2C${maxLon}%2C${maxLat}&layer=mapnik&marker=${destination.latitude}%2C${destination.longitude}`;
}

function osmDirectionsUrl(branch, destinationLatitude, destinationLongitude) {
    if (!branch?.latitude || !branch?.longitude) {
        return `https://www.openstreetmap.org/?mlat=${destinationLatitude}&mlon=${destinationLongitude}#map=17/${destinationLatitude}/${destinationLongitude}`;
    }

    return `https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${branch.latitude}%2C${branch.longitude}%3B${destinationLatitude}%2C${destinationLongitude}`;
}

function formatKm(meters) {
    return (Number(meters || 0) / 1000).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatMinutes(seconds) {
    return Math.ceil(Number(seconds || 0) / 60).toLocaleString('es-BO');
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
