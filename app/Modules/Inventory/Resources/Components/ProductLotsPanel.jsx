import { Link } from '@inertiajs/react';
import { useState } from 'react';
import LotEditorModal from '../Pages/Inventory/Lots/LotEditorModal';

export const lotIsEmpty = (lot) => !lot.voided_at && /^0+(?:\.0+)?$/.test(String(lot.available_meters ?? ''));

export default function ProductLotsPanel({ overview, onPage }) {
    const [editor, setEditor] = useState(null);
    const { product, lots, summary = {}, canManageLots = {} } = overview;
    return <section className="mt-5 space-y-3" aria-label={`Lotes de ${product.name}`}>
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-semibold">{summary.total ?? 0} lotes: {summary.stocked ?? 0} con stock, {summary.depleted ?? 0} en 0</h3><Link className="text-sm font-semibold text-brand-primary" href={route('inventory.lots.index', product.id)}>Gestionar lotes de este producto</Link></div>
        {Number(summary.depleted ?? 0) > 0 ? <p role="status" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-900">⚠ {summary.depleted} lotes en 0 (Agotados). No pueden venderse; admiten reingreso por compra si siguen activos.</p> : null}
        <div className="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700"><table className="min-w-full text-left text-sm"><thead className="bg-slate-100 dark:bg-slate-800"><tr>{['Código', 'Sucursal', 'Peso inicial Kg', `Inicial (${product.base_unit})`, `Disponible (${product.base_unit})`, `Reservado (${product.base_unit})`, `Libre (${product.base_unit})`, 'Estado', 'Acciones'].map((title) => <th key={title} className="whitespace-nowrap px-3 py-3">{title}</th>)}</tr></thead><tbody className="divide-y divide-slate-200 dark:divide-slate-700">
            {lots.data.map((lot) => <tr key={lot.id} className={lotIsEmpty(lot) ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''}>
                <td className="px-3 py-3 font-semibold">{lot.lot_code ?? lot.lot_number ?? `Histórico #${lot.id}`}<details className="mt-2 text-xs font-normal"><summary>Movimientos y auditoría</summary><ul>{(lot.movements ?? []).map((movement) => <li key={movement.id}>{movement.type}: {movement.meters_delta} · {movement.lot_quantity_before ?? '—'} → {movement.lot_quantity_after ?? '—'}</li>)}</ul><ul>{(lot.audits ?? []).map((audit) => <li key={audit.id}>{audit.event} · {audit.created_at}</li>)}</ul>{lot.notes ? <p>{lot.notes}</p> : null}</details></td>
                <td className="px-3 py-3">{lot.branch?.name ?? lot.branch_id}</td><td className="px-3 py-3">{lot.initial_kg ?? '—'}</td><td className="px-3 py-3">{lot.initial_meters}</td><td className="px-3 py-3">{lot.available_meters} {product.base_unit}</td><td className="px-3 py-3">{lot.reserved_quantity}</td><td className="px-3 py-3">{lot.free_quantity}</td>
                <td className="px-3 py-3">{lot.voided_at ? <span>Anulado · bloqueado {lot.blocked_quantity}</span> : lotIsEmpty(lot) ? <span className="whitespace-nowrap rounded bg-amber-100 px-2 py-1 font-semibold text-amber-900">⚠ LOTE EN 0 (AGOTADO)</span> : lot.status === 'available' ? 'Disponible' : lot.status}</td>
                <td className="px-3 py-3">{canManageLots[lot.id] && !lot.voided_at && lot.lot_code ? <div className="flex gap-2"><button type="button" className="text-brand-primary" onClick={() => setEditor({ lot, mode: 'edit' })}>Editar</button><button type="button" className="text-red-700" onClick={() => setEditor({ lot, mode: 'void' })}>Anular</button></div> : 'Solo lectura'}</td>
            </tr>)}
            {!lots.data.length ? <tr><td colSpan={9} className="p-4 text-slate-500">Este producto todavía no tiene lotes registrados con estos filtros.</td></tr> : null}
        </tbody></table></div>
        <nav aria-label="Páginas de lotes" className="flex gap-3">{[[lots.prev_page_url, 'Anteriores'], [lots.next_page_url, 'Siguientes']].map(([url, label]) => url ? onPage ? <button key={label} type="button" onClick={() => onPage(url)} className="rounded border px-3 py-2 text-sm">{label}</button> : <Link key={label} href={url} preserveScroll className="rounded border px-3 py-2 text-sm">{label}</Link> : null)}</nav>
        {editor ? <LotEditorModal product={product} lot={editor.lot} mode={editor.mode} onClose={() => setEditor(null)} onSaved={onPage ? () => onPage(route('inventory.lots.index', product.id)) : undefined} /> : null}
    </section>;
}
