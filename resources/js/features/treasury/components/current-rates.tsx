import { RateOriginBadge } from '@/features/treasury/components/rate-origin-badge';
import { formatLongDate, formatRate } from '@/features/treasury/rates';
import type { ExchangeRateRow } from '@/features/treasury/types';

type CurrentRatesProps = {
    rate: ExchangeRateRow | null;
    today: string;
};

/**
 * The rate in force, large: the dollar as the main figure and the rest as support. If the
 * latest one is from another day, it says so.
 */
export function CurrentRates({ rate, today }: CurrentRatesProps) {
    if (rate === null) {
        return (
            <section className="rounded-[2rem] border-2 border-dashed border-border p-8 text-muted-foreground">
                Todavía no hay tasas. Actualícelas desde el BCV o regístrelas a
                mano.
            </section>
        );
    }

    const isStale = rate.date !== today;

    return (
        <section
            aria-label="Tasa vigente"
            className="grid grid-cols-1 gap-6 rounded-[2rem] border-2 border-border bg-card p-6 shadow-sm md:p-8 lg:grid-cols-[1.2fr_2fr]"
        >
            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                        Tasa vigente
                    </span>
                    <RateOriginBadge
                        origin={rate.origin}
                        label={rate.origin_label}
                    />
                </div>
                <p className="text-sm text-muted-foreground first-letter:uppercase">
                    {formatLongDate(rate.date)}
                </p>
                <p className="flex flex-wrap items-baseline gap-2">
                    <span className="text-5xl font-black tracking-tight tabular-nums md:text-6xl">
                        {formatRate(rate.usd_rate)}
                    </span>
                    <span className="text-lg font-semibold text-muted-foreground">
                        Bs por dólar
                    </span>
                </p>
                {isStale && (
                    <p className="text-sm font-semibold text-warning">
                        No es de hoy: se usa la última disponible hasta que se
                        actualice.
                    </p>
                )}
            </div>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <RateFact label="Bs por euro" value={rate.eur_rate} />
                <RateFact
                    label="Pesos por dólar (TRM)"
                    value={rate.trm_cop_usd}
                />
                <RateFact label="Pesos por bolívar" value={rate.cop_rate} />
            </dl>
        </section>
    );
}

function RateFact({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex flex-col justify-end gap-1 rounded-3xl bg-muted/60 p-5">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-black tracking-tight tabular-nums">
                {formatRate(value)}
            </dd>
        </div>
    );
}
