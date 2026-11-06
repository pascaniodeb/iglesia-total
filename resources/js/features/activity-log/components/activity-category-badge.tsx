import type { ActivityLogRow } from '@/features/activity-log/types';
import { cn } from '@/lib/utils';

/** Tone of each category. Security stands out: it is what a comptroller looks for first. */
const TONES: Record<ActivityLogRow['category'], string> = {
    data: 'bg-primary/10 text-primary',
    security: 'bg-destructive-foreground/10 text-destructive-foreground',
    access: 'bg-muted text-foreground',
    system: 'bg-muted text-muted-foreground',
};

export function ActivityCategoryBadge({
    entry,
}: {
    entry: Pick<ActivityLogRow, 'category' | 'category_label'>;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap',
                TONES[entry.category],
            )}
        >
            {entry.category_label}
        </span>
    );
}
