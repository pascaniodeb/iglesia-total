import { currencyLabel, ratio } from '../currencies';
import type { CategoryTotals, CurrencyKey } from '../types';
import { Panel } from './panel';
import { formatMonth } from '@/lib/month';
import { formatAmount, isZeroAmount } from '@/lib/money';

type CategoryBreakdownProps = {
    month: string;
    categories: CategoryTotals[];
    currency: CurrencyKey;
};

const SHADES = [
    'bg-chart-1',
    'bg-chart-2',
    'bg-chart-3',
    'bg-chart-4',
    'bg-chart-5',
] as const;

/** What each kind of offering brought in the month, as a share of the month's total. */
export function CategoryBreakdown({
    month,
    categories,
    currency,
}: CategoryBreakdownProps) {
    const rows = categories.filter(
        (category) => !isZeroAmount(category[currency]),
    );
    const total = rows.reduce(
        (sum, category) => sum + Number(category[currency]),
        0,
    );

    return (
        <Panel
            title="Por tipo de ofrenda"
            description={`${formatMonth(month)}, en ${currencyLabel(currency)}`}
        >
            {rows.length === 0 ? (
                <p className="rounded-xl bg-muted/50 p-6 text-center text-sm text-muted-foreground">
                    Este mes aún no hay ofrendas en {currencyLabel(currency)}.
                </p>
            ) : (
                <>
                    <div
                        role="img"
                        aria-label="Reparto del mes por tipo de ofrenda"
                        className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
                    >
                        {rows.map((category, index) => (
                            <span
                                key={category.id}
                                className={SHADES[index % SHADES.length]}
                                style={{
                                    width: `${ratio(category[currency], String(total)) * 100}%`,
                                }}
                            />
                        ))}
                    </div>
                    <ul className="flex flex-col gap-3">
                        {rows.map((category, index) => {
                            const share = Math.round(
                                ratio(category[currency], String(total)) * 100,
                            );

                            return (
                                <li
                                    key={category.id}
                                    className="flex min-w-0 items-center gap-3"
                                >
                                    <span
                                        aria-hidden
                                        className={`size-3 shrink-0 rounded-xs ${SHADES[index % SHADES.length]}`}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium capitalize">
                                            {category.name.toLowerCase()}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {category.count}{' '}
                                            {category.count === 1
                                                ? 'ofrenda'
                                                : 'ofrendas'}{' '}
                                            · {share}%
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                                        {formatAmount(category[currency])}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </Panel>
    );
}
