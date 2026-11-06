import type { DataTableViewProps } from '@/components/data-table/data-table-desktop';

/**
 * Rows as cards below `md`: title, badges and label/value pairs. Never overflows horizontally
 * (long values wrap).
 */
export function DataTableMobileList<TRow>({
    rows,
    columns,
    rowKey,
    rowActions,
}: DataTableViewProps<TRow>) {
    const role = (column: (typeof columns)[number]) =>
        column.mobile ?? 'detail';
    const primary = columns.filter((column) => role(column) === 'primary');
    const badges = columns.filter((column) => role(column) === 'badge');
    const details = columns.filter((column) => role(column) === 'detail');

    return (
        <ul className="divide-y-2 divide-border">
            {rows.map((row) => (
                <li key={rowKey(row)} className="flex flex-col gap-3 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0 text-lg break-words">
                            {primary.map((column) => (
                                <div key={column.key}>{column.cell(row)}</div>
                            ))}
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            {badges.map((column) => (
                                <div key={column.key}>{column.cell(row)}</div>
                            ))}
                            {rowActions?.(row)}
                        </div>
                    </div>
                    {details.length > 0 && (
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                            {details.map((column) => (
                                <div key={column.key} className="min-w-0">
                                    <dt className="eyebrow text-muted-foreground">
                                        {column.header}
                                    </dt>
                                    <dd className="text-base break-words">
                                        {column.cell(row)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </li>
            ))}
        </ul>
    );
}
