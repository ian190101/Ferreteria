import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const storageKey = (branchId) => `pos:branch:${branchId}:cart`;
const queueKey = (branchId) => `pos:branch:${branchId}:pending-sales`;

export default function Index({ branches = [], selectedBranchId, saleTypes = [], currencies = [], paymentMethods = [], products = [], excludedTrackedProducts = 0, posPolicy = {}, documentPolicy = {} }) {
    const [query, setQuery] = useState('');
    const [cart, setCart] = useState(() => readStorage(storageKey(selectedBranchId), []));
    const [notice, setNotice] = useState('Listo para escanear.');
    const [checkoutErrors, setCheckoutErrors] = useState({});
    const [checkoutProcessing, setCheckoutProcessing] = useState(false);
    const [paymentMethodId, setPaymentMethodId] = useState(paymentMethods[0]?.id ?? '');
    const [paymentReference, setPaymentReference] = useState('');
    const [paymentAmount, setPaymentAmount] = useState('');
    const [pendingSales, setPendingSales] = useState(() => readStorage(queueKey(selectedBranchId), []));
    const [isOnline, setIsOnline] = useState(() => navigator.onLine);
    const [selectedTable, setSelectedTable] = useState('');
    const [serviceReference, setServiceReference] = useState('');
    const [assignedPerson, setAssignedPerson] = useState('');
    const offlineEnabled = posPolicy.offlineMode !== 'disabled';
    const scannerRequired = posPolicy.scannerMode === 'required';
    const modeLabel = posPolicy.modeLabel ?? 'POS dinamico';
    const manualSelectionAllowed = posPolicy.manualSelectionAllowed !== false;
    const allowsPartialBalance = posPolicy.allowsPartialBalance === true;
    const activeChannels = posPolicy.availableChannels ?? ['counter'];
    const scanner = useRef({ buffer: '', lastAt: 0 });
    const productsByBarcode = useMemo(() => {
        const map = new Map();

        products.forEach((product) => {
            if (product.barcode) {
                map.set(String(product.barcode), { product });
            }

            (product.variants ?? []).forEach((variant) => {
                if (variant.barcode) {
                    map.set(String(variant.barcode), { product, variant });
                }
            });
        });

        return map;
    }, [products]);
    const filteredProducts = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (!term) {
            return products.slice(0, 30);
        }

        return products
            .filter((product) => {
                const variantText = (product.variants ?? [])
                    .map((variant) => `${variant.name} ${variant.sku} ${variant.barcode}`)
                    .join(' ');

                return `${product.name} ${product.sku} ${product.barcode} ${variantText}`.toLowerCase().includes(term);
            })
            .slice(0, 30);
    }, [products, query]);
    const total = cart.reduce((sum, item) => sum + (Number(item.quantity) * Number(item.sale_price)), 0);
    const selectedPaymentMethod = paymentMethods.find((method) => String(method.id) === String(paymentMethodId));
    const amountToCharge = paymentAmount === '' ? total : Number(paymentAmount);

    useEffect(() => {
        setCart(readStorage(storageKey(selectedBranchId), []));
        setPendingSales(readStorage(queueKey(selectedBranchId), []));
    }, [selectedBranchId]);

    useEffect(() => {
        writeStorage(storageKey(selectedBranchId), cart);
    }, [cart, selectedBranchId]);

    useEffect(() => {
        writeStorage(queueKey(selectedBranchId), pendingSales);
    }, [pendingSales, selectedBranchId]);

    useEffect(() => {
        const online = () => {
            setIsOnline(true);
            setNotice('Conexion recuperada. Puedes enviar las ventas pendientes.');
        };
        const offline = () => {
            setIsOnline(false);
            setNotice('Sin conexion. Las ventas se guardaran como pendientes locales.');
        };

        window.addEventListener('online', online);
        window.addEventListener('offline', offline);

        return () => {
            window.removeEventListener('online', online);
            window.removeEventListener('offline', offline);
        };
    }, []);

    useEffect(() => {
        const onKeyDown = (event) => {
            const target = event.target;
            const tag = target?.tagName?.toLowerCase();

            if (tag === 'input' || tag === 'textarea' || target?.isContentEditable) {
                return;
            }

            const now = Date.now();
            const elapsed = now - scanner.current.lastAt;

            scanner.current.lastAt = now;

            if (event.key === 'Enter') {
                const code = scanner.current.buffer.trim();
                scanner.current.buffer = '';

                if (code) {
                    addByBarcode(code);
                }

                return;
            }

            if (event.key.length === 1) {
                scanner.current.buffer = elapsed <= 60 ? `${scanner.current.buffer}${event.key}` : event.key;
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [productsByBarcode]);

    const addProduct = (product, variant = null) => {
        const cartItem = normalizeCartItem(product, variant);

        setCart((current) => {
            const existing = current.find((item) => item.cart_key === cartItem.cart_key);

            if (existing) {
                return current.map((item) => item.cart_key === cartItem.cart_key ? { ...item, quantity: item.quantity + 1 } : item);
            }

            return [...current, { ...cartItem, quantity: 1 }];
        });
        setNotice(`${cartItem.name} agregado al carrito.`);
    };

    const addByBarcode = (code) => {
        const entry = productsByBarcode.get(String(code));

        if (!entry) {
            setNotice(`No se encontro un producto con el codigo ${code}.`);
            return;
        }

        addProduct(entry.product, entry.variant ?? null);
    };

    const updateQuantity = (cartKey, quantity) => {
        const nextQuantity = Math.max(0, Number(quantity) || 0);

        setCart((current) => current
            .map((item) => (item.cart_key ?? String(item.id)) === cartKey ? { ...item, quantity: nextQuantity } : item)
            .filter((item) => item.quantity > 0));
    };

    const changeBranch = (branchId) => {
        router.get(route('pos.index'), { branch_id: branchId }, { preserveState: false, preserveScroll: true });
    };

    const checkout = () => {
        if (cart.length === 0) {
            setNotice('Agrega al menos un producto antes de finalizar la venta.');
            return;
        }

        if (!saleTypes[0]?.id || !currencies[0]?.id || !paymentMethodId) {
            setNotice('Falta configurar tipo de venta, moneda o metodo de pago para poder finalizar.');
            return;
        }

        if (selectedPaymentMethod?.requires_reference && !paymentReference.trim()) {
            setNotice('Este metodo de pago requiere referencia.');
            setCheckoutErrors({ pos_payment_reference: 'Ingresa la referencia del pago antes de finalizar.' });
            return;
        }

        if (amountToCharge <= 0 || amountToCharge > total) {
            setNotice('El monto cobrado debe ser mayor a 0 y no superar el total.');
            setCheckoutErrors({ pos_payment_amount: 'El monto cobrado debe ser mayor a 0 y no superar el total.' });
            return;
        }

        if (!allowsPartialBalance && Math.abs(amountToCharge - total) > 0.01) {
            setNotice('Este perfil POS exige cobrar el total completo.');
            setCheckoutErrors({ pos_payment_amount: 'Este perfil POS exige cobrar el total completo en un solo cierre.' });
            return;
        }

        if (posPolicy.requiresResource && !selectedTable.trim()) {
            setNotice('Este modo POS requiere mesa, recurso o ambiente.');
            setCheckoutErrors({ 'pos_context.resource': 'Selecciona mesa, recurso o ambiente antes de finalizar.' });
            return;
        }

        if (posPolicy.requiresAssignedPerson && !assignedPerson.trim()) {
            setNotice('Este modo POS requiere registrar el responsable asignado.');
            setCheckoutErrors({ 'pos_context.assigned': 'Registra el responsable asignado antes de finalizar.' });
            return;
        }

        const payload = buildCheckoutPayload({
            selectedBranchId,
            saleTypeId: saleTypes[0].id,
            currencyId: currencies[0].id,
            paymentMethodId,
            amountToCharge,
            paymentReference,
            cart,
            defaultTerms: documentPolicy.termsByDocument?.ticket ?? documentPolicy.termsByDocument?.sale_note ?? documentPolicy.defaultTerms ?? '',
            context: {
                mode: posPolicy.mode ?? 'retail',
                channel: activeChannels[0] ?? 'counter',
                table: selectedTable,
                reference: serviceReference,
                assigned: assignedPerson,
            },
        });

        if (!isOnline && !offlineEnabled) {
            setNotice('No hay conexion y este perfil de negocio no permite ventas offline.');
            return;
        }

        if (!isOnline) {
            queueSale(payload);
            return;
        }

        submitSale(payload);
    };

    const queueSale = (payload) => {
        const queuedSale = {
            id: crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`,
            created_at: new Date().toISOString(),
            total,
            items_count: cart.length,
            payload,
        };

        setPendingSales((current) => [queuedSale, ...current]);
        setCart([]);
        setPaymentAmount('');
        setPaymentReference('');
        setCheckoutErrors({});
        setNotice('Venta guardada localmente. Envia la cola cuando vuelva la conexion.');
    };

    const submitSale = (payload, queuedId = null) => {
        setCheckoutErrors({});
        router.post(route('sales.store'), payload, {
            preserveScroll: true,
            preserveState: false,
            onStart: () => setCheckoutProcessing(true),
            onFinish: () => setCheckoutProcessing(false),
            onSuccess: () => {
                if (queuedId) {
                    setPendingSales((current) => current.filter((entry) => entry.id !== queuedId));
                } else {
                    setCart([]);
                    setPaymentAmount('');
                    setPaymentReference('');
                }
            },
            onError: (errors) => {
                setCheckoutErrors(errors);
                setNotice('No se pudo finalizar. Revisa las alertas del formulario.');
            },
        });
    };

    const sendPendingSale = (entry) => {
        if (!isOnline) {
            setNotice('No hay conexion para enviar ventas pendientes.');
            return;
        }

        submitSale(entry.payload, entry.id);
    };

    const discardPendingSale = (entryId) => {
        setPendingSales((current) => current.filter((entry) => entry.id !== entryId));
    };

    const printTemporaryReceipt = (entry) => {
        const win = window.open('', '_blank', 'width=420,height=640');
        if (!win) {
            setNotice('El navegador bloqueo la ventana de impresion del recibo temporal.');
            return;
        }

        const items = entry.payload.items.map((item) => `
            <tr>
                <td>${escapeHtml(item.description)}</td>
                <td style="text-align:right">${Number(item.display_quantity || 0).toFixed(2)}</td>
                <td style="text-align:right">${Number(item.unit_price || 0).toFixed(1)}</td>
                <td style="text-align:right">${Number(item.total || (item.meters * item.unit_price) || 0).toFixed(1)}</td>
            </tr>
        `).join('');

        win.document.write(`
            <html>
                <head>
                    <title>Recibo temporal POS</title>
                    <style>
                        body { font-family: Arial, sans-serif; font-size: 12px; padding: 12px; }
                        h1 { font-size: 16px; margin: 0 0 6px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                        td, th { border-bottom: 1px solid #ddd; padding: 4px 0; }
                        .warn { border: 1px solid #d97706; color: #92400e; padding: 8px; margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <h1>Recibo temporal</h1>
                    <p>Fecha local: ${new Date(entry.created_at).toLocaleString('es-BO')}</p>
                    <div class="warn">Este documento no es factura ni nota definitiva. Se debe sincronizar cuando vuelva la conexion.</div>
                    <table>
                        <thead><tr><th>Producto</th><th>Cant.</th><th>Precio</th><th>Total</th></tr></thead>
                        <tbody>${items}</tbody>
                    </table>
                    <h2 style="text-align:right">Total Bs ${Number(entry.total || 0).toFixed(1)}</h2>
                    <script>window.print();</script>
                </body>
            </html>
        `);
        win.document.close();
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-slate-800 dark:text-slate-200">POS rapido</h2>}>
            <Head title="POS rapido" />

            <section className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Comercial</p>
                        <h1 className="text-3xl font-bold text-slate-950 dark:text-white">{documentPolicy.ticketLabel ?? modeLabel}</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {modeLabel}. Escanee, seleccione items y genere {String(documentPolicy.saleNoteLabel ?? 'nota de venta').toLowerCase()} pagada desde una sola pantalla.
                        </p>
                        <p className={`mt-2 text-xs font-semibold ${isOnline ? 'text-emerald-600 dark:text-emerald-300' : 'text-amber-600 dark:text-amber-300'}`}>
                            {isOnline ? 'Conexion activa' : (offlineEnabled ? 'Modo sin conexion: se usara cola local' : 'Sin conexion: ventas offline desactivadas')}
                        </p>
                        {scannerRequired ? (
                            <p className="mt-2 text-xs text-sky-600 dark:text-sky-300">
                                Este perfil requiere lector de barras para operar el POS. La busqueda manual queda solo como apoyo visual.
                            </p>
                        ) : null}
                        {excludedTrackedProducts > 0 ? (
                            <p className="mt-2 text-xs text-amber-600 dark:text-amber-300">
                                {excludedTrackedProducts} productos con rastreo por lote/unidad fisica no aparecen aqui; deben venderse desde Venta documental para escoger el lote correcto.
                            </p>
                        ) : null}
                    </div>

                    <select value={selectedBranchId ?? ''} onChange={(event) => changeBranch(event.target.value)} className="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </select>
                </div>

                {pendingSales.length > 0 ? (
                    <div className="mb-5 rounded-3xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-bold">Ventas pendientes locales</h2>
                                <p>Estas ventas estan guardadas en este equipo. Envie cada una cuando haya conexion estable.</p>
                            </div>
                            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700 dark:bg-amber-500/20 dark:text-amber-100">{pendingSales.length} pendientes</span>
                        </div>
                        <div className="mt-3 grid gap-2 md:grid-cols-2">
                            {pendingSales.map((entry) => (
                                <div key={entry.id} className="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-white/70 p-3 dark:bg-slate-950/40">
                                    <div>
                                        <p className="font-semibold">Bs {money(entry.total)} - {entry.items_count} items</p>
                                        <p className="text-xs opacity-70">{new Date(entry.created_at).toLocaleString('es-BO')}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => sendPendingSale(entry)} disabled={!isOnline || checkoutProcessing} className="rounded-full bg-brand-primary px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">Enviar</button>
                                        <button type="button" onClick={() => printTemporaryReceipt(entry)} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">Recibo</button>
                                        <button type="button" onClick={() => discardPendingSale(entry.id)} className="rounded-full border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600">Descartar</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {(posPolicy.usesTables || posPolicy.supportsServices || posPolicy.usesKitchen || posPolicy.usesSplitPayments || posPolicy.billingEnabled || posPolicy.usesDelivery) ? (
                    <div className="mb-5 grid gap-3 lg:grid-cols-3">
                        {posPolicy.usesTables ? (
                            <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Restaurante</p>
                                <label className="mt-3 block text-sm font-semibold text-slate-700 dark:text-slate-200">Mesa o ambiente</label>
                                <input value={selectedTable} onChange={(event) => setSelectedTable(event.target.value)} placeholder="Ej. Mesa 4, salon norte" className="mt-2 h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Esta venta quedara marcada con la mesa indicada hasta conectar comandas reales en la fase restaurante.</p>
                            </div>
                        ) : null}

                        {posPolicy.supportsServices ? (
                            <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Servicios</p>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                    <input value={serviceReference} onChange={(event) => setServiceReference(event.target.value)} placeholder="Referencia u orden" className="h-11 rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                                    <input value={assignedPerson} onChange={(event) => setAssignedPerson(event.target.value)} placeholder="Tecnico/asignado" className="h-11 rounded-2xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-800 dark:bg-slate-950" />
                                </div>
                            </div>
                        ) : null}

                        {(posPolicy.usesKitchen || posPolicy.usesSplitPayments || posPolicy.billingEnabled || posPolicy.usesDelivery) ? (
                            <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Capacidades activas</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {posPolicy.usesKitchen ? <span className="rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700 dark:bg-orange-500/20 dark:text-orange-100">Cocina/barra</span> : null}
                                    {posPolicy.usesSplitPayments ? <span className="rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-sky-700 dark:bg-sky-500/20 dark:text-sky-100">Pago mixto</span> : null}
                                    {posPolicy.usesTips ? <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-100">Propinas</span> : null}
                                    {posPolicy.usesDelivery ? <span className="rounded-full bg-violet-100 px-3 py-1 text-xs font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-100">Delivery</span> : null}
                                    {posPolicy.billingEnabled ? <span className="rounded-full bg-slate-200 px-3 py-1 text-xs font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-100">Facturacion</span> : null}
                                </div>
                                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                    Canales: {activeChannels.map(channelLabel).join(', ')}. Cobro: {allowsPartialBalance ? 'parcial o mixto' : 'total obligatorio'}.
                                </p>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                <div className="grid gap-5 xl:grid-cols-[1fr_0.72fr]">
                    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                            <input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        addByBarcode(query.trim());
                                    }
                                }}
                                placeholder="Buscar por nombre, SKU o codigo de barras"
                                className="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-brand-primary focus:ring-4 focus:ring-brand-primary/10 dark:border-slate-800 dark:bg-slate-950"
                            />
                            <button type="button" onClick={() => addByBarcode(query.trim())} className="h-12 rounded-2xl bg-brand-primary px-5 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25">
                                Agregar codigo
                            </button>
                        </div>

                        <div className="mt-4 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                            {notice}
                        </div>
                        {Object.keys(checkoutErrors).length > 0 ? (
                            <div className="mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                                {Object.values(checkoutErrors).map((error) => <p key={error}>{error}</p>)}
                            </div>
                        ) : null}

                        <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {filteredProducts.map((product) => (
                                <div key={product.id} className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-brand-primary hover:bg-brand-primary/5 dark:border-slate-800 dark:bg-slate-950">
                                    {posPolicy.usesImages && product.image_url ? (
                                        <img src={product.image_url} alt={product.image_alt ?? product.name} loading="lazy" className="mb-3 h-28 w-full rounded-xl object-cover" />
                                    ) : null}
                                    <button type="button" onClick={() => !manualSelectionAllowed ? setNotice('Escanea el codigo de barras para agregar productos en este perfil.') : addProduct(product)} className="w-full text-left">
                                        <p className="line-clamp-2 min-h-10 text-sm font-bold text-slate-950 dark:text-white">{product.name}</p>
                                        <p className="mt-2 text-xs text-slate-500">{product.sku} - {product.barcode}</p>
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{itemTypeLabel(product.item_type)}</span>
                                            {product.preparation_minutes ? <span className="rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-700 dark:bg-orange-500/20 dark:text-orange-100">{product.preparation_minutes} min prep.</span> : null}
                                            {product.duration_minutes ? <span className="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-100">{product.duration_minutes} min</span> : null}
                                        </div>
                                        <div className="mt-3 flex items-center justify-between text-sm">
                                            <span className="font-semibold text-brand-primary">Bs {money(product.sale_price)}</span>
                                            <span className="rounded-full bg-slate-200 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                {product.is_inventory_item ? `${stock(product.stock)} ${product.unit}` : 'Sin stock'}
                                            </span>
                                        </div>
                                    </button>
                                    {posPolicy.usesVariants && product.variants?.length ? (
                                        <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                                            {product.variants.slice(0, 4).map((variant) => (
                                                <button key={variant.id} type="button" onClick={() => !manualSelectionAllowed ? setNotice('Escanea el codigo de barras para agregar variantes en este perfil.') : addProduct(product, variant)} className="rounded-full border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-brand-primary hover:text-brand-primary dark:border-slate-700 dark:text-slate-200">
                                                    {variant.name}
                                                </button>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </div>

                    <aside className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                            <div>
                                <h2 className="text-xl font-bold text-slate-950 dark:text-white">Carrito</h2>
                                <p className="text-sm text-slate-500">{cart.length} items</p>
                            </div>
                            <button type="button" onClick={() => setCart([])} className="rounded-full border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-red-300 hover:text-red-600 dark:border-slate-700 dark:text-slate-300">
                                Limpiar
                            </button>
                        </div>

                        <div className="mt-4 max-h-[48vh] space-y-3 overflow-y-auto pr-1">
                            {cart.length === 0 ? (
                                <div className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-700">
                                    Escanee o seleccione productos para agregarlos.
                                </div>
                            ) : null}

                            {cart.map((item) => {
                                const cartKey = item.cart_key ?? String(item.id);

                                return (
                                <div key={cartKey} className="rounded-2xl border border-slate-200 p-3 dark:border-slate-800">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-bold text-slate-950 dark:text-white">{item.name}</p>
                                            {item.variant_name ? <p className="text-xs font-semibold text-brand-primary">{item.variant_name}</p> : null}
                                            <p className="text-xs text-slate-500">Bs {money(item.sale_price)} por {item.unit}</p>
                                        </div>
                                        <p className="text-sm font-bold">Bs {money(item.quantity * item.sale_price)}</p>
                                    </div>
                                    <div className="mt-3 flex items-center gap-2">
                                        <button type="button" onClick={() => updateQuantity(cartKey, item.quantity - 1)} className="h-9 w-9 rounded-full border border-slate-300 text-lg font-bold dark:border-slate-700">-</button>
                                        <input value={item.quantity} onChange={(event) => updateQuantity(cartKey, event.target.value)} className="h-9 w-20 rounded-xl border border-slate-200 bg-slate-50 text-center text-sm font-semibold dark:border-slate-800 dark:bg-slate-950" />
                                        <button type="button" onClick={() => updateQuantity(cartKey, item.quantity + 1)} className="h-9 w-9 rounded-full border border-slate-300 text-lg font-bold dark:border-slate-700">+</button>
                                    </div>
                                </div>
                            );})}
                        </div>

                        <div className="mt-5 rounded-2xl bg-slate-950 p-5 text-white shadow-sm dark:bg-slate-950 dark:text-white">
                            <p className="text-sm opacity-70">Total estimado</p>
                            <p className="mt-1 text-4xl font-black">Bs {money(total)}</p>
                            <div className="mt-5 space-y-3 rounded-2xl bg-white/10 p-3">
                                <label className="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-100">Metodo de pago</label>
                                <select value={paymentMethodId} onChange={(event) => setPaymentMethodId(event.target.value)} className="pos-checkout-control h-11 w-full rounded-xl border border-white/20 bg-white px-3 text-sm font-semibold text-slate-950 outline-none dark:bg-white dark:text-slate-950">
                                    {paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name}</option>)}
                                </select>
                                <label className="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-100">Monto cobrado</label>
                                <input value={paymentAmount} onChange={(event) => setPaymentAmount(event.target.value)} placeholder={money(total)} type="number" min="0" step="0.1" className="pos-checkout-control h-11 w-full rounded-xl border border-white/20 bg-white px-3 text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-500 dark:bg-white dark:text-slate-950" />
                                {selectedPaymentMethod?.requires_reference ? (
                                    <>
                                        <label className="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-100">Referencia</label>
                                        <input value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} placeholder="QR, transferencia o comprobante" className="pos-checkout-control h-11 w-full rounded-xl border border-white/20 bg-white px-3 text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-500 dark:bg-white dark:text-slate-950" />
                                    </>
                                ) : null}
                            </div>
                            <button type="button" onClick={checkout} disabled={checkoutProcessing || cart.length === 0} className="mt-4 w-full rounded-2xl bg-brand-primary px-4 py-3 text-sm font-semibold text-white shadow-sm shadow-brand-primary/25 disabled:cursor-not-allowed disabled:opacity-60">
                                {checkoutProcessing ? 'Finalizando...' : (isOnline ? `Cobrar y generar ${String(documentPolicy.saleNoteLabel ?? 'nota').toLowerCase()}` : (offlineEnabled ? 'Guardar venta local' : 'Sin conexion'))}
                            </button>
                        </div>
                    </aside>
                </div>
            </section>
        </AuthenticatedLayout>
    );
}

function buildCheckoutPayload({ selectedBranchId, saleTypeId, currencyId, paymentMethodId, amountToCharge, paymentReference, cart, defaultTerms = '', context = {} }) {
    const notes = [
        'Venta generada desde POS dinamico.',
        context.mode ? `Modo: ${context.mode}.` : null,
        context.table ? `Mesa/ambiente: ${context.table}.` : null,
        context.reference ? `Referencia: ${context.reference}.` : null,
        context.assigned ? `Asignado: ${context.assigned}.` : null,
    ].filter(Boolean).join(' ');

    return {
            from_pos: true,
            document_type: 'sale_note',
            branch_id: selectedBranchId,
            sale_type_id: saleTypeId,
            currency_id: currencyId,
            customer_name: 'Cliente ocasional POS',
            customer_document: '',
            customer_contact: '',
            advance_mode: 'none',
            requires_delivery: false,
            terms: defaultTerms,
            internal_notes: notes,
            pos_payment_method_id: paymentMethodId,
            pos_payment_amount: amountToCharge,
            pos_payment_reference: paymentReference,
            pos_context: {
                mode: context.mode ?? 'retail',
                channel: context.channel ?? 'counter',
                resource: context.table ?? '',
                reference: context.reference ?? '',
                assigned: context.assigned ?? '',
            },
            items: cart.map((item) => ({
                product_id: item.product_id ?? item.id,
                product_coil_id: '',
                description: item.variant_name ? `${item.name} - ${item.variant_name}` : item.name,
                unit_label: item.unit,
                display_quantity: item.quantity,
                display_unit_label: item.unit,
                item_attributes: item.variant_attributes ? Object.entries(item.variant_attributes).map(([key, value]) => ({ name: key, value })) : [],
                calculation_mode: 'direct',
                meters: item.quantity,
                unit_price: item.sale_price,
                discount_amount: 0,
            })),
    };
}

function channelLabel(channel) {
    return ({
        counter: 'mostrador',
        table: 'mesa',
        delivery: 'delivery',
        reservation: 'reserva',
        service_order: 'orden de servicio',
    })[channel] ?? channel;
}

function normalizeCartItem(product, variant = null) {
    const variantPrice = variant?.sale_price !== undefined && variant?.sale_price !== null
        ? Number(variant.sale_price)
        : Number(product.sale_price);

    return {
        ...product,
        product_id: product.id,
        variant_id: variant?.id ?? null,
        variant_name: variant?.name ?? null,
        variant_attributes: variant?.attributes ?? null,
        cart_key: variant?.id ? `${product.id}:variant:${variant.id}` : `${product.id}:base`,
        sale_price: variantPrice,
        sku: variant?.sku ?? product.sku,
        barcode: variant?.barcode ?? product.barcode,
    };
}

function itemTypeLabel(type) {
    return ({
        physical: 'Producto',
        service: 'Servicio',
        combo: 'Combo',
        kit: 'Kit',
        prepared_product: 'Preparado',
        internal_supply: 'Insumo',
        finished_product: 'Terminado',
        rental: 'Alquiler',
        digital: 'Digital',
    })[type] ?? 'Item';
}

function money(value) {
    return Number(value || 0).toFixed(1);
}

function stock(value) {
    return Number(value || 0).toLocaleString('es-BO', { maximumFractionDigits: 2 });
}

function readStorage(key, fallback) {
    try {
        const value = localStorage.getItem(key);

        return value ? JSON.parse(value) : fallback;
    } catch {
        return fallback;
    }
}

function writeStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Si el navegador bloquea almacenamiento local, el POS sigue funcionando en memoria.
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
