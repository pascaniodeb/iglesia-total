import type { Severity } from '@/features/audits/types';
import { cn } from '@/lib/utils';

const LABEL = {
    critical: 'Crítico',
    warning: 'Advertencia',
    info: 'Informativo',
} as const;

const TONE = {
    critical: 'bg-negative/10 text-negative',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-muted text-muted-foreground',
} as const;

/** How much a finding of a check matters: critical and warning count against the audit, info never. */
export function SeverityBadge({ severity }: { severity: Severity }) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                TONE[severity],
            )}
        >
            {LABEL[severity]}
        </span>
    );
}
