import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Show({ channel, settings, menu = [], orderStatus = null }) {
    const { props } = usePage();
    const flashSuccess = props?.flash?.success;
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const defaultType = channel.allowed_order_types?.[0] ?? channel.service_mode ?? 'pickup';
    const form = useForm({
        order_type: defaultType,
        customer_name: '',
        customer_phone: '',
        table_or_reference: channel.context_reference ?? '',
        notes: '',
        items: [],
    });

    const selectedMap = useMemo(
        () => new Map(form.data.items.map((item) => [Number(item.product_id), item])),
        [form.data.items],
    );

    const categories = useMemo(() => {
        return ['all', ...new Set(menu.map((product) => product.category).filter(Boolean))];
    }, [menu]);

    const filteredMenu = useMemo(() => {
        const term = search.trim().toLowerCase();

        return menu.filter((product) => {
            const matchesCategory = category === 'all' || product.category === category;
            const haystack = `${product.name ?? ''} ${product.sku ?? ''} ${product.barcode ?? ''}`.toLowerCase();

            return matchesCategory && (term === '' || haystack.includes(term));
        });
    }, [category, menu, search]);

    const total = useMemo(() => {
        return form.data.items.reduce((sum, item) => {
            const product = menu.find((row) => Number(row.id) === Number(item.product_id));
            return sum + Number(item.quantity || 0) * Number(product?.sale_price || 0);
        }, 0);
    }, [form.data.items, menu]);

    const updateQuantity = (product, rawValue) => {
        const quantity = Number(rawValue);
        const currentItems = form.data.items.filter((item) => Number(item.product_id) !== Number(product.id));

        if (!Number.isFinite(quantity) || quantity <= 0) {
            form.setData('items', currentItems);
            return;
        }

        form.setData('items', [
            ...currentItems,
            { product_id: product.id, quantity: Math.min(quantity, Number(settings.max_quantity_per_item ?? 999)), notes: '' },
        ]);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(window.location.pathname, {
            preserveScroll: true,
            onSuccess: () => form.reset('items', 'notes'),
        });
    };

    return (
        <main className="min-h-screen bg-slate-100 text-slate-950">
            <Head title={`Pedido QR - ${channel.name}`} />

            <section className="mx-auto flex min-h-screen max-w-4xl flex-col px-4 py-6 sm:px-6 lg:px-8">
                <header className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Pedido desde QR</p>
                    <h1 className="mt-2 text-2xl font-black tracking-tight">{channel.name}</h1>
                    <p className="mt-2 text-sm text-slate-600">
                        {channel.branch?.name ? `${channel.branch.name}. ` : ''}
                        Revisa los items y envia tu pedido para que el negocio lo confirme.
                    </p>
                </header>

                {flashSuccess ? (
                    <div className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
                        {flashSuccess}
                    </div>
                ) : null}

                {orderStatus ? (
                    <section className="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        <p className="font-black">Estado del pedido {orderStatus.public_code}</p>
                        <p className="mt-1">Estado actual: <span className="font-bold">{orderStatus.label}</span>. Total: {orderStatus.total}.</p>
                        {orderStatus.updated_at ? <p className="mt-1 text-blue-700">Ultima actualizacion: {orderStatus.updated_at}</p> : null}
                    </section>
                ) : null}

                <form onSubmit={submit} className="mt-4 grid flex-1 gap-4 lg:grid-cols-[1fr_20rem]">
                    <div className="space-y-3">
                        <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[1fr_12rem]">
                            <label className="block text-sm font-semibold text-slate-700">
                                Buscar producto o servicio
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    className="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3 outline-none ring-brand-primary/20 focus:border-brand-primary focus:ring-4"
                                    placeholder="Nombre, SKU o codigo"
                                />
                            </label>
                            <label className="block text-sm font-semibold text-slate-700">
                                Categoria
                                <select value={category} onChange={(event) => setCategory(event.target.value)} className="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3">
                                    {categories.map((item) => (
                                        <option key={item} value={item}>{item === 'all' ? 'Todas' : item}</option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        {menu.length === 0 ? (
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                                No hay productos o servicios disponibles para este QR.
                            </div>
                        ) : filteredMenu.length === 0 ? (
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                                No hay resultados con esos filtros.
                            </div>
                        ) : filteredMenu.map((product) => {
                            const selected = selectedMap.get(Number(product.id));

                            return (
                                <article key={product.id} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[5rem_1fr_7rem]">
                                    <div className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-xl bg-slate-100 text-xs font-bold uppercase text-slate-400">
                                        {product.image_url ? (
                                            <img src={product.image_url} alt={product.name} className="h-full w-full object-cover" loading="lazy" />
                                        ) : (
                                            product.item_type === 'service' ? 'Serv.' : 'Prod.'
                                        )}
                                    </div>
                                    <div className="min-w-0">
                                        <h2 className="font-bold">{product.name}</h2>
                                        <p className="mt-1 text-sm text-slate-500">
                                            {product.item_type === 'service' ? 'Servicio' : 'Producto'}{product.base_unit ? ` / ${product.base_unit}` : ''}
                                        </p>
                                        {product.category ? <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{product.category}</p> : null}
                                        <p className="mt-3 text-lg font-black text-emerald-700">{product.display_price}</p>
                                    </div>
                                    <label className="flex flex-col gap-2 text-sm font-semibold text-slate-700">
                                        Cantidad
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.001"
                                            value={selected?.quantity ?? ''}
                                            onChange={(event) => updateQuantity(product, event.target.value)}
                                            className="h-11 rounded-xl border border-slate-300 px-3 text-base font-bold outline-none ring-brand-primary/20 focus:border-brand-primary focus:ring-4"
                                            placeholder="0"
                                        />
                                    </label>
                                </article>
                            );
                        })}
                    </div>

                    <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-black">Datos del pedido</h2>
                        <div className="mt-4 space-y-4">
                            <label className="block text-sm font-semibold text-slate-700">
                                Tipo de pedido
                                <select value={form.data.order_type} onChange={(event) => form.setData('order_type', event.target.value)} className="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3">
                                    {(channel.allowed_order_types ?? []).map((type) => (
                                        <option key={type} value={type}>{typeLabel(type)}</option>
                                    ))}
                                </select>
                            </label>
                            <Input label="Nombre" required={channel.requires_customer_name || settings.requires_customer_name} value={form.data.customer_name} error={form.errors.customer_name} onChange={(value) => form.setData('customer_name', value)} />
                            <Input label="Telefono" required={channel.requires_customer_phone || settings.requires_customer_phone} value={form.data.customer_phone} error={form.errors.customer_phone} onChange={(value) => form.setData('customer_phone', value)} />
                            <Input label="Mesa o referencia" required={channel.requires_table_or_reference} value={form.data.table_or_reference} error={form.errors.table_or_reference} onChange={(value) => form.setData('table_or_reference', value)} />
                            <label className="block text-sm font-semibold text-slate-700">
                                Nota
                                <textarea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} rows="3" className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" />
                            </label>
                        </div>

                        {form.errors.items ? <p className="mt-3 text-sm font-semibold text-rose-600">{form.errors.items}</p> : null}

                        <div className="mt-5 border-t border-slate-200 pt-4">
                            <div className="flex items-center justify-between text-sm text-slate-600">
                                <span>Items</span>
                                <span>{form.data.items.length}</span>
                            </div>
                            {form.data.items.length > 0 ? (
                                <div className="mt-3 max-h-48 space-y-2 overflow-y-auto pr-1">
                                    {form.data.items.map((item) => {
                                        const product = menu.find((row) => Number(row.id) === Number(item.product_id));

                                        return (
                                            <div key={item.product_id} className="flex items-start justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-xs">
                                                <span className="min-w-0 truncate font-semibold text-slate-700">{product?.name ?? 'Item'}</span>
                                                <span className="shrink-0 text-slate-500">x {Number(item.quantity).toLocaleString('es-BO')}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : null}
                            <div className="mt-2 flex items-center justify-between text-xl font-black">
                                <span>Total estimado</span>
                                <span>Bs {total.toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                            </div>
                            <button type="submit" disabled={form.processing} className="mt-4 h-12 w-full rounded-xl bg-brand-primary px-4 text-sm font-black text-white shadow-lg shadow-brand-primary/20 disabled:cursor-not-allowed disabled:opacity-60">
                                {form.processing ? 'Enviando...' : 'Enviar pedido'}
                            </button>
                        </div>
                    </aside>
                </form>
            </section>
        </main>
    );
}

function Input({ label, value, onChange, required, error }) {
    return (
        <label className="block text-sm font-semibold text-slate-700">
            {label}{required ? ' *' : ''}
            <input value={value} onChange={(event) => onChange(event.target.value)} required={required} className="mt-1 h-11 w-full rounded-xl border border-slate-300 px-3" />
            {error ? <span className="mt-1 block text-xs text-rose-600">{error}</span> : null}
        </label>
    );
}

function typeLabel(type) {
    return {
        pickup: 'Retiro',
        table: 'Mesa',
        delivery: 'Delivery',
        reservation: 'Reserva',
        quote_request: 'Cotizacion',
    }[type] ?? type;
}
