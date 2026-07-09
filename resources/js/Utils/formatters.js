import { usePage } from '@inertiajs/react';

const fallbackPrecision = {
    quantity: 0,
    measure: 2,
    money: 1,
    percent: 2,
    exchange_rate: 6,
    weight: 2,
    cost: 1,
    modules: {},
};

export function useDecimalFormatter(module = null) {
    const precision = usePage().props.decimalPrecision ?? fallbackPrecision;

    const decimalsFor = (kind) => Number(precision.modules?.[module]?.[kind] ?? precision[kind] ?? fallbackPrecision[kind] ?? 2);

    const format = (value, kind = 'measure', options = {}) => {
        const decimals = decimalsFor(kind);

        return new Intl.NumberFormat('es-BO', {
            minimumFractionDigits: options.minimumFractionDigits ?? decimals,
            maximumFractionDigits: options.maximumFractionDigits ?? decimals,
        }).format(Number(value ?? 0));
    };

    return {
        decimalsFor,
        format,
        money: (value) => format(value, 'money'),
        measure: (value) => format(value, 'measure'),
        quantity: (value) => format(value, 'quantity'),
        weight: (value) => format(value, 'weight'),
        cost: (value) => format(value, 'cost'),
        percent: (value) => format(value, 'percent'),
        exchangeRate: (value) => format(value, 'exchange_rate'),
        fixed: (value, kind = 'measure') => Number(value ?? 0).toFixed(decimalsFor(kind)),
    };
}

export function decimalStep(decimals) {
    const value = Number(decimals ?? 0);

    if (value <= 0) {
        return '1';
    }

    return `0.${'0'.repeat(Math.max(value - 1, 0))}1`;
}
