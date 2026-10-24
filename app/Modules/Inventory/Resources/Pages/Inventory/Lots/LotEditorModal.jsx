import { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';

export default function LotEditorModal({ product, lot, mode, onClose, onSaved }) {
    const firstField = useRef(null);
    const dialog = useRef(null);
    const previousFocus = useRef(null);
    const form = useForm({ lot_code: lot?.lot_code ?? '', notes: lot?.notes ?? '', void_reason: '' });

    useEffect(() => {
        previousFocus.current = document.activeElement;
        firstField.current?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
            if (event.key === 'Tab') {
                const elements = [...dialog.current.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled])')];
                const first = elements[0];
                const last = elements[elements.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            previousFocus.current?.focus?.();
        };
    }, [onClose]);

    const submit = (event) => {
        event.preventDefault();
        const saved = () => { onClose(); onSaved?.(); };
        if (mode === 'void') {
            form.transform(({ void_reason }) => ({ void_reason })).post(
                route('inventory.lots.void', { product: product.id, lot: lot.id }),
                { preserveScroll: true, onSuccess: saved },
            );
        } else {
            form.transform(({ lot_code, notes }) => ({ lot_code: lot_code.trim().toUpperCase(), notes })).patch(
                route('inventory.lots.update', { product: product.id, lot: lot.id }),
                { preserveScroll: true, onSuccess: saved },
            );
        }
    };

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
        <div ref={dialog} role="dialog" aria-modal="true" aria-labelledby="lot-dialog-title" className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl dark:bg-slate-900">
            <h2 id="lot-dialog-title" className="text-lg font-semibold">{mode === 'void' ? 'Anular lote' : 'Editar lote'} {lot.lot_code}</h2>
            <form onSubmit={submit} className="mt-4 space-y-4">
                {mode === 'void' ? <>
                    <p>Se ajustarán {lot.available_meters} {product.base_unit} de este lote. La identidad y el historial permanecerán.</p>
                    <label className="block text-sm font-medium" htmlFor="void_reason">Motivo de anulación</label>
                    <textarea ref={firstField} id="void_reason" value={form.data.void_reason} onChange={(event) => form.setData('void_reason', event.target.value)} aria-invalid={Boolean(form.errors.void_reason)} aria-describedby={form.errors.void_reason ? 'void_reason_error' : undefined} required className="w-full rounded-md border-slate-300 dark:bg-slate-800" />
                    {form.errors.void_reason ? <p id="void_reason_error" className="text-sm text-red-600">{form.errors.void_reason}</p> : null}
                </> : <>
                    <label className="block text-sm font-medium" htmlFor="lot_code">Código del lote</label>
                    <input ref={firstField} id="lot_code" value={form.data.lot_code} onChange={(event) => form.setData('lot_code', event.target.value)} maxLength={80} pattern="(?=.*[A-Za-z0-9])[A-Za-z0-9-]+" required aria-invalid={Boolean(form.errors.lot_code)} aria-describedby={form.errors.lot_code ? 'lot_code_error' : undefined} className="w-full rounded-md border-slate-300 dark:bg-slate-800" />
                    {form.errors.lot_code ? <p id="lot_code_error" className="text-sm text-red-600">{form.errors.lot_code}</p> : null}
                    <label className="block text-sm font-medium" htmlFor="lot_notes">Observaciones</label>
                    <textarea id="lot_notes" value={form.data.notes ?? ''} onChange={(event) => form.setData('notes', event.target.value)} className="w-full rounded-md border-slate-300 dark:bg-slate-800" />
                </>}
                {form.errors.lot_id ? <p role="alert" className="text-sm text-red-600">{form.errors.lot_id}</p> : null}
                <div className="flex justify-end gap-3">
                    <button type="button" onClick={onClose} className="rounded-md border px-4 py-2">Cancelar</button>
                    <button type="submit" disabled={form.processing} className="rounded-md bg-brand-primary px-4 py-2 text-white disabled:opacity-50">{mode === 'void' ? 'Confirmar anulación' : 'Guardar cambios'}</button>
                </div>
            </form>
        </div>
    </div>;
}
