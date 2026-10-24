import { useEffect, useId, useRef, useState } from 'react';

export default function LotSelector({ productId, branchId, context, value = '', selectedLabel = '', onChange, error, disabled = false, unit = 'unidad' }) {
    const inputId = useId();
    const [search, setSearch] = useState('');
    const [items, setItems] = useState([]);
    const [cursor, setCursor] = useState(null);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState('');
    const [selectedLot, setSelectedLot] = useState(null);
    const requestVersion = useRef(0);
    const pageController = useRef(null);

    useEffect(() => {
        requestVersion.current += 1;
        pageController.current?.abort();
        pageController.current = null;
        setItems([]);
        setCursor(null);
        setSearch('');
        setSelectedLot(null);
    }, [productId, branchId, context]);

    useEffect(() => {
        requestVersion.current += 1;
        pageController.current?.abort();
        pageController.current = null;
        const version = requestVersion.current;
        setItems([]);
        setCursor(null);
        if (!productId || !branchId || !context || disabled) return undefined;
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            setMessage('');
            try {
                const url = new URL(route('inventory.lots.options', { product: productId }), window.location.origin);
                url.searchParams.set('branch_id', String(branchId));
                url.searchParams.set('context', context);
                url.searchParams.set('search', search);
                const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No se pudieron consultar los lotes.');
                const result = await response.json();
                if (version === requestVersion.current) {
                    setItems(result.data ?? []);
                    setCursor(result.next_cursor ?? null);
                }
            } catch (cause) {
                if (cause.name !== 'AbortError' && version === requestVersion.current) setMessage(cause.message);
            } finally {
                if (!controller.signal.aborted && version === requestVersion.current) setLoading(false);
            }
        }, 250);
        return () => { controller.abort(); window.clearTimeout(timer); };
    }, [productId, branchId, context, search, disabled]);

    const loadMore = async () => {
        if (!cursor || loading) return;
        const version = requestVersion.current;
        const controller = new AbortController();
        pageController.current?.abort();
        pageController.current = controller;
        setLoading(true);
        try {
            const url = new URL(route('inventory.lots.options', { product: productId }), window.location.origin);
            url.searchParams.set('branch_id', String(branchId));
            url.searchParams.set('context', context);
            url.searchParams.set('search', search);
            url.searchParams.set('cursor', cursor);
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('No se pudieron cargar más lotes.');
            const result = await response.json();
            if (version === requestVersion.current) {
                setItems((current) => [...current, ...(result.data ?? [])]);
                setCursor(result.next_cursor ?? null);
            }
        } catch (cause) {
            if (cause.name !== 'AbortError' && version === requestVersion.current) setMessage(cause.message);
        } finally {
            if (pageController.current === controller) pageController.current = null;
            if (!controller.signal.aborted && version === requestVersion.current) setLoading(false);
        }
    };

    return <div className="space-y-2">
        <label htmlFor={`${inputId}-search`} className="block text-sm font-medium">Buscar lote</label>
        <input id={`${inputId}-search`} type="search" value={search} onChange={(event) => setSearch(event.target.value)} disabled={disabled || !productId || !branchId} className="w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
        <label htmlFor={inputId} className="block text-sm font-medium">Lote del producto</label>
        <select id={inputId} value={value ?? ''} onChange={(event) => { const lot = items.find((entry) => String(entry.lot_id) === event.target.value); setSelectedLot(lot ?? null); onChange?.(event.target.value); }} disabled={disabled || !productId || !branchId} aria-invalid={Boolean(error)} aria-describedby={error ? `${inputId}-error` : undefined} className="w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
            <option value="">Selecciona un lote</option>
            {value && !items.some((lot) => String(lot.lot_id) === String(value)) ? <option value={value}>{selectedLot?.lot_code || selectedLabel || `Lote ${value}`}</option> : null}
            {items.map((lot) => <option key={lot.lot_id} value={lot.lot_id} disabled={lot.selectable === false}>{lot.lot_code} · libres {lot.free} {unit} · físico {lot.physical} · peso inicial {lot.initial_kg ?? '—'} Kg{lot.depleted ? ['purchase', 'receipt', 'adjustment'].includes(context) ? ' · ⚠ EN 0 (Agotado - permite reingreso)' : ' · ⚠ EN 0 - AGOTADO' : lot.selectable === false ? ' · Reservado / sin saldo libre' : ''}</option>)}
        </select>
        {items.some((lot) => lot.depleted) ? <p role="status" className="rounded bg-amber-50 p-2 text-xs font-semibold text-amber-900">⚠ LOTE EN 0 (AGOTADO): los códigos agotados están identificados en la lista. {['purchase', 'receipt', 'adjustment'].includes(context) ? 'Puedes elegirlos para registrar un reingreso.' : 'No pueden seleccionarse para vender o reservar.'}</p> : null}
        {!loading && productId && branchId && !items.length ? <p role="status" className="text-sm text-slate-500">No hay lotes registrados que coincidan con esta búsqueda.</p> : null}
        {cursor ? <button type="button" onClick={loadMore} disabled={loading} className="text-sm font-semibold text-brand-primary">Cargar más lotes</button> : null}
        {loading ? <p role="status" className="text-sm text-slate-500">Consultando lotes…</p> : null}
        {message ? <p role="alert" className="text-sm text-red-600">{message}</p> : null}
        {error ? <p id={`${inputId}-error`} className="text-sm text-red-600">{error}</p> : null}
    </div>;
}
