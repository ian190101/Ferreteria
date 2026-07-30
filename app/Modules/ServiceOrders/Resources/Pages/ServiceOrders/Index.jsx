import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '../../../../Shared/Resources/Components/Pagination';
import { Head, router, useForm } from '@inertiajs/react';

export default function Index({
    branches = [],
    selectedBranchId,
    policy = {},
    orders,
    statuses = {},
    workers = [],
    customers = [],
    materials = [],
    filters = {},
}) {
    const form = useForm({
        branch_id: selectedBranchId ?? branches[0]?.id ?? '',
        customer_id: '',
        worker_id: '',
        customer_name: '',
        customer_phone: '',
        title: '',
        service_type: policy.mode === 'technical' ? 'Servicio tecnico' : 'Servicio',
        scheduled_at: '',
        labor_amount: 0,
        advance_amount: 0,
        diagnosis: '',
        work_performed: '',
        warranty_terms: '',
        notes: '',
        items: [],
    });

    const filterForm = useForm({
        branch_id: filters.branch_id ?? selectedBranchId ?? '',
        status: filters.status ?? '',
        search: filters.search ?? '',
        per_page: filters.per_page ?? 12,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('service-orders.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('customer_id', 'worker_id', 'customer_name', 'customer_phone', 'title', 'scheduled_at', 'labor_amount', 'advance_amount', 'diagnosis', 'work_performed', 'warranty_terms', 'notes', 'items'),
        });
    };

    const updateStatus = (order, status) => {
        router.patch(route('service-orders.status', order.id), { status }, { preserveScroll: true });
    };

    const selectedMaterialsTotal = form.data.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.unit_price || 0)), 0);
    const total = selectedMaterialsTotal + Number(form.data.labor_amount || 0);
    const balance = Math.max(total - Number(form.data.advance_amount || 0), 0);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Ordenes de servicio</h2>}>
            <Head title="Ordenes de servicio" />

            <section className="py-8">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Servicios</p>
                        <h1 className="text-3xl font-black text-slate-950 dark:text-white">Ordenes de servicio</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                            Gestiona trabajos tecnicos o profesionales con responsable, diagnostico, materiales usados, anticipo, garantia y estados.
                        </p>
                    </div>

                    <div className="mb-6 grid gap-3 md:grid-cols-4">
                        <CapabilityCard label="Servicios" enabled={policy.servicesEnabled} help="Permite vender servicios o mano de obra." />
                        <CapabilityCard label="Ordenes" enabled={policy.ordersEnabled} help="Activa diagnostico, tecnico, materiales y cierre." />
                        <CapabilityCard label="Tecnico requerido" enabled={policy.technicianRequired} help="Obliga asignar responsable." />
                        <CapabilityCard label="Garantia/firma" enabled={policy.warrantyEnabled || policy.signatureEnabled} help="Activa datos de cierre mas completos." />
                    </div>

                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                        <Panel title="Nueva orden" help="Si el servicio usa materiales, se descontaran del inventario de la sucursal al registrar la orden.">
                            <form onSubmit={submit} className="space-y-5">
                                <div className="grid gap-4 md:grid-cols-3">
                                    <SelectField label="Sucursal" value={form.data.branch_id} onChange={(value) => form.setData('branch_id', value)} error={form.errors.branch_id}>
                                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </SelectField>
                                    <SelectField label="Cliente registrado" value={form.data.customer_id} onChange={(value) => form.setData('customer_id', value)} error={form.errors.customer_id}>
                                        <option value="">Cliente manual o sin cliente</option>
                                        {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} {customer.phone ? `- ${customer.phone}` : ''}</option>)}
                                    </SelectField>
                                    <SelectField label="Tecnico/responsable" value={form.data.worker_id} onChange={(value) => form.setData('worker_id', value)} error={form.errors.worker_id}>
                                        <option value="">{policy.technicianRequired ? 'Seleccione responsable' : 'Sin responsable'}</option>
                                        {workers.map((worker) => <option key={worker.id} value={worker.id}>{worker.name} {worker.position ? `- ${worker.position}` : ''}</option>)}
                                    </SelectField>
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label="Cliente manual" value={form.data.customer_name} onChange={(value) => form.setData('customer_name', value)} error={form.errors.customer_name} placeholder="Nombre si no esta registrado" />
                                    <Field label="Telefono/contacto" value={form.data.customer_phone} onChange={(value) => form.setData('customer_phone', value)} error={form.errors.customer_phone} />
                                    <Field label="Titulo del trabajo" value={form.data.title} onChange={(value) => form.setData('title', value)} error={form.errors.title} required />
                                    <Field label="Tipo de servicio" value={form.data.service_type} onChange={(value) => form.setData('service_type', value)} error={form.errors.service_type} />
                                    <Field label="Fecha programada" type="datetime-local" value={form.data.scheduled_at} onChange={(value) => form.setData('scheduled_at', value)} error={form.errors.scheduled_at} />
                                    <Field label="Mano de obra Bs" type="number" step="0.1" value={form.data.labor_amount} onChange={(value) => form.setData('labor_amount', value)} error={form.errors.labor_amount} />
                                    <Field label="Anticipo Bs" type="number" step="0.1" value={form.data.advance_amount} onChange={(value) => form.setData('advance_amount', value)} error={form.errors.advance_amount} />
                                </div>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field label="Diagnostico" textarea value={form.data.diagnosis} onChange={(value) => form.setData('diagnosis', value)} error={form.errors.diagnosis} />
                                    <Field label="Trabajo realizado" textarea value={form.data.work_performed} onChange={(value) => form.setData('work_performed', value)} error={form.errors.work_performed} />
                                </div>

                                {policy.warrantyEnabled ? <Field label="Terminos de garantia" textarea value={form.data.warranty_terms} onChange={(value) => form.setData('warranty_terms', value)} error={form.errors.warranty_terms} /> : null}

                                <div className="rounded-3xl border border-slate-200 p-4 dark:border-slate-800">
                                    <div className="mb-3 flex items-center justify-between gap-3">
                                        <div>
                                            <h3 className="font-bold text-slate-950 dark:text-white">Materiales usados</h3>
                                            <p className="text-xs text-slate-500">Opcional. Si se marca descuento, afecta el stock de la sucursal.</p>
                                        </div>
                                        <button type="button" onClick={() => form.setData('items', [...form.data.items, emptyItem(materials[0])])} className="rounded-full border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">Agregar material</button>
                                    </div>
                                    <div className="space-y-3">
                                        {form.data.items.length === 0 ? <MutedState text="Sin materiales. La orden puede ser solo mano de obra." /> : form.data.items.map((item, index) => {
                                            const material = materials.find((candidate) => Number(candidate.id) === Number(item.product_id));
                                            return (
                                                <div key={index} className="grid gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-950 md:grid-cols-[1.5fr_110px_110px_110px_auto]">
                                                    <SelectField label="Material" value={item.product_id} onChange={(value) => updateItem(form, index, 'product_id', value, materials)}>
                                                        {materials.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                                                    </SelectField>
                                                    <Field label="Cant." type="number" step="0.001" value={item.quantity} onChange={(value) => updateItem(form, index, 'quantity', value)} />
                                                    <Field label="Unidad" value={item.unit_label ?? material?.base_unit ?? ''} onChange={(value) => updateItem(form, index, 'unit_label', value)} />
                                                    <Field label="Precio" type="number" step="0.1" value={item.unit_price} onChange={(value) => updateItem(form, index, 'unit_price', value)} />
                                                    <label className="flex items-center gap-2 self-end rounded-2xl border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                                                        <input type="checkbox" checked={Boolean(item.discount_inventory)} onChange={(event) => updateItem(form, index, 'discount_inventory', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-brand-primary" />
                                                        Stock
                                                    </label>
                                                    <button type="button" onClick={() => removeItem(form, index)} className="rounded-full border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 md:col-span-5">Quitar</button>
                                                </div>
                                            );
                                        })}
                                        {form.errors.items ? <p className="text-sm font-semibold text-red-600">{form.errors.items}</p> : null}
                                    </div>
                                </div>

                                <div className="rounded-3xl bg-slate-950 p-4 text-white dark:bg-slate-800">
                                    <div className="grid gap-3 text-sm md:grid-cols-3">
                                        <Metric label="Materiales" value={`Bs ${money(selectedMaterialsTotal)}`} />
                                        <Metric label="Total" value={`Bs ${money(total)}`} />
                                        <Metric label="Saldo" value={`Bs ${money(balance)}`} />
                                    </div>
                                </div>

                                <button className="w-full rounded-full bg-brand-primary px-5 py-3 text-sm font-black text-white shadow-lg shadow-brand-primary/20" disabled={form.processing}>
                                    Registrar orden
                                </button>
                            </form>
                        </Panel>

                        <Panel title="Filtros" help="La lista respeta sucursal, perfil de negocio y permisos.">
                            <form onSubmit={(event) => { event.preventDefault(); filterForm.get(route('service-orders.index'), { preserveScroll: true, preserveState: true }); }} className="space-y-4">
                                <SelectField label="Sucursal" value={filterForm.data.branch_id} onChange={(value) => filterForm.setData('branch_id', value)}>
                                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                </SelectField>
                                <SelectField label="Estado" value={filterForm.data.status} onChange={(value) => filterForm.setData('status', value)}>
                                    <option value="">Todos</option>
                                    {Object.entries(statuses).map(([code, label]) => <option key={code} value={code}>{label}</option>)}
                                </SelectField>
                                <Field label="Busqueda" value={filterForm.data.search} onChange={(value) => filterForm.setData('search', value)} placeholder="Orden, cliente o trabajo" />
                                <button className="w-full rounded-full bg-brand-primary px-5 py-3 text-sm font-black text-white">Filtrar</button>
                            </form>
                        </Panel>
                    </div>

                    <div className="mt-6">
                        <Panel title="Ordenes registradas" help="Cambia estados desde aqui. Si hay estados personalizados activos, el backend valida sus transiciones.">
                            {orders.data.length === 0 ? <MutedState text="No hay ordenes de servicio para estos filtros." /> : (
                                <div className="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                                    <table className="min-w-[980px] w-full text-left text-sm">
                                        <thead className="bg-slate-100 text-xs uppercase tracking-[0.14em] text-slate-500 dark:bg-slate-950">
                                            <tr>
                                                <th className="px-4 py-3">Orden</th>
                                                <th className="px-4 py-3">Cliente</th>
                                                <th className="px-4 py-3">Trabajo</th>
                                                <th className="px-4 py-3">Tecnico</th>
                                                <th className="px-4 py-3">Estado</th>
                                                <th className="px-4 py-3 text-right">Total</th>
                                                <th className="px-4 py-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                            {orders.data.map((order) => (
                                                <tr key={order.id}>
                                                    <td className="px-4 py-3 font-bold">{order.order_number}<br /><span className="text-xs font-normal text-slate-500">{formatDate(order.scheduled_at)}</span></td>
                                                    <td className="px-4 py-3">{order.customer?.name ?? order.customer_name ?? '-'}<br /><span className="text-xs text-slate-500">{order.customer?.phone ?? order.customer_phone ?? ''}</span></td>
                                                    <td className="px-4 py-3">{order.title}<br /><span className="text-xs text-slate-500">{order.items?.length ?? 0} materiales</span></td>
                                                    <td className="px-4 py-3">{order.worker?.name ?? '-'}</td>
                                                    <td className="px-4 py-3"><StatusBadge label={statuses[order.status] ?? order.status} /></td>
                                                    <td className="px-4 py-3 text-right font-bold">Bs {money(order.total_amount)}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-wrap gap-2">
                                                            {Object.entries(statuses).map(([code, label]) => (
                                                                <button key={code} type="button" onClick={() => updateStatus(order, code)} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">{label}</button>
                                                            ))}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <div className="px-4 py-3"><Pagination links={orders.links} /></div>
                                </div>
                            )}
                        </Panel>
                    </div>
                </div>
            </section>
        </AuthenticatedLayout>
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

function emptyItem(material) {
    return {
        product_id: material?.id ?? '',
        quantity: 1,
        unit_label: material?.base_unit ?? '',
        unit_cost: material?.purchase_price ?? 0,
        unit_price: material?.sale_price ?? 0,
        discount_inventory: true,
        notes: '',
    };
}

function updateItem(form, index, field, value, materials = []) {
    form.setData('items', form.data.items.map((item, itemIndex) => {
        if (itemIndex !== index) {
            return item;
        }

        if (field === 'product_id') {
            const material = materials.find((candidate) => Number(candidate.id) === Number(value));
            return {
                ...item,
                product_id: value,
                unit_label: material?.base_unit ?? item.unit_label,
                unit_cost: material?.purchase_price ?? item.unit_cost,
                unit_price: material?.sale_price ?? item.unit_price,
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
        return 'Sin programar';
    }

    return new Date(value).toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
}
