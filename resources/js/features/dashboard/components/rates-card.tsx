import { Link } from '@inertiajs/react';
import { formatRate, formatShortDate } from '@/features/treasury/rates';
import type { RatesWidget } from '../types';
import { Panel } from './panel';
import { index } from '@/routes/treasury/exchange-rates';

/** The exchange rates in force. They inform: the books stay in each currency. */
export function RatesCard({ rates }: { rates: RatesWidget }) {
    const rows: { label: string; hint: string; value: string | null }[] = [
        { label: 'Dólar', hint: 'Bs por 1 USD', value: rates.usd },
        { label: 'Peso colombiano', hint: 'COP por 1 Bs', value: rates.cop },
        { label: 'Euro', hint: 'Bs por 1 EUR', value: rates.eur },
    ];

    return (
        <Panel
            title="Tasas de cambio"
            description={`Vigentes al ${formatShortDate(rates.date)}`}
            aside={
                <Link
                    href={index()}
                    prefetch
                    className="text-sm font-medium text-primary hover:underline"
                >
                    Ver tasas
                </Link>
            }
        >
            <dl className="flex flex-col divide-y">
                {rows.map((row) => (
                    <div
                        key={row.label}
                        className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <dt className="min-w-0">
                            <span className="block text-sm font-medium">
                                {row.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {row.hint}
                            </span>
                        </dt>
                        <dd className="text-lg font-bold tabular-nums">
                            {formatRate(row.value)}
                        </dd>
                    </div>
                ))}
            </dl>
        </Panel>
    );
}
