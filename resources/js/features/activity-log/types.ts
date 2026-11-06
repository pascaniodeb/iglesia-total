import type { TableFilters } from '@/types';

/** Mirrors App\Modules\Integrity\Domain\Enums\ActivityCategory. */
export type ActivityCategory = 'data' | 'security' | 'access' | 'system';

/** One recorded change, with the field's Spanish label. */
export type ActivityChange = {
    field: string;
    label: string;
    from: string | number | boolean | null;
    to: string | number | boolean | null;
    /** The values were never written to the log (personal data). */
    protected: boolean;
};

/** A row of the activity log listing (ActivityLogListing::row()). */
export type ActivityLogRow = {
    id: string;
    seq: number;
    occurred_at: string;
    category: ActivityCategory;
    category_label: string;
    actor: string;
    actor_role: string | null;
    summary: string;
    action: string;
    changes: ActivityChange[];
    ip: string | null;
    route: string | null;
    request_id: string | null;
    hash: string;
};

export type ActivityLogSortKey = 'occurred';

export type ActivityLogFilters = TableFilters<ActivityLogSortKey> & {
    category: string;
};
