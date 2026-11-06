import { Eye } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import { ActivityLogDetailDialog } from '@/features/activity-log/components/activity-log-detail-dialog';
import type { ActivityLogRow } from '@/features/activity-log/types';

/** The only action on a log entry is reading it: the log is never edited. */
export function ActivityLogRowActions({ entry }: { entry: ActivityLogRow }) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <>
            <DataTableRowActions
                label={entry.summary}
                actions={[
                    {
                        label: 'Ver detalle',
                        icon: Eye,
                        onSelect: () => setIsOpen(true),
                    },
                ]}
            />
            <ActivityLogDetailDialog
                entry={entry}
                open={isOpen}
                onOpenChange={setIsOpen}
            />
        </>
    );
}
