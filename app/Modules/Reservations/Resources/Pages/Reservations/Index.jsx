import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({
    branches = [],
    selectedBranchId,
    policy = {},
    reservations,
    statuses = {},
    resources = [],
    workers = [],
    customers = [],
    products = [],
    filters = {},
}) {
    const form = useForm({
        branch_id: selectedBranchId ?? branches[0]?.id ?? '',
        type: policy.rentalsEnabled ? 'rental' : 'reservation',
        reservable_resource_id: '',
        customer_id: '',
        worker_id: '',
        channel: 'mostrador',
        customer_name: '',
        customer_phone: '',
        title: '',
        start_at: '',
        end_at: '',
        amount: 0,
        advance_amount: 0,
        deposit_amount: 0,
        penalty_amount: 0,
        condition_before: '',
        condition_after: '',
        notes: '',
        items: [],
    });

    const filterForm = useForm({
        branch_id: filters.branch_id ?? selectedBranchId ?? '',
        type: filters.type ?? '',
        status: filters.status ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 12,
    });

    const selectedType = form.data.type;
    const resourceRequired = selectedType === 'rental' ? policy.rentalResourceRequired : policy.reservationResourceRequired;
    const itemsTotal = form.data.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.unit_price || 0)), 0);
    const total = Number(form.data.amount || 0) + Number(form.data.deposit_amount || 0) + Number(form.data.penalty_amount || 0) + itemsTotal;
    const balance = Math.max(total - Number(form.data.advance_amount || 0), 0);

    const submit = (event) => {
        event.preventDefault();
        form.post(route('reservations.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('reservable_resource_id', 'customer_id', 'worker_id', 'customer_name', 'customer_phone', 'title', 'start_at', 'end_at', 'amount', 'advance_amount', 'deposit_amount', 'penalty_amount', 'condition_before', 'condition_after', 'notes', 'items'),
        });
    };

    const updateStatus = (reservation, status) => {
        router.patch(route('reservations.status', reservation.id), { status }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Reservas y alquileres</h2>}>
            <Head title="Reservas y alquileres" />

            <section className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Agenda y recursos</p>
                        <h1 className="text-3xl font-black text-slate-950 dark:text-white">Reservas y alquileres</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                            Reserva mesas, equipos, salones, tecnicos o productos alquilables con control de horarios, anticipos, garantias y devoluciones.
                        </p>
                    </div>

                    <div className="mb-6 grid gap-3 md:grid-cols-4">
                        <CapabilityCard label="Reservas" enabled={policy.reservationsEnabled} help="Permite agendar recursos o servicios." />
                        <CapabilityCard label="Alquileres" enabled={policy.rentalsEnabled} help="Controla entrega, garantia y devolucion." />
                        <CapabilityCard label="Cliente requerido" enabled={policy.customerRequired} help="Obliga identificar al cliente." />
                        <CapabilityCard label="Garantia/penalidad" enabled={policy.depositRequired || policy.penaltyEnabled} help="Aplica a alquileres si el perfil lo exige." />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                        <Panel title="Nueva reserva o alquiler" help="El sistema bloquea automaticamente horarios solapados para el mismo recurso.">
                            <form onSubmit={submit} className="space-y-5">
                                <div className="grid gap-4 md:grid-cols-3">
                                    <SelectField label="Sucursal" value={form.data.branch_id} onChange={(value) => form.setData('branch_id', value)} error={form.errors.branch_id}>
                                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </SelectField>
                                    <SelectField label="Tipo" value={form.data.type} onChange={(value) => form.setData('type', value)} error={form.errors.type}>
                                        <option value="reservation">Reserva</option>
                                        {policy.rentalsEnabled ? <option value="rental">Alquiler</option> : null}
                                    </SelectField>
                                    <SelectField label={resourceRequired ? 'Recurso requerido' : 'Recurso opcional'} value={form.data.reservable_resource_id} onChange={(value) => form.setData('reservable_resource_id', value)} error={form.errors.reservable_resource_id}>
                                        <option value="">Sin recurso</option>
                                        {resources.map((resource) => <option key={resource.id} value={resource.id}>{resource.name} - {resource.type} {resource.capacity ? `(${resource.capacity})` : ''}</option>)}
                                    </SelectField>
                                </div>

                                <div className="grid gap-4 md:grid-cols-3">
                                    <SelectField label="Cliente registrado" value={form.data.customer_id} onChange={(value) => form.setData('customer_id', value)} error={form.errors.customer_id}>
                                        <option value="">Cliente manual o sin cliente</option>
                                        {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} {customer.phone ? `- ${customer.phone}` : ''}</option>)}
                                    </SelectField>
                                    <SelectField label="Responsable" value={form.data.worker_id} onChange={(value) => form.setData('worker_id', value)} error={form.errors.worker_id}>
                                        <option value="">Sin responsable</option>
                                        {workers.map((worker) => <option key={worker.id} value={worker.id}>{worker.name} {worker.position ? `- ${worker.position}` : ''}</option>)}
                                    </SelectField>
                                    <Field label="Canal" value={form.data.channel} onChange={(value) => form.setData('channel', value)} error={form.errors.channel} placeholder="mostrador, mesa, delivery, telefono" />
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label="Cliente manual" value={form.data.customer_name} onChange={(value) => form.setData('customer_name', value)} error={form.errors.customer_name} />
                                    <Field label="Telefono/contacto" value={form.data.customer_phone} onChange={(value) => form.setData('customer_phone', value)} error={form.errors.customer_phone} />
                                    <Field label="Titulo" value={form.data.title} onChange={(value) => form.setData('title', value)} error={form.errors.title} required />
                                    <Field label="Inicio" type="datetime-local" value={form.data.start_at} onChange={(value) => form.setData('start_at', value)} error={form.errors.start_at} required />
                                    <Field label={selectedType === 'rental' ? 'Fin / devolucion prevista' : 'Fin'} type="datetime-local" value={form.data.end_at} onChange={(value) => form.setData('end_at', value)} error={form.errors.end_at} required />
                                    <Field label="Monto base Bs" type="number" step="0.1" value={form.data.amount} onChange={(value) => form.setData('amount', value)} error={form.errors.amount} />
                                    {policy.advanceEnabled ? <Field label="Anticipo Bs" type="number" step="0.1" value={form.data.advance_amount} onChange={(value) => form.setData('advance_amount', value)} error={form.errors.advance_amount} /> : null}
                                    {selectedType === 'rental' ? <Field label={policy.depositRequired ? 'Garantia requerida Bs' : 'Garantia Bs'} type="number" step="0.1" value={form.data.deposit_amount} onChange={(value) => form.setData('deposit_amount', value)} error={form.errors.deposit_amount} /> : null}
                                    {selectedType === 'rental' && policy.penaltyEnabled ? <Field label="Penalidad Bs" type="number" step="0.1" value={form.data.penalty_amount} onChange={(value) => form.setData('penalty_amount', value)} error={form.errors.penalty_amount} /> : null}
                                </div>

                                {selectedType === 'rental' ? (
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Field label="Estado antes de entregar" textarea value={form.data.condition_before} onChange={(value) => form.setData('condition_before', value)} error={form.errors.condition_before} placeholder="Ej. equipo funcionando, accesorios completos" />
                                        <Field label="Estado al devolver" textarea value={form.data.condition_after} onChange={(value) => form.setData('condition_after', value)} error={form.errors.condition_after} placeholder="Se puede completar al finalizar" />
                                    </div>
                                ) : null}

                                <ReservedItems products={products} form={form} />

                                <Field label="Notas" textarea value={form.data.notes} onChange={(value) => form.setData('notes', value)} error={form.errors.notes} />

                                <div className="rounded-3xl bg-slate-950 p-4 text-white dark:bg-slate-800">
                                    <div className="grid gap-3 text-sm md:grid-cols-4">
                                        <Metric label="Items" value={`Bs ${money(itemsTotal)}`} />
                                        <Metric label="Total" value={`Bs ${money(total)}`} />
                                        <Metric label="Anticipo" value={`Bs ${money(form.data.advance_amount)}`} />
                                        <Metric label="Saldo" value={`Bs ${money(balance)}`} />
                                    </div>
                                </div>

                                <button className="w-full rounded-full bg-brand-primary px-5 py-3 text-sm font-black text-white shadow-lg shadow-brand-primary/20" disabled={form.processing}>
                                    Guardar {selectedType === 'rental' ? 'alquiler' : 'reserva'}
                                </button>
                            </form>
                        </Panel>

                        <Panel title="Filtros" help="Filtra por sucursal, tipo, estado o cliente/recurso.">
                            <form onSubmit={(event) => { event.preventDefault(); filterForm.get(route('reservations.index'), { preserveScroll: true, preserveState: true }); }} className="space-y-4">
                                <SelectField label="Sucursal" value={filterForm.data.branch_id} onChange={(value) => filterForm.setData('branch_id', value)}>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <SelectField label="Tipo" value={filterForm.data.type} onChange={(value) => filterForm.setData('type', value)}>
                                    <option value="">Todos</option>
                                    <option value="reservation">Reservas</option>
                                    <option value="rental">Alquileres</option>
                                </SelectField>
                                <SelectField label="Estado" value={filterForm.data.status} onChange={(value) => filterForm.setData('status', value)}>
                                    <option value="">Todos</option>
                                    {Object.entries(statuses).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                                </SelectField>
                                <Field label="Busqueda" value={filterForm.data.search} onChange={(value) => filterForm.setData('search', value)} placeholder="Cliente, recurso o numero" />
                                <button className="w-full rounded-full bg-brand-primary px-5 py-3 text-sm font-black text-white">Filtrar</button>
                            </form>
                        </Panel>
                    </div>

                    <div className="mt-6">
                        <Panel title="Agenda registrada" help="Los cambios de estado pueden registrar entrega y devolucion cuando corresponde.">
                            {reservations.data.length === 0 ? <MutedState text="No hay reservas o alquileres para estos filtros." /> : (
                                <div className="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                                    <table className="min-w-[1060px] w-full text-left text-sm">
                                        <thead className="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-950">
                                            <tr>
                                                <th className="sticky left-0 z-10 bg-slate-100 px-4 py-3 dark:bg-slate-950">Numero</th>
                                                <th className="px-4 py-3">Cliente</th>
                                                <th className="px-4 py-3">Recurso</th>
                                                <th className="px-4 py-3">Horario</th>
                                                <th className="px-4 py-3">Estado</th>
                                                <th className="px-4 py-3 text-right">Total</th>
                                                <th className="px-4 py-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                            {reservations.data.map((reservation) => (
                                                <tr key={reservation.id}>
                                                    <td className="sticky left-0 z-10 bg-white px-4 py-3 font-bold dark:bg-slate-900">
                                                        {reservation.reservation_number}
                                                        <br />
                                                        <span className="text-xs font-normal text-slate-500">{reservation.type === 'rental' ? 'Alquiler' : 'Reserva'}</span>
                                                    </td>
                                                    <td className="px-4 py-3">{reservation.customer?.name ?? reservation.customer_name ?? '-'}<br /><span className="text-xs text-slate-500">{reservation.customer?.phone ?? reservation.customer_phone ?? ''}</span></td>
                                                    <td className="px-4 py-3">{reservation.resource?.name ?? '-'}<br /><span className="text-xs text-slate-500">{reservation.title}</span></td>
                                                    <td className="px-4 py-3">{formatDate(reservation.start_at)}<br /><span className="text-xs text-slate-500">hasta {formatDate(reservation.end_at)}</span></td>
                                                    <td className="px-4 py-3"><StatusBadge label={statuses[reservation.status] ?? reservation.status} /></td>
                                                    <td className="px-4 py-3 text-right font-bold">Bs {money(reservation.total_amount)}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            {Object.entries(statuses).map(([code, label]) => (
                                                                <button key={code} type="button" onClick={() => updateStatus(reservation, code)} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">{label}</button>
                                                            ))}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <div className="px-4 py-3"><Pagination links={reservations.links} /></div>
                                </div>
                            )}
                        </Panel>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function ReservedItems({ products, form }) {
    return (
        <div className="rounded-3xl border border-slate-200 p-4 dark:border-slate-800">
            <div className="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h3 className="font-bold text-slate-950 dark:text-white">Items asociados</h3>
                    <p className="text-xs text-slate-500">Opcional. Sirve para reservar productos, extras, paquetes o servicios vinculados.</p>
                </div>
                <button type="button" onClick={() => form.setData('items', [...form.data.items, emptyItem(products[0])])} className="rounded-full border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">Agregar item</button>
            </div>
            <div className="space-y-3">
                {form.data.items.length === 0 ? <MutedState text="Sin items asociados." /> : form.data.items.map((item, index) => {
                    const product = products.find((candidate) => Number(candidate.id) === Number(item.product_id));
                    return (
                        <div key={index} className="grid gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-950 md:grid-cols-[1.4fr_1.4fr_100px_100px_100px_auto]">
                            <SelectField label="Producto/servicio" value={item.product_id} onChange={(value) => updateItem(form, index, 'product_id', value, products)}>
                                <option value="">Manual</option>
                                {products.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}
                            </SelectField>
                            <Field label="Descripcion" value={item.description} onChange={(value) => updateItem(form, index, 'description', value)} />
                            <Field label="Cant." type="number" step="0.001" value={item.quantity} onChange={(value) => updateItem(form, index, 'quantity', value)} />
                            <Field label="Unidad" value={item.unit_label ?? product?.base_unit ?? ''} onChange={(value) => updateItem(form, index, 'unit_label', value)} />
                            <Field label="Precio" type="number" step="0.1" value={item.unit_price} onChange={(value) => updateItem(form, index, 'unit_price', value)} />
                            <button type="button" onClick={() => removeItem(form, index)} className="self-end rounded-full border border-red-200 px-3 py-2 text-sm font-semibold text-red-600">Quitar</button>
                        </div>
                    );
                })}
                {form.errors.items ? <p className="text-sm font-semibold text-red-600">{form.errors.items}</p> : null}
            </div>
        </div>
    );
}

function Panel({ title, help, children }) {
    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-4">
                <h2 className="text-xl font-bold text-slate-950 dark:text-white">{title}</h2>
                {help ? <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{help}</p> : null}
            </div>
            {children}
        </div>
    );
}

