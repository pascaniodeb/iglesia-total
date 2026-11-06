import { cn } from '@/lib/utils';

type StatusBadgeProps = {
    active: boolean;
    /** Label of the active state. */
    activeLabel?: string;
    /** Label of the inactive state. */
    inactiveLabel?: string;
};

export function StatusBadge({
    active,
    activeLabel = 'Active',
    inactiveLabel = 'Inactive',
}: StatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                active
                    ? 'bg-primary/10 text-primary'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-1.5 rounded-full',
                    active ? 'bg-primary' : 'bg-muted-foreground/60',
                )}
            />
            {active ? activeLabel : inactiveLabel}
        </span>
    );
}
