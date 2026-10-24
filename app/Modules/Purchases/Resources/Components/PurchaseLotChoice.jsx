import LotSelector from '../../../Inventory/Resources/Components/LotSelector';

export function normalizeLotCode(value) {
    return String(value ?? '').trim().toUpperCase();
}

export default function PurchaseLotChoice({ productId, branchId, item, onChange, errors = {}, index, context = 'purchase', unit = 'm' }) {
    const mode = item.lot_mode === 'existing' ? 'existing' : 'new';
    const changeMode = (value) => {
        onChange({ lot_mode: value, [value === 'existing' ? 'new_lot_code' : 'lot_id']: '' });
    };

    return <fieldset className="space-y-3 rounded-md border border-slate-200 p-3 dark:border-slate-700">
        <legend className="px-1 text-sm font-medium">Identidad del lote</legend>
        <div className="flex flex-wrap gap-4 text-sm">
            <label className="inline-flex items-center gap-2"><input type="radio" checked={mode === 'existing'} disabled={!productId} onChange={() => changeMode('existing')} />Lote existente</label>
            <label className="inline-flex items-center gap-2"><input type="radio" checked={mode === 'new'} onChange={() => changeMode('new')} />Nuevo lote</label>
        </div>
        {mode === 'existing' ? <>
            <LotSelector productId={productId} branchId={branchId} context={context} value={item.lot_id ?? ''} onChange={(value) => onChange({ lot_id: value })} error={errors[`items.${index}.lot_id`]} unit={unit} />
            <p className="text-xs text-slate-500">Ingresa solo la cantidad que está entrando ahora, no el saldo final del lote. La recepción suma esa cantidad al saldo existente; también permite reingreso a un lote en 0.</p>
        </> : <>
            <label className="block text-sm font-medium" htmlFor={`new-lot-code-${index}`}>Código de lote nuevo</label>
            <input id={`new-lot-code-${index}`} value={item.new_lot_code ?? ''} onChange={(event) => onChange({ new_lot_code: normalizeLotCode(event.target.value) })} placeholder="Ej: G260427D03" pattern="(?=.*[A-Z0-9])[A-Z0-9-]+" aria-invalid={Boolean(errors[`items.${index}.new_lot_code`])} aria-describedby={errors[`items.${index}.new_lot_code`] ? `new-lot-error-${index}` : undefined} maxLength={80} className="w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" />
            {errors[`items.${index}.new_lot_code`] ? <p id={`new-lot-error-${index}`} className="text-sm text-red-600">{errors[`items.${index}.new_lot_code`]}</p> : null}
            <p className="text-xs text-slate-500">Cada bobina física nueva debe tener un código distinto y su propia línea.</p>
        </>}
        {context === 'purchase' && item.calculation_mode !== 'weight' ? <label className="block text-sm" htmlFor={`lot-weight-${index}`}>Peso en Kg (referencia de este ingreso)<input id={`lot-weight-${index}`} type="number" min="0" step="0.001" value={item.kilograms ?? ''} onChange={(event) => onChange({ kilograms: event.target.value, weight_unit: 'kg' })} className="mt-1 block w-full rounded-md border-slate-300 text-sm dark:bg-slate-900" />{errors[`items.${index}.kilograms`] ? <span className="text-red-600">{errors[`items.${index}.kilograms`]}</span> : null}</label> : null}
    </fieldset>;
}
