import type { ReactNode } from 'react';
import { SortButton } from '@/components/data-table/sort-button';
import type { DataTableColumn } from '@/components/data-table/types';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { SortDirection } from '@/types';

/** Por ancho de la tabla (container queries). Clases literales: Tailwind solo genera las que ve. */
const PRIORITY = {
    secondary: 'hidden @5xl:table-cell',
    tertiary: 'hidden @6xl:table-cell',
} as const;

export type DataTableViewProps<TRow> = {
    rows: TRow[];
    columns: DataTableColumn<TRow>[];
    rowKey: (row: TRow) => string;
    rowActions?: (row: TRow) => ReactNode;
};

type DataTableDesktopProps<TRow> = DataTableViewProps<TRow> & {
    sort: string;
    direction: SortDirection;
    onSort: (sort: string) => void;
};

export function DataTableDesktop<TRow>({
    rows,
    columns,
    rowKey,
    rowActions,
    sort,
    direction,
    onSort,
}: DataTableDesktopProps<TRow>) {
    return (
        <Table>
            <TableHeader>
                <TableRow className="hover:bg-transparent">
                    {columns.map((column) => {
                        const state =
                            column.sortKey !== undefined &&
                            column.sortKey === sort
                                ? direction
                                : null;

                        return (
                            <TableHead
                                key={column.key}
                                aria-sort={
                                    state === null
                                        ? undefined
                                        : state === 'asc'
                                          ? 'ascending'
                                          : 'descending'
                                }
                                className={cn(
                                    'h-12 px-4 eyebrow text-muted-foreground',
                                    column.align === 'end' && 'text-right',
                                    column.priority &&
                                        PRIORITY[column.priority],
                                )}
                            >
                                {column.sortKey === undefined ? (
                                    column.header
                                ) : (
                                    <SortButton
                                        label={column.header}
                                        state={state}
                                        align={column.align}
                                        onClick={() =>
                                            onSort(column.sortKey as string)
                                        }
                                    />
                                )}
                            </TableHead>
                        );
                    })}
                    {rowActions && (
                        <TableHead className="w-14 px-4">
                            <span className="sr-only">Acciones</span>
                        </TableHead>
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={rowKey(row)}>
                        {columns.map((column) => (
                            <TableCell
                                key={column.key}
                                className={cn(
                                    // Data cells may wrap: a long text never pushes
                                    // the table out of its container.
                                    'h-14 px-4 text-base whitespace-normal',
                                    column.align === 'end' &&
                                        'text-right tabular-nums',
                                    column.priority &&
                                        PRIORITY[column.priority],
                                )}
                            >
                                {column.cell(row)}
                            </TableCell>
                        ))}
                        {rowActions && (
                            <TableCell className="px-4 text-right">
                                {rowActions(row)}
                            </TableCell>
                        )}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
