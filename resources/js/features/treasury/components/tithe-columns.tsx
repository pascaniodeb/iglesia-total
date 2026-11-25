import type { DataTableColumn } from '@/components/data-table/types';
import { formatShortDate } from '@/features/treasury/rates';
import type { TitheRow, TitheSortKey } from '@/features/treasury/types';
import { formatAmount, isZeroAmount } from '@/lib/money';

const amount = (value: string) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <span className="font-semibold whitespace-nowrap tabular-nums">
            {formatAmount(value)}
        </span>
    );

/** Columns of the tithes book. Each currency in its own column: never converted. */
export const titheColumns: DataTableColumn<TitheRow, TitheSortKey>[] = [
    {
        key: 'date',
        header: 'Fecha',
        sortKey: 'date',
        cell: (tithe) => (
            <span className="font-semibold whitespace-nowrap tabular-nums">
                {formatShortDate(tithe.tithe_date)}
            </span>
        ),
    },
    {
        key: 'member',
        header: 'Miembro',
        mobile: 'primary',
        cell: (tithe) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">
                    {tithe.member?.name ?? 'Miembro desconocido'}
                </span>
                {tithe.notes && (
                    <span className="text-xs break-words text-muted-foreground">
                        {tithe.notes}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'ves',
        header: 'Bs',
        sortKey: 'ves',
        align: 'end',
        cell: (t) => amount(t.amount_ves),
    },
    {
        key: 'usd',
        header: 'USD',
        sortKey: 'usd',
        align: 'end',
        cell: (t) => amount(t.amount_usd),
    },
    {
        key: 'cop',
        header: 'COP',
        sortKey: 'cop',
        align: 'end',
        cell: (t) => amount(t.amount_cop),
    },
];
