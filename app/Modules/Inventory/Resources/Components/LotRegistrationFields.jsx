import { useId } from 'react';

export function newLotRow(branchId = '') {
    return { lot_code: '', initial_kg: '', initial_meters: '', branch_id: branchId, barcode: '' };
}

export default function LotRegistrationFields({ rows = [], onChange, branches = [], defaultBranchId = '', errors = {}, lockBranch = false, disabled = false, baseUnit = 'm', entryContext = 'inventory' }) {
    const id = useId();
    const update = (index, field, value) => onChange(rows.map((row, position) => position === index ? { ...row, [field]: value } : row));
    const field = (row, index, name, label, props = {}) => {
        const error = errors[`lot_registrations.${index}.${name}`];
        const inputId = `${id}-${index}-${name}`;
        return <label className="block text-sm" htmlFor={inputId}>{label}
            <input id={inputId} value={row[name] ?? ''} onChange={(event) => update(index, name, event.target.value)} disabled={disabled}
                aria-invalid={Boolean(error)} aria-describedby={error ? `${inputId}-error` : undefined}
                className="mt-1 block w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" {...props} />
            {error ? <span id={`${inputId}-error`} className="mt-1 block text-red-600">{error}</span> : null}
        </label>;
    };

    return <fieldset className="mt-4 space-y-4 rounded-lg border border-brand-primary/30 p-4">
        <legend className="px-1 font-semibold">Lotes / Bobinas iniciales</legend>
        <p className="text-sm text-slate-600 dark:text-slate-300">Cada bobina física lleva un código distinto. Cantidad inicial en {baseUnit}; el peso en kg es una referencia si ya indicas la cantidad. Para calcular metros solo desde el peso, configura el espesor y usa Metro (m) como unidad base.</p>
        <p className="rounded-md bg-sky-50 p-3 text-sm text-sky-950 dark:bg-sky-950/40 dark:text-sky-100">{entryContext === 'purchase' ? 'Estos lotes se preparan para esta compra. El stock aumenta una sola vez al guardar la compra como Recibida; un borrador no aumenta existencias.' : 'Registrar cantidades aquí aumenta el stock inicial sin crear una compra. Si esta mercadería ingresará con documento de proveedor, regístrala desde Compras para evitar duplicarla. Puedes quitar las filas para crear solo el producto.'}</p>
        {rows.map((row, index) => <div key={index} className="rounded-md border border-slate-200 p-3 dark:border-slate-700">
            <div className="mb-2 flex items-center justify-between"><h4 className="text-sm font-semibold">Lote {index + 1}</h4><button type="button" disabled={disabled} onClick={() => onChange(rows.filter((_, position) => position !== index))} className="text-sm text-red-700 dark:text-red-300" aria-label={`Quitar lote ${index + 1}`}>Quitar</button></div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {field(row, index, 'lot_code', 'Código de Lote / Coil', { required: true, maxLength: 80, placeholder: 'Ej: G260427D03', pattern: '(?=.*[A-Za-z0-9])[A-Za-z0-9-]+', onBlur: () => update(index, 'lot_code', String(row.lot_code).trim().toUpperCase()), style: { textTransform: 'uppercase' } })}
                {field(row, index, 'initial_kg', 'Peso en Kg', { type: 'number', min: '0', step: '0.001' })}
                {field(row, index, 'initial_meters', `Cantidad inicial (${baseUnit})`, { type: 'number', min: '0', step: '0.001', placeholder: baseUnit === 'm' ? 'Metros; o calcular por peso y espesor' : `Cantidad en ${baseUnit}` })}
                <label className="text-sm" htmlFor={`${id}-${index}-branch`}>Sucursal<select id={`${id}-${index}-branch`} value={lockBranch ? defaultBranchId : row.branch_id} disabled={disabled || lockBranch} onChange={(event) => update(index, 'branch_id', event.target.value)} className="mt-1 block w-full rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" required><option value="">Seleccionar</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select>{errors[`lot_registrations.${index}.branch_id`] ? <span className="text-red-600">{errors[`lot_registrations.${index}.branch_id`]}</span> : null}</label>
            </div>
        </div>)}
        {errors.lot_registrations ? <p role="alert" className="text-sm text-red-600">{errors.lot_registrations}</p> : null}
        <button type="button" disabled={disabled || rows.length >= 100} onClick={() => onChange([...rows, newLotRow(defaultBranchId || branches[0]?.id)])} className="rounded-md border border-brand-primary px-3 py-2 text-sm font-semibold text-brand-primary">+ Agregar otro lote</button>
    </fieldset>;
}
