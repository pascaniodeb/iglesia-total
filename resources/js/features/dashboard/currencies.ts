import type { CurrencyKey } from './types';

export const CURRENCIES: {
    key: CurrencyKey;
    code: 'VES' | 'USD' | 'COP';
    label: string;
    name: string;
}[] = [
    { key: 'cop', code: 'COP', label: 'COP', name: 'Pesos colombianos' },
    { key: 'usd', code: 'USD', label: 'USD', name: 'Dólares' },
    { key: 'ves', code: 'VES', label: 'Bs', name: 'Bolívares' },
];

export function currencyLabel(key: CurrencyKey): string {
    return CURRENCIES.find((currency) => currency.key === key)?.label ?? key;
}

/**
 * A bar's size relative to the biggest one. Only for drawing: what is written is always the
 * server's exact decimal text, never this number.
 */
export function ratio(value: string, max: string): number {
    const top = Number(max);

    return top > 0 ? Math.max(0, Number(value)) / top : 0;
}

/** The change from one month to the next as a whole percentage, or null when there is nothing to compare. */
export function percentChange(
    current: string,
    previous: string,
): number | null {
    const before = Number(previous);

    return before > 0
        ? Math.round(((Number(current) - before) / before) * 100)
        : null;
}

const compact = new Intl.NumberFormat('es-VE', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** "141000.00" → "141 mil": a short label for a chart axis. */
export function compactAmount(value: string): string {
    return compact.format(Number(value));
}
