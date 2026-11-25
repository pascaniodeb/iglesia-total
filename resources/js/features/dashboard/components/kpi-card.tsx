import type { LucideIcon } from 'lucide-react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type KpiCardProps = {
    label: string;
    /** The figure, already formatted. */
    value: string;
    unit: string;
    icon: LucideIcon;
    /** A short sentence under the figure. */
    hint?: ReactNode;
    /** The change against the period before, as a whole percentage; null hides it. */
    change?: number | null;
    /** What a "good" change looks like: more offerings is good, more expenses is not. */
    tone?: 'brand' | 'positive' | 'warning';
};

const TONES = {
    brand: 'from-primary/12 text-primary',
    positive: 'from-positive/12 text-positive',
    warning: 'from-warning/20 text-warning-foreground',
} as const;

/** One headline number: what it is, how much, and whether it went up or down. */
export function KpiCard({
    label,
    value,
    unit,
    icon: Icon,
    hint,
    change,
    tone = 'brand',
}: KpiCardProps) {
    return (
        <article
            className={cn(
                '@container relative isolate flex min-w-0 flex-col gap-2.5 overflow-hidden rounded-2xl border bg-card p-4 shadow-xs sm:gap-3 sm:p-5',
                'motion-safe:transition-transform motion-safe:hover:-translate-y-0.5',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute inset-0 -z-10 bg-linear-to-br to-transparent',
                    TONES[tone],
                )}
            />
            <header className="flex items-center justify-between gap-3">
                <h3 className="min-w-0 text-xs font-medium text-muted-foreground sm:text-sm">
                    {label}
                </h3>
                <span
                    className={cn(
                        'hidden size-9 shrink-0 items-center justify-center rounded-xl bg-background/80 ring-1 ring-border sm:flex',
                        TONES[tone].split(' ')[1],
                    )}
                >
                    <Icon aria-hidden className="size-4.5" />
                </span>
            </header>
            <p className="flex min-w-0 flex-wrap items-baseline gap-x-2 tabular-nums">
                <span className="text-lg font-bold tracking-tight whitespace-nowrap @[9rem]:text-xl @[13rem]:text-2xl @[17rem]:text-3xl">
                    {value}
                </span>
                <span className="text-sm font-semibold text-muted-foreground">
                    {unit}
                </span>
            </p>
            <footer className="flex min-h-6 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground sm:text-sm">
                {change !== undefined && change !== null && (
                    <Change value={change} />
                )}
                {hint}
            </footer>
        </article>
    );
}

function Change({ value }: { value: number }) {
    const Icon = value > 0 ? ArrowUpRight : value < 0 ? ArrowDownRight : Minus;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                value > 0 && 'bg-positive/10 text-positive',
                value < 0 && 'bg-negative/10 text-negative',
                value === 0 && 'bg-muted text-muted-foreground',
            )}
        >
            <Icon aria-hidden className="size-3.5" />
            {value > 0 ? '+' : ''}
            {value}%<span className="sr-only"> frente al mes anterior</span>
        </span>
    );
}