function CapabilityCard({ label, enabled, help }) {
    return (
        <div className={`rounded-3xl border p-4 shadow-sm ${enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100' : 'border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300'}`}>
            <p className="text-sm font-black">{label}</p>
            <p className="mt-1 text-xs opacity-75">{enabled ? 'Activo' : 'Inactivo'} - {help}</p>
        </div>
    );
}

function Field({ label, value, onChange, error, textarea = false, ...props }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</span>
            {textarea ? (
                <textarea value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 min-h-24 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-950" {...props} />
            ) : (
                <input value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-800 dark:bg-slate-950" {...props} />
            )}
            {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function SelectField({ label, value, onChange, error, children }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</span>
            <select value={value ?? ''} onChange={(event) => onChange(event.target.value)} className="mt-1 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-800 dark:bg-slate-950">
                {children}
            </select>
            {error ? <span className="mt-1 block text-xs font-semibold text-red-600">{error}</span> : null}
        </label>
    );
}

function StatusBadge({ label }) {
    return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{label}</span>;
}

function Metric({ label, value }) {
    return (
        <div>
            <p className="text-xs uppercase tracking-[0.14em] text-slate-400">{label}</p>
            <p className="text-lg font-black">{value}</p>
        </div>
    );
}

function MutedState({ text }) {
    return <div className="rounded-2xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">{text}</div>;
}

function emptyItem(product) {
    return {
        product_id: product?.id ?? '',
        description: product?.name ?? '',
        quantity: 1,
        unit_label: product?.base_unit ?? '',
        unit_price: product?.sale_price ?? 0,
        notes: '',
    };
}

function updateItem(form, index, field, value, products = []) {
    form.setData('items', form.data.items.map((item, itemIndex) => {
        if (itemIndex !== index) {
            return item;
        }

        if (field === 'product_id') {
            const product = products.find((candidate) => Number(candidate.id) === Number(value));
            return {
                ...item,
                product_id: value,
                description: product?.name ?? item.description,
                unit_label: product?.base_unit ?? item.unit_label,
                unit_price: product?.sale_price ?? item.unit_price,
            };
        }

        return { ...item, [field]: value };
    }));
}

function removeItem(form, index) {
    form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index));
}

function money(value) {
    return Number(value || 0).toFixed(1);
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
}
