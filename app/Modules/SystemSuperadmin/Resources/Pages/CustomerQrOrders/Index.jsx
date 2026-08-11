import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Pagination from '../../../../Shared/Resources/Components/Pagination';

export default function Index({ orders, summary, filters, branches, channels, statuses, orderTypes, conversionTargets, can }) {
    const previousPending = useRef(summary.pending);
    const [soundEnabled, setSoundEnabled] = useState(() => localStorage.getItem('qr-order-sound') === '1');
    const filterForm = useForm({
        branch_id: filters.branch_id ?? '',
        status: filters.status ?? '',
        order_type: filters.order_type ?? '',
        channel_id: filters.channel_id ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        search: filters.search ?? '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        filterForm.get(route('customer-qr-orders.index'), { preserveScroll: true, preserveState: true });
    };

    const clearFilters = () => router.get(route('customer-qr-orders.index'), {}, { preserveScroll: true });

    useEffect(() => {
        localStorage.setItem('qr-order-sound', soundEnabled ? '1' : '0');
    }, [soundEnabled]);

    useEffect(() => {
        if (soundEnabled && summary.pending > previousPending.current) {
            playNotificationTone();
        }

        previousPending.current = summary.pending;
    }, [soundEnabled, summary.pending]);

    useEffect(() => {
        const timer = window.setInterval(() => {
            router.reload({ only: ['orders', 'summary'], preserveScroll: true });
        }, 30000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Pedidos QR</h2>}>
            <Head title="Pedidos QR" />

            <div className="px-4 py-8 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-7xl space-y-6">
                    <section>
                        <h1 className="text-3xl font-black tracking-tight text-slate-950 dark:text-white">Pedidos recibidos por QR</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-400">
                            Gestiona solicitudes entrantes, acepta, rechaza, cambia estados operativos y convierte a documentos segun el perfil activo.
                        </p>
                        <button
                            type="button"
                            onClick={() => setSoundEnabled((value) => !value)}
                            className="mt-3 rounded-full border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 transition hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200"
                        >
                            Sonido de nuevos pedidos: {soundEnabled ? 'activo' : 'inactivo'}
                        </button>
                    </section>

                    <section className="grid gap-4 md:grid-cols-4">
                        <Metric label="Pendientes" value={summary.pending} tone="amber" />
                        <Metric label="Aceptados" value={summary.accepted} tone="blue" />
                        <Metric label="Listos" value={summary.ready} tone="emerald" />
                        <Metric label="Entregados" value={summary.delivered} tone="slate" />
                    </section>

                    <form onSubmit={applyFilters} className="grid gap-3 rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-white/10 dark:bg-slate-900/70 md:grid-cols-4 xl:grid-cols-8">
                        <Select label="Sucursal" value={filterForm.data.branch_id} onChange={(value) => filterForm.setData('branch_id', value)} options={branches.map((branch) => [branch.id, branch.name])} allLabel="Todas" />
                        <Select label="Estado" value={filterForm.data.status} onChange={(value) => filterForm.setData('status', value)} options={Object.entries(statuses)} allLabel="Todos" />
                        <Select label="Canal" value={filterForm.data.channel_id} onChange={(value) => filterForm.setData('channel_id', value)} options={channels.map((channel) => [channel.id, `${channel.name} (${channel.code})`])} allLabel="Todos" />
                        <Select label="Tipo" value={filterForm.data.order_type} onChange={(value) => filterForm.setData('order_type', value)} options={orderTypes.map((type) => [type, typeLabel(type)])} allLabel="Todos" />
                        <Input label="Desde" type="date" value={filterForm.data.date_from} onChange={(value) => filterForm.setData('date_from', value)} />
                        <Input label="Hasta" type="date" value={filterForm.data.date_to} onChange={(value) => filterForm.setData('date_to', value)} />
                        <Input label="Buscar" value={filterForm.data.search} onChange={(value) => filterForm.setData('search', value)} placeholder="Codigo, cliente, telefono" />
                        <div className="flex items-end gap-2">
                            <button type="submit" className="h-11 rounded-xl bg-brand-primary px-4 text-sm font-bold text-white">Filtrar</button>
                            <button type="button" onClick={clearFilters} className="h-11 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Limpiar</button>
                        </div>
                    </form>

                    <section className="overflow-hidden rounded-2xl border border-white/70 bg-white/85 shadow-sm backdrop-blur dark:border-white/10 dark:bg-slate-900/70">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                                    <tr>
                                        <Th>Pedido</Th>
                                        <Th>Cliente</Th>
                                        <Th>Sucursal/canal</Th>
                                        <Th>Items</Th>
                                        <Th>Total</Th>
                                        <Th>Estado</Th>
                                        <Th>Acciones</Th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {orders.data.length === 0 ? (
                                        <tr>
                                            <td colSpan="7" className="px-4 py-8 text-center text-slate-500">No hay pedidos QR para los filtros seleccionados.</td>
                                        </tr>
                                    ) : orders.data.map((order) => (
                                        <OrderRow key={order.id} order={order} statuses={statuses} conversionTargets={conversionTargets} can={can} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="px-4 py-3">
                            <Pagination links={orders.links} />
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function OrderRow({ order, statuses, conversionTargets, can }) {
    const action = useForm({ target_type: 'sale' });

    const accept = () => router.post(route('customer-qr-orders.accept', order.id), {}, { preserveScroll: true });
    const reject = () => {
        const reason = window.prompt('Motivo interno del rechazo (opcional)');
        router.post(route('customer-qr-orders.reject', order.id), { internal_notes: reason ?? '' }, { preserveScroll: true });
    };
    const updateStatus = (status) => router.patch(route('customer-qr-orders.status', order.id), { status }, { preserveScroll: true });
    const convert = () => action.post(route('customer-qr-orders.convert', order.id), { preserveScroll: true });

    return (
        <tr className="align-top">
            <Td>
                <p className="font-black text-slate-950 dark:text-white">{order.public_code}</p>
                <p className="mt-1 text-xs text-slate-500">{order.submitted_at}</p>
                <p className="mt-1 text-xs font-semibold text-slate-400">{typeLabel(order.order_type)}</p>
            </Td>
            <Td>
                <p className="font-semibold">{order.customer_name || 'Sin nombre'}</p>
                <p className="text-xs text-slate-500">{order.customer_phone || 'Sin telefono'}</p>
                {order.table_or_reference ? <p className="mt-1 text-xs text-slate-500">Ref: {order.table_or_reference}</p> : null}
            </Td>
            <Td>
                <p className="font-semibold">{order.branch || 'Sin sucursal'}</p>
                <p className="text-xs text-slate-500">{order.channel || 'Canal QR'}</p>
            </Td>
            <Td>
                <div className="max-w-xs space-y-1">
                    {order.items.slice(0, 4).map((item, index) => (
                        <p key={`${item.product_id}-${index}`} className="truncate text-xs text-slate-600 dark:text-slate-300">
                            {item.quantity} {item.base_unit || 'un'} - {item.name}
                        </p>
                    ))}
                    {order.items.length > 4 ? <p className="text-xs font-semibold text-slate-500">+ {order.items.length - 4} mas</p> : null}
                </div>
            </Td>
            <Td>
                <p className="font-black text-emerald-700">{order.total_label}</p>
            </Td>
            <Td>
                <span className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    {order.status_label}
                </span>
                {order.converted_to_type ? <p className="mt-1 text-xs text-slate-500">Convertido a {order.converted_to_type} #{order.converted_to_id}</p> : null}
            </Td>
            <Td>
                <div className="flex min-w-64 flex-wrap gap-2">
                    {can.accept && order.status === 'pending' ? <Button onClick={accept}>Aceptar</Button> : null}
                    {can.reject && !['rejected', 'converted'].includes(order.status) ? <Button onClick={reject} variant="danger">Rechazar</Button> : null}
                    {can.accept && !['rejected', 'converted'].includes(order.status) ? (
                        <>
                            <Button onClick={() => updateStatus('preparing')}>Preparando</Button>
                            <Button onClick={() => updateStatus('ready')}>Listo</Button>
                            <Button onClick={() => updateStatus('delivered')}>Entregado</Button>
                        </>
                    ) : null}
                    {can.convert && !order.converted_to_type && order.status !== 'rejected' ? (
                        <div className="flex gap-2">
                            <select value={action.data.target_type} onChange={(event) => action.setData('target_type', event.target.value)} className="h-9 rounded-lg border border-slate-300 px-2 text-xs dark:border-slate-700 dark:bg-slate-950">
                                {Object.entries(conversionTargets).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </select>
                            <Button onClick={convert}>Convertir</Button>
                        </div>
                    ) : null}
                </div>
            </Td>
        </tr>
    );
}

function Metric({ label, value, tone }) {
    const tones = {
        amber: 'text-amber-700',
        blue: 'text-blue-700',
        emerald: 'text-emerald-700',
        slate: 'text-slate-700 dark:text-slate-200',
    };

    return (
        <div className="rounded-2xl border border-white/70 bg-white/80 p-4 shadow-sm dark:border-white/10 dark:bg-slate-900/70">
            <p className="text-xs font-bold uppercase tracking-wider text-slate-500">{label}</p>
            <p className={`mt-2 text-3xl font-black ${tones[tone]}`}>{value}</p>
        </div>
    );
}

function Select({ label, value, onChange, options, allLabel }) {
    return (
        <label className="block text-xs font-bold uppercase tracking-wider text-slate-500">
            {label}
            <select value={value} onChange={(event) => onChange(event.target.value)} className="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm normal-case tracking-normal text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                <option value="">{allLabel}</option>
                {options.map(([key, text]) => <option key={key} value={key}>{text}</option>)}
            </select>
        </label>
    );
}

function Input({ label, value, onChange, type = 'text', placeholder }) {
    return (
        <label className="block text-xs font-bold uppercase tracking-wider text-slate-500">
            {label}
            <input type={type} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="mt-1 h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm normal-case tracking-normal text-slate-800 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100" />
        </label>
    );
}

function Button({ children, onClick, variant = 'primary' }) {
    const classes = variant === 'danger'
        ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200'
        : 'border-slate-200 bg-white text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200';

    return <button type="button" onClick={onClick} className={`h-9 rounded-lg border px-3 text-xs font-bold transition ${classes}`}>{children}</button>;
}

function Th({ children }) {
    return <th className="px-4 py-3 text-left font-bold">{children}</th>;
}

function Td({ children }) {
    return <td className="px-4 py-4 text-slate-700 dark:text-slate-200">{children}</td>;
}

function typeLabel(type) {
    return {
        pickup: 'Retiro',
        table: 'Mesa',
        delivery: 'Delivery',
        reservation: 'Reserva',
        quote_request: 'Cotizacion',
        service: 'Servicio',
    }[type] ?? type;
}

function playNotificationTone() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.35);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.4);
    } catch {
        // El navegador puede bloquear audio automatico; el pedido queda visible aunque no suene.
    }
}
