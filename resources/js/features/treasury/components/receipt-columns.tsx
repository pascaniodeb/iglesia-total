import type { DataTableColumn } from '@/components/data-table/types';
import { Badge } from '@/components/ui/badge';
import { formatShortDate } from '@/features/treasury/rates';
import type { ReceiptRow, ReceiptSortKey } from '@/features/treasury/types';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { cn } from '@/lib/utils';

const amount = (value: string, isVoid: boolean) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <span
            className={cn(
                'font-semibold whitespace-nowrap tabular-nums',
                isVoid && 'line-through decoration-2',
            )}
        >
            {formatAmount(value)}
        </span>
    );

/** Columns of the receipt book. Each currency in its own column: never converted. */
export const receiptColumns: DataTableColumn<ReceiptRow, ReceiptSortKey>[] = [
    {
        key: 'number',
        header: 'N.º',
        sortKey: 'number',
        cell: (receipt) => (
            <span
                className={cn(
                    'font-mono font-bold tabular-nums',
                    receipt.is_void && 'line-through decoration-2',
                )}
            >
                {receipt.number_label}
            </span>
        ),
    },
    {
        key: 'concept',
        header: 'Concepto',
        mobile: 'primary',
        cell: (receipt) => (
            <span className="flex flex-col">
                <span
                    className={cn(
                        'font-semibold break-words',
                        receipt.is_void && 'line-through decoration-2',
                    )}
                >
                    {receipt.holder ?? receipt.counterparty ?? 'Sin titular'}
                </span>
                <span className="text-xs break-words text-muted-foreground">
                    {receipt.type_label}
                    {receipt.account_code && ` · ${receipt.account_code}`}
                    {receipt.concept && ` · ${receipt.concept}`}
                </span>
            </span>
        ),
    },
    {
        key: 'state',
        header: 'Estado',
        mobile: 'badge',
        cell: (receipt) =>
            receipt.is_void ? (
                <Badge variant="destructive">Anulado</Badge>
            ) : (
                <Badge variant="secondary">Emitido</Badge>
            ),
    },
    {
        key: 'date',
        header: 'Fecha',
        sortKey: 'date',
        priority: 'secondary',
        cell: (receipt) => (
            <span className="font-semibold whitespace-nowrap tabular-nums">
                {formatShortDate(receipt.issued_on)}
            </span>
        ),
    },
    {
        key: 'ves',
        header: 'Bs',
        sortKey: 'ves',
        align: 'end',
        cell: (receipt) => amount(receipt.amount_ves, receipt.is_void),
    },
    {
        key: 'usd',
        header: 'USD',
        sortKey: 'usd',
        align: 'end',
        cell: (receipt) => amount(receipt.amount_usd, receipt.is_void),
    },
    {
        key: 'cop',
        header: 'COP',
        sortKey: 'cop',
        align: 'end',
        cell: (receipt) => amount(receipt.amount_cop, receipt.is_void),
    },
];
