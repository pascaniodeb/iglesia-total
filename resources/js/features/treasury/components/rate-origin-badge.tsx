import type { RateOrigin } from '@/features/treasury/types';
import { cn } from '@/lib/utils';

const STYLES: Record<RateOrigin, string> = {
    market: 'bg-primary/10 text-primary',
    manual: 'bg-warning/15 text-warning',
    legacy: 'bg-muted text-muted-foreground',
};

/** Where the rate came from: the official sources, a manual correction or the old system. */
export function RateOriginBadge({
    origin,
    label,
}: {
    origin: RateOrigin;
    label: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap',
                STYLES[origin],
            )}
        >
            {label}
        </span>
    );
}
