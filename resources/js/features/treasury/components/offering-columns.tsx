import type { DataTableColumn } from '@/components/data-table/types';
import { formatShortDate } from '@/features/treasury/rates';
import type { OfferingRow, OfferingSortKey } from '@/features/treasury/types';
import { formatAmount, isZeroAmount } from '@/lib/money';

const amount = (value: string) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <span className="font-semibold whitespace-nowrap tabular-nums">
            {formatAmount(value)}
        </span>
    );

/** Columns of the offerings registry. Each currency in its own column: never converted. */
export const offeringColumns: DataTableColumn<OfferingRow, OfferingSortKey>[] =
    [
        {
            key: 'date',
            header: 'Fecha',
            sortKey: 'date',
            cell: (offering) => (
                <span className="flex flex-col">
                    <span className="font-semibold whitespace-nowrap tabular-nums">
                        {formatShortDate(offering.offering_date)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Semana {offering.week_key.slice(-2)}
                        {offering.is_locked && ' · cerrada'}
                    </span>
                </span>
            ),
        },
        {
            key: 'member',
            header: 'Miembro',
            mobile: 'primary',
            cell: (offering) => (
                <span className="flex flex-col">
                    <span className="font-semibold break-words">
                        {offering.member?.name ?? 'Ofrenda anónima'}
                    </span>
                    {offering.concept && (
                        <span className="text-xs break-words text-muted-foreground">
                            {offering.concept}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'category',
            header: 'Categoría',
            priority: 'secondary',
            cell: (offering) => offering.category,
        },
        {
            key: 'ves',
            header: 'Bs',
            sortKey: 'ves',
            align: 'end',
            cell: (o) => amount(o.amount_ves),
        },
        {
            key: 'usd',
            header: 'USD',
            sortKey: 'usd',
            align: 'end',
            cell: (o) => amount(o.amount_usd),
        },
        {
            key: 'cop',
            header: 'COP',
            sortKey: 'cop',
            align: 'end',
            cell: (o) => amount(o.amount_cop),
        },
    ];
