import type { DataTableColumn } from '@/components/data-table/types';
import { ActivityCategoryBadge } from '@/features/activity-log/components/activity-category-badge';
import type {
    ActivityLogRow,
    ActivityLogSortKey,
} from '@/features/activity-log/types';
import { formatDateTime } from '@/lib/date';

/**
 * Columns of the activity log. On narrow containers each entry becomes a card with what was
 * done as the title and the category as a badge.
 */
export const activityLogColumns: DataTableColumn<
    ActivityLogRow,
    ActivityLogSortKey
>[] = [
    {
        key: 'summary',
        header: 'Qué se hizo',
        mobile: 'primary',
        cell: (entry) => (
            <span className="font-semibold break-words">{entry.summary}</span>
        ),
    },
    {
        key: 'actor',
        header: 'Quién',
        cell: (entry) => (
            <span className="flex flex-col">
                <span className="break-words">{entry.actor}</span>
                {entry.actor_role && (
                    <span className="text-xs text-muted-foreground">
                        {entry.actor_role}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'occurred',
        header: 'Cuándo',
        sortKey: 'occurred',
        cell: (entry) => (
            <time dateTime={entry.occurred_at} className="whitespace-nowrap">
                {formatDateTime(entry.occurred_at)}
            </time>
        ),
    },
    {
        key: 'category',
        header: 'Tipo',
        mobile: 'badge',
        cell: (entry) => <ActivityCategoryBadge entry={entry} />,
    },
];
