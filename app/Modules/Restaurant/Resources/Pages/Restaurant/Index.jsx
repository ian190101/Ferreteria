import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

export default function Index({ branches = [], selectedBranchId, policy = {}, tables = [], orders = [], recipes = [], products = [], ingredients = [] }) {
    const tableForm = useForm({
        branch_id: selectedBranchId,
        area_name: '',
        name: '',
        code: '',
        capacity: 4,
        sort_order: 0,
    });
    const recipeForm = useForm({
        branch_id: '',
        product_id: products[0]?.id ?? '',
        name: '',
        yield_quantity: 1,
        instructions: '',
        items: [emptyRecipeItem(ingredients[0]?.id ?? '')],
    });
    const orderForm = useForm({
        branch_id: selectedBranchId,
        restaurant_table_id: '',
        customer_label: '',
        channel: policy.tablesEnabled ? 'table' : 'counter',
        preparation_area: policy.kitchenAreas?.[0] ?? '',
        notes: '',
        items: [emptyOrderItem(products[0]?.id ?? '')],
    });

    const productsById = useMemo(() => new Map(products.map((product) => [Number(product.id), product])), [products]);
    const selectedOrderTotal = orderForm.data.items.reduce((sum, item) => {
        const product = productsById.get(Number(item.product_id));
        const price = Number(item.unit_price !== '' ? item.unit_price : product?.sale_price ?? 0);

        return sum + (Number(item.quantity || 0) * price);
    }, 0);

    const changeBranch = (branchId) => {
        router.get(route('restaurant.index'), { branch_id: branchId }, { preserveScroll: true, preserveState: false });
    };

    const submitTable = (event) => {
        event.preventDefault();
        tableForm.post(route('restaurant.tables.store'), {
            preserveScroll: true,
            onSuccess: () => tableForm.reset('area_name', 'name', 'code'),
        });
    };

    const submitRecipe = (event) => {
        event.preventDefault();
        recipeForm.post(route('restaurant.recipes.store'), {
            preserveScroll: true,
            onSuccess: () => recipeForm.reset('name', 'instructions'),
        });
    };

    const submitOrder = (event) => {
        event.preventDefault();
        orderForm.post(route('restaurant.orders.store'), {
            preserveScroll: true,
            onSuccess: () => orderForm.reset('restaurant_table_id', 'customer_label', 'notes'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">Restaurante</h2>}>
            <Head title="Restaurante" />

            <section className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="mb-5 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Operacion restaurante</p>
                        <h1 className="text-3xl font-bold text-slate-950 dark:text-white">Mesas, comandas y recetas</h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                            Gestiona mesas/salones, envia comandas a cocina o barra y descuenta insumos por receta cuando el perfil lo indique.
                        </p>
                    </div>
                    <select value={selectedBranchId ?? ''} onChange={(event) => changeBranch(event.target.value)} className="h-12 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </select>
                </div>

                <div className="mb-5 grid gap-3 lg:grid-cols-4">
                    <CapabilityCard label="Mesas" enabled={policy.tablesEnabled} help="Activa salones, mesas y ocupacion por comanda." />
                    <CapabilityCard label="Comandas" enabled={policy.kitchenEnabled} help="Permite enviar pedidos a cocina/barra y cambiar estados." />
                    <CapabilityCard label="Recetas" enabled={policy.recipesEnabled} help={`Descuento de insumos: ${policy.discountInventoryAt === 'kitchen_order' ? 'al enviar comanda' : 'al cerrar venta'}.`} />
                    <CapabilityCard label="Pago dividido" enabled={policy.splitBillEnabled} help="Disponible para flujos de restaurante que separan cuentas." />
                </div>

                <div className="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
                    <Panel title="Mesas y salones" help="Si el restaurante no usa mesas, este bloque puede quedar apagado desde el perfil de negocio.">
                        {policy.tablesEnabled ? (
                            <>
                                <form onSubmit={submitTable} className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Salon / ambiente" value={tableForm.data.area_name} onChange={(value) => tableForm.setData('area_name', value)} error={tableForm.errors.area_name} placeholder="Ej. Salon principal" />
                                    <Field label="Mesa" value={tableForm.data.name} onChange={(value) => tableForm.setData('name', value)} error={tableForm.errors.name} placeholder="Ej. Mesa 1" />
                                    <Field label="Codigo" value={tableForm.data.code} onChange={(value) => tableForm.setData('code', value)} error={tableForm.errors.code} placeholder="M-01" />
                                    <Field label="Capacidad" type="number" min="1" value={tableForm.data.capacity} onChange={(value) => tableForm.setData('capacity', value)} error={tableForm.errors.capacity} />
                                    <div className="sm:col-span-2">
                                        <button disabled={tableForm.processing} className="rounded-2xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white disabled:opacity-60">Guardar mesa</button>
                                    </div>
                                </form>
                                <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                    {tables.map((table) => (
                                        <div key={table.id} className="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-bold text-slate-950 dark:text-white">{table.name}</p>
                                                    <p className="text-xs text-slate-500">{table.area_name || 'Sin salon'} - {table.capacity} personas</p>
                                                </div>
                                                <StatusBadge value={table.status} />
                                            </div>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {['available', 'occupied', 'reserved', 'inactive'].map((status) => (
                                                    <button key={status} type="button" onClick={() => router.patch(route('restaurant.tables.update', table.id), { status, is_active: status !== 'inactive' }, { preserveScroll: true })} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                                                        {statusLabel(status)}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : <MutedState text="El perfil actual no usa mesas. Puede funcionar como mostrador, comida rapida o delivery." />}
                    </Panel>

                    <Panel title="Nueva comanda" help="Una comanda puede ser de mesa, mostrador, delivery o para llevar. Si hay receta y el perfil descuenta al enviar, baja el stock de insumos al guardar.">
                        {policy.kitchenEnabled ? (
                            <form onSubmit={submitOrder} className="space-y-4">
                                <div className="grid gap-3 md:grid-cols-2">
                                    {policy.tablesEnabled ? (
                                        <SelectField label="Mesa" value={orderForm.data.restaurant_table_id} onChange={(value) => orderForm.setData('restaurant_table_id', value)} error={orderForm.errors.restaurant_table_id}>
                                            <option value="">Sin mesa / mostrador</option>
                                            {tables.filter((table) => table.is_active).map((table) => <option key={table.id} value={table.id}>{table.area_name ? `${table.area_name} - ` : ''}{table.name}</option>)}
                                        </SelectField>
                                    ) : null}
                                    <SelectField label="Canal" value={orderForm.data.channel} onChange={(value) => orderForm.setData('channel', value)} error={orderForm.errors.channel}>
                                        <option value="table">Mesa</option>
                                        <option value="counter">Mostrador</option>
                                        <option value="takeaway">Para llevar</option>
                                        <option value="delivery">Delivery</option>
                                    </SelectField>
                                    <SelectField label="Area de preparacion" value={orderForm.data.preparation_area} onChange={(value) => orderForm.setData('preparation_area', value)} error={orderForm.errors.preparation_area}>
                                        <option value="">Sin area</option>
                                        {(policy.kitchenAreas ?? []).map((area) => <option key={area} value={area}>{area}</option>)}
                                    </SelectField>
                                    <Field label="Cliente / referencia" value={orderForm.data.customer_label} onChange={(value) => orderForm.setData('customer_label', value)} error={orderForm.errors.customer_label} placeholder="Ej. Mesa Juan, pedido WhatsApp" />
                                </div>

                                <div className="space-y-3">
                                    {orderForm.data.items.map((item, index) => {
                                        const product = productsById.get(Number(item.product_id));

                                        return (
                                            <div key={index} className="grid gap-2 rounded-2xl border border-slate-200 p-3 dark:border-slate-800 md:grid-cols-[1fr_110px_130px_auto] md:items-end">
                                                <SelectField label="Producto/plato" value={item.product_id} onChange={(value) => updateOrderItem(orderForm, index, 'product_id', value)}>
                                                    {products.map((productOption) => <option key={productOption.id} value={productOption.id}>{productOption.name}</option>)}
                                                </SelectField>
                                                <Field label="Cantidad" type="number" min="0.001" step="0.001" value={item.quantity} onChange={(value) => updateOrderItem(orderForm, index, 'quantity', value)} />
                                                <Field label="Precio" type="number" min="0" step="0.1" value={item.unit_price} onChange={(value) => updateOrderItem(orderForm, index, 'unit_price', value)} placeholder={money(product?.sale_price)} />
                                                <button type="button" onClick={() => removeOrderItem(orderForm, index)} className="rounded-2xl border border-red-200 px-4 py-3 text-sm font-semibold text-red-600">Quitar</button>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <button type="button" onClick={() => orderForm.setData('items', [...orderForm.data.items, emptyOrderItem(products[0]?.id ?? '')])} className="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">Agregar item</button>
                                    <p className="text-lg font-black text-slate-950 dark:text-white">Total estimado Bs {money(selectedOrderTotal)}</p>
                                </div>
                                <Field label="Notas de comanda" value={orderForm.data.notes} onChange={(value) => orderForm.setData('notes', value)} error={orderForm.errors.notes} textarea />
                                {orderForm.errors.items ? <p className="text-sm font-semibold text-red-600">{orderForm.errors.items}</p> : null}
                                <button disabled={orderForm.processing} className="w-full rounded-2xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white disabled:opacity-60">Enviar comanda</button>
                            </form>
                        ) : <MutedState text="El perfil actual no usa comandas de cocina/barra." />}
                    </Panel>
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-[1fr_1fr]">
                    <Panel title="Recetas e insumos" help="Una receta relaciona un producto preparado con los insumos que se descuentan del inventario.">
                        {policy.recipesEnabled ? (
                            <form onSubmit={submitRecipe} className="space-y-4">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <SelectField label="Producto preparado" value={recipeForm.data.product_id} onChange={(value) => recipeForm.setData('product_id', value)} error={recipeForm.errors.product_id}>
                                        {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                                    </SelectField>
                                    <Field label="Nombre receta" value={recipeForm.data.name} onChange={(value) => recipeForm.setData('name', value)} error={recipeForm.errors.name} placeholder="Ej. Hamburguesa clasica" />
                                    <Field label="Rendimiento" type="number" min="0.001" step="0.001" value={recipeForm.data.yield_quantity} onChange={(value) => recipeForm.setData('yield_quantity', value)} error={recipeForm.errors.yield_quantity} />
                                    <SelectField label="Sucursal receta" value={recipeForm.data.branch_id} onChange={(value) => recipeForm.setData('branch_id', value)} error={recipeForm.errors.branch_id}>
                                        <option value="">Global</option>
                                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                                    </SelectField>
                                </div>
                                {recipeForm.data.items.map((item, index) => (
                                    <div key={index} className="grid gap-2 rounded-2xl border border-slate-200 p-3 dark:border-slate-800 md:grid-cols-[1fr_120px_110px_auto] md:items-end">
                                        <SelectField label="Insumo" value={item.ingredient_product_id} onChange={(value) => updateRecipeItem(recipeForm, index, 'ingredient_product_id', value)}>
                                            {ingredients.map((ingredient) => <option key={ingredient.id} value={ingredient.id}>{ingredient.name}</option>)}
                                        </SelectField>
                                        <Field label="Cantidad" type="number" min="0.001" step="0.001" value={item.quantity} onChange={(value) => updateRecipeItem(recipeForm, index, 'quantity', value)} />
                                        <Field label="Merma %" type="number" min="0" max="100" step="0.001" value={item.waste_percentage} onChange={(value) => updateRecipeItem(recipeForm, index, 'waste_percentage', value)} />
                                        <button type="button" onClick={() => removeRecipeItem(recipeForm, index)} className="rounded-2xl border border-red-200 px-4 py-3 text-sm font-semibold text-red-600">Quitar</button>
                                    </div>
                                ))}
                                <button type="button" onClick={() => recipeForm.setData('items', [...recipeForm.data.items, emptyRecipeItem(ingredients[0]?.id ?? '')])} className="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-semibold dark:border-slate-700">Agregar insumo</button>
                                <Field label="Instrucciones" value={recipeForm.data.instructions} onChange={(value) => recipeForm.setData('instructions', value)} error={recipeForm.errors.instructions} textarea />
                                <button disabled={recipeForm.processing} className="w-full rounded-2xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white disabled:opacity-60">Guardar receta</button>
                            </form>
                        ) : <MutedState text="El perfil actual no usa recetas ni descuento de insumos." />}
                    </Panel>

                    <Panel title="Comandas recientes" help="Estados operativos: enviada, preparando, lista, entregada, cerrada o cancelada.">
                        <div className="space-y-3">
                            {orders.length === 0 ? <MutedState text="No hay comandas recientes para esta sucursal." /> : null}
                            {orders.map((order) => (
                                <div key={order.id} className="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-bold text-slate-950 dark:text-white">{order.order_number}</p>
                                            <p className="text-xs text-slate-500">{order.table ? `${order.table.area_name || 'Salon'} - ${order.table.name}` : channelLabel(order.channel)} - {order.waiter?.name ?? 'Sin mesero'}</p>
                                        </div>
                                        <StatusBadge value={order.status} />
                                    </div>
                                    <div className="mt-3 text-sm text-slate-600 dark:text-slate-300">
                                        {order.items.map((item) => <p key={item.id}>{Number(item.quantity).toLocaleString('es-BO')} x {item.product?.name ?? 'Producto'} {item.notes ? `- ${item.notes}` : ''}</p>)}
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {['preparing', 'ready', 'delivered', 'closed', 'cancelled'].map((status) => (
                                            <button key={status} type="button" onClick={() => router.patch(route('restaurant.orders.status', order.id), { status }, { preserveScroll: true })} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">{statusLabel(status)}</button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>
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

function StatusBadge({ value }) {
    return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{statusLabel(value)}</span>;
}

function MutedState({ text }) {
    return <div className="rounded-2xl border border-dashed border-slate-300 p-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">{text}</div>;
}

function emptyRecipeItem(ingredientId = '') {
    return { ingredient_product_id: ingredientId, quantity: 1, unit_label: '', waste_percentage: 0 };
}

function emptyOrderItem(productId = '') {
    return { product_id: productId, quantity: 1, unit_price: '', notes: '' };
}

function updateRecipeItem(form, index, field, value) {
    form.setData('items', form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, [field]: value } : item));
}

function removeRecipeItem(form, index) {
    form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index));
}

function updateOrderItem(form, index, field, value) {
    form.setData('items', form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, [field]: value } : item));
}

function removeOrderItem(form, index) {
    form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index));
}

function money(value) {
    return Number(value || 0).toFixed(1);
}

function statusLabel(status) {
    return ({
        available: 'Disponible',
        occupied: 'Ocupada',
        reserved: 'Reservada',
        inactive: 'Inactiva',
        sent: 'Enviada',
        preparing: 'Preparando',
        ready: 'Lista',
        delivered: 'Entregada',
        closed: 'Cerrada',
        cancelled: 'Cancelada',
    })[status] ?? status ?? '-';
}

function channelLabel(channel) {
    return ({
        table: 'Mesa',
        counter: 'Mostrador',
        delivery: 'Delivery',
        takeaway: 'Para llevar',
    })[channel] ?? channel ?? '-';
}
