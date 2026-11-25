import { CURRENCIES } from '../currencies';
import type { CurrencyKey } from '../types';
import { cn } from '@/lib/utils';

type CurrencySwitchProps = {
    value: CurrencyKey;
    onChange: (value: CurrencyKey) => void;
};

/** Which currency the whole dashboard reads in. Each one keeps its own books: nothing is converted. */
export function CurrencySwitch({ value, onChange }: CurrencySwitchProps) {
    return (
        <div
            role="radiogroup"
            aria-label="Moneda del panel"
            className="grid w-full grid-cols-3 rounded-full border bg-muted/60 p-1 md:inline-grid md:w-auto md:shrink-0"
        >
            {CURRENCIES.map((currency) => {
                const active = currency.key === value;

                return (
                    <button
                        key={currency.key}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        title={currency.name}
                        onClick={() => onChange(currency.key)}
                        className={cn(
                            'rounded-full px-4 py-1.5 text-sm font-semibold transition-colors outline-none',
                            'focus-visible:ring-2 focus-visible:ring-ring',
                            active
                                ? 'bg-primary text-primary-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {currency.label}
                    </button>
                );
            })}
        </div>
    );
}
