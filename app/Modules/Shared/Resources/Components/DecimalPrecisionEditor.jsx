export default function DecimalPrecisionEditor({ value, onChange }) {
    const modules = [
        ['sales', 'Ventas'],
        ['purchases', 'Compras'],
        ['inventory', 'Inventario'],
        ['finance', 'Finanzas'],
    ];
    const fields = [
        ['quantity', 'Cantidades'],
        ['measure', 'Medidas'],
        ['money', 'Dinero/contabilidad'],
        ['weight', 'Peso'],
        ['cost', 'Costos/precios'],
        ['percent', 'Porcentajes'],
        ['exchange_rate', 'Tipo de cambio'],
    ];

    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 dark:border-slate-800 dark:bg-slate-950/30">
            <h4 className="text-sm font-semibold text-slate-950 dark:text-white">Decimales del sistema</h4>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Controla cuantos decimales se muestran por defecto y por modulo. Se aplica a ventas, compras, inventario y finanzas.
            </p>

            <div className="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {fields.map(([key, label]) => (
                    <DecimalInput
                        key={key}
                        label={label}
                        value={value[key] ?? 0}
                        onChange={(nextValue) => onChange([key], nextValue)}
                    />
                ))}
            </div>

            <div className="mt-5 space-y-4">
                {modules.map(([module, label]) => (
                    <div key={module} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{label}</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            {Object.entries(value.modules?.[module] ?? {}).map(([key, decimals]) => (
                                <DecimalInput
                                    key={`${module}-${key}`}
                                    label={decimalLabel(key)}
                                    value={decimals}
                                    onChange={(nextValue) => onChange(['modules', module, key], nextValue)}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DecimalInput({ label, value, onChange }) {
    return (
        <label className="block">
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</span>
            <input
                type="number"
                min="0"
                max="6"
                step="1"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-2xl border-slate-200 bg-white/80 text-sm shadow-sm focus:border-brand-primary focus:ring-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-100"
            />
        </label>
    );
}

function decimalLabel(key) {
    return {
        quantity: 'Cant.',
        measure: 'Medidas',
        money: 'Dinero',
        weight: 'Peso',
        cost: 'Costo/precio',
        percent: 'Porcentaje',
        exchange_rate: 'Cambio',
    }[key] ?? key;
}

export function decimalDefaults(value = {}) {
    return {
        quantity: Number(value.quantity ?? 0),
        measure: Number(value.measure ?? 2),
        money: Number(value.money ?? 1),
        percent: Number(value.percent ?? 2),
        exchange_rate: Number(value.exchange_rate ?? 6),
        weight: Number(value.weight ?? 2),
        cost: Number(value.cost ?? 1),
        modules: {
            sales: { quantity: Number(value.modules?.sales?.quantity ?? 0), measure: Number(value.modules?.sales?.measure ?? 2), money: Number(value.modules?.sales?.money ?? 1) },
            purchases: { quantity: Number(value.modules?.purchases?.quantity ?? 0), measure: Number(value.modules?.purchases?.measure ?? 2), money: Number(value.modules?.purchases?.money ?? 1), weight: Number(value.modules?.purchases?.weight ?? 2), cost: Number(value.modules?.purchases?.cost ?? 1) },
            inventory: { quantity: Number(value.modules?.inventory?.quantity ?? 0), measure: Number(value.modules?.inventory?.measure ?? 2), weight: Number(value.modules?.inventory?.weight ?? 2), cost: Number(value.modules?.inventory?.cost ?? 1) },
            finance: { money: Number(value.modules?.finance?.money ?? 1) },
        },
    };
}

export function setDecimalPath(target, path, value) {
    const normalized = Math.max(0, Math.min(6, Number(value || 0)));
    let cursor = target;

    path.slice(0, -1).forEach((segment) => {
        cursor[segment] ??= {};
        cursor = cursor[segment];
    });

    cursor[path.at(-1)] = normalized;
}
