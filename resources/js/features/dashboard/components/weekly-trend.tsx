import { useEffect, useState } from 'react';
import { compactAmount, currencyLabel, ratio } from '../currencies';
import type { CurrencyKey, TrendWeek } from '../types';
import { Panel } from './panel';
import { formatShortDate } from '@/features/treasury/rates';
import { formatAmount } from '@/lib/money';
import { cn } from '@/lib/utils';

type WeeklyTrendProps = {
    weeks: TrendWeek[];
    currency: CurrencyKey;
};

/** "2026-W47" → "S47". */
function weekLabel(key: string): string {
    return `S${key.split('-W')[1] ?? key}`;
}

/**
 * The offerings of the last weeks as bars. A bar is solid when the week is closed, softer while it
 * is still open; the one being read is spelled out above the chart, so no value hides in a tooltip.
 */
export function WeeklyTrend({ weeks, currency }: WeeklyTrendProps) {
    const lastWithData = [...weeks].reverse().find((week) => week.count > 0);
    const [picked, setPicked] = useState<string | null>(null);
    const [drawn, setDrawn] = useState(false);

    useEffect(() => {
        const frame = requestAnimationFrame(() => setDrawn(true));

        return () => cancelAnimationFrame(frame);
    }, []);

    const active =
        weeks.find((week) => week.key === picked) ??
        lastWithData ??
        weeks[weeks.length - 1];
    const max = weeks.reduce(
        (top, week) =>
            Number(week[currency]) > Number(top) ? week[currency] : top,
        '0',
    );
    const total = weeks.reduce((sum, week) => sum + Number(week.count), 0);

    return (
        <Panel
            title="Ofrendas por semana"
            description={`Últimas ${weeks.length} semanas, en ${currencyLabel(currency)}`}
            aside={<Legend />}
            className="@container"
        >
            {total === 0 || !active ? (
                <p className="rounded-xl bg-muted/50 p-6 text-center text-sm text-muted-foreground">
                    Aún no hay ofrendas en estas semanas.
                </p>
            ) : (
                <>
                    <div
                        aria-live="polite"
                        className="flex min-w-0 flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
                    >
                        <p className="flex items-baseline gap-2 tabular-nums">
                            <span className="text-2xl font-bold tracking-tight">
                                {formatAmount(active[currency])}
                            </span>
                            <span className="text-sm font-semibold text-muted-foreground">
                                {currencyLabel(currency)}
                            </span>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Semana {active.key.split('-W')[1]} ·{' '}
                            {formatShortDate(active.start)} al{' '}
                            {formatShortDate(active.end)} · {active.count}{' '}
                            {active.count === 1 ? 'ofrenda' : 'ofrendas'}
                        </p>
                    </div>
                    <div
                        role="group"
                        aria-label="Ofrendas por semana"
                        className="grid h-44 items-end gap-1.5 @md:gap-2.5"
                        style={{
                            gridTemplateColumns: `repeat(${weeks.length}, minmax(0, 1fr))`,
                        }}
                    >
                        {weeks.map((week) => {
                            const height = drawn
                                ? ratio(week[currency], max)
                                : 0;
                            const selected = week.key === active.key;

                            return (
                                <button
                                    key={week.key}
                                    type="button"
                                    onClick={() => setPicked(week.key)}
                                    onMouseEnter={() => setPicked(week.key)}
                                    onFocus={() => setPicked(week.key)}
                                    aria-label={`${weekLabel(week.key)}: ${formatAmount(week[currency])} ${currencyLabel(currency)}`}
                                    aria-pressed={selected}
                                    className="group flex h-full min-w-0 flex-col justify-end gap-1.5 outline-none"
                                >
                                    <span className="flex min-h-1 flex-1 items-end">
                                        <span
                                            className={cn(
                                                'w-full rounded-t-md rounded-b-xs motion-safe:transition-[height,background-color,opacity] motion-safe:duration-700',
                                                week.closed
                                                    ? 'bg-primary'
                                                    : 'bg-primary/35',
                                                !selected &&
                                                    'opacity-70 group-hover:opacity-100',
                                                'ring-offset-background group-focus-visible:ring-2 group-focus-visible:ring-ring group-focus-visible:ring-offset-2',
                                            )}
                                            style={{
                                                height: `${Math.max(height * 100, week.count > 0 ? 3 : 1)}%`,
                                            }}
                                        />
                                    </span>
                                    <span
                                        className={cn(
                                            'truncate text-center text-[0.65rem] tabular-nums @md:text-xs',
                                            selected
                                                ? 'font-semibold text-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {weekLabel(week.key)}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        La barra más alta de este período: {compactAmount(max)}{' '}
                        {currencyLabel(currency)}.
                    </p>
                </>
            )}
        </Panel>
    );
}

function Legend() {
    return (
        <ul className="flex flex-col gap-1 text-xs text-muted-foreground @md:flex-row @md:gap-3">
            <li className="flex items-center gap-1.5">
                <span aria-hidden className="size-2.5 rounded-xs bg-primary" />{' '}
                Cerrada
            </li>
            <li className="flex items-center gap-1.5">
                <span
                    aria-hidden
                    className="size-2.5 rounded-xs bg-primary/35"
                />{' '}
                Abierta
            </li>
        </ul>
    );
}
