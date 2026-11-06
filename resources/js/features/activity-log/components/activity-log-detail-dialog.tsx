import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { ActivityCategoryBadge } from '@/features/activity-log/components/activity-category-badge';
import type {
    ActivityChange,
    ActivityLogRow,
} from '@/features/activity-log/types';
import { formatDateTime } from '@/lib/date';

type ActivityLogDetailDialogProps = {
    entry: ActivityLogRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const empty = <span className="text-muted-foreground">—</span>;

function formatValue(value: ActivityChange['from']) {
    if (value === null) {
        return empty;
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    return String(value);
}

function ChangeValue({
    change,
    side,
}: {
    change: ActivityChange;
    side: 'from' | 'to';
}) {
    const value = change[side];

    if (change.protected && value !== null) {
        return (
            <span className="text-muted-foreground italic">Dato protegido</span>
        );
    }

    return <span className="break-words">{formatValue(value)}</span>;
}

/**
 * Everything one entry recorded: what changed (personal data shows as protected, it was never
 * stored), where it came from and its link in the hash chain.
 */
export function ActivityLogDetailDialog({
    entry,
    open,
    onOpenChange,
}: ActivityLogDetailDialogProps) {
    const actor = entry.actor_role
        ? `${entry.actor} · ${entry.actor_role}`
        : entry.actor;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85dvh] flex-col gap-5 sm:max-w-2xl">
                <div className="flex flex-col gap-2 pr-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <ActivityCategoryBadge entry={entry} />
                        <span className="text-sm text-muted-foreground">
                            {formatDateTime(entry.occurred_at)}
                        </span>
                    </div>
                    <DialogTitle className="text-lg leading-snug">
                        {entry.summary}
                    </DialogTitle>
                    <DialogDescription>{actor}</DialogDescription>
                </div>

                <div className="-mx-6 min-h-0 flex-1 overflow-y-auto px-6">
                    {entry.changes.length > 0 ? (
                        <table className="w-full table-fixed text-sm">
                            <caption className="sr-only">
                                Cambios registrados
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                                    <th
                                        scope="col"
                                        className="w-1/3 py-2 pr-3 font-medium"
                                    >
                                        Campo
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-3 font-medium"
                                    >
                                        Antes
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 font-medium"
                                    >
                                        Después
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entry.changes.map((change) => (
                                    <tr
                                        key={change.field}
                                        className="border-b align-top last:border-0"
                                    >
                                        <th
                                            scope="row"
                                            className="py-2 pr-3 text-left font-medium"
                                        >
                                            {change.label}
                                        </th>
                                        <td className="py-2 pr-3">
                                            <ChangeValue
                                                change={change}
                                                side="from"
                                            />
                                        </td>
                                        <td className="py-2">
                                            <ChangeValue
                                                change={change}
                                                side="to"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Este registro no guarda cambios de campos.
                        </p>
                    )}
                </div>

                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 border-t pt-4 text-xs">
                    <dt className="text-muted-foreground">Origen</dt>
                    <dd className="break-words">
                        {[entry.route, entry.ip].filter(Boolean).join(' · ') ||
                            '—'}
                    </dd>
                    <dt className="text-muted-foreground">Operación</dt>
                    <dd className="font-mono break-all">
                        {entry.request_id ?? '—'}
                    </dd>
                    <dt className="text-muted-foreground">Eslabón</dt>
                    <dd className="font-mono break-all">
                        #{entry.seq} · {entry.hash}
                    </dd>
                </dl>
            </DialogContent>
        </Dialog>
    );
}
