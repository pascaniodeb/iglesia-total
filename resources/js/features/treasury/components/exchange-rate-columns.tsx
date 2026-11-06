import type { DataTableColumn } from '@/components/data-table/types';
import { RateOriginBadge } from '@/features/treasury/components/rate-origin-badge';
import { formatRate, formatShortDate } from '@/features/treasury/rates';
import type { ExchangeRateRow } from '@/features/treasury/types';

const rate = (value: string | null) => (
    <span className="font-semibold tabular-nums">{formatRate(value)}</span>
);

export const exchangeRateColumns: DataTableColumn<ExchangeRateRow, 'date'>[] = [
    {
        key: 'date',
        header: 'Fecha',
        sortKey: 'date',
        mobile: 'primary',
        cell: (row) => (
            <span className="font-bold tracking-tight tabular-nums">
                {formatShortDate(row.date)}
            </span>
        ),
    },
    {
        key: 'usd',
        header: 'Bs por dólar',
        align: 'end',
        cell: (row) => rate(row.usd_rate),
    },
    {
        key: 'eur',
        header: 'Bs por euro',
        align: 'end',
        priority: 'secondary',
        cell: (row) => rate(row.eur_rate),
    },
    {
        key: 'trm',
        header: 'Pesos por dólar',
        align: 'end',
        priority: 'tertiary',
        cell: (row) => rate(row.trm_cop_usd),
    },
    {
        key: 'cop',
        header: 'Pesos por bolívar',
        align: 'end',
        cell: (row) => rate(row.cop_rate),
    },
    {
        key: 'origin',
        header: 'Origen',
        mobile: 'badge',
        cell: (row) => (
            <RateOriginBadge origin={row.origin} label={row.origin_label} />
        ),
    },
];
