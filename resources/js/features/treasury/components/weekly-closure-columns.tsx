import { router } from '@inertiajs/react';
import { ShieldCheck, ShieldAlert } from 'lucide-react';
import type { DataTableColumn } from '@/components/data-table/types';
import { Button } from '@/components/ui/button';
import { formatShortDate } from '@/features/treasury/rates';
import type { WeeklyClosureRow } from '@/features/treasury/types';
import { formatAmount } from '@/lib/money';
import { seal } from '@/routes/treasury/weekly-closures';

const amount = (value: string) => (
    <span className="font-semibold whitespace-nowrap tabular-nums">
        {formatAmount(value)}
    </span>
);

function SealCell({
    closure,
    canManage,
}: {
    closure: WeeklyClosureRow;
    canManage: boolean;
}) {
    if (closure.seal) {
        return (
            <span
                className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold whitespace-nowrap text-primary"
                title={closure.seal.hash}
            >
                <ShieldCheck aria-hidden className="size-3.5" />
                Bloque {closure.seal.height}
            </span>
        );
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-3 py-1 text-xs font-semibold whitespace-nowrap text-warning">
                <ShieldAlert aria-hidden className="size-3.5" />
                Sello pendiente
            </span>
            {canManage && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            seal(),
                            { week_key: closure.week_key },
                            { preserveScroll: true },
                        )
                    }
                >
                    Sellar ahora
                </Button>
            )}
        </span>
    );
}

export function weeklyClosureColumns(
    canManage: boolean,
): DataTableColumn<WeeklyClosureRow, 'week'>[] {
    return [
        {
            key: 'week',
            header: 'Semana',
            sortKey: 'week',
            mobile: 'primary',
            cell: (closure) => (
                <span className="flex flex-col">
                    <span className="font-semibold whitespace-nowrap tabular-nums">
                        {formatShortDate(closure.week_start)} al{' '}
                        {formatShortDate(closure.week_end)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {closure.week_key} · {closure.offerings_count}{' '}
                        {closure.offerings_count === 1 ? 'ofrenda' : 'ofrendas'}
                    </span>
                </span>
            ),
        },
        {
            key: 'ves',
            header: 'Bs',
            align: 'end',
            cell: (c) => amount(c.total_ves),
        },
        {
            key: 'usd',
            header: 'USD',
            align: 'end',
            cell: (c) => amount(c.total_usd),
        },
        {
            key: 'cop',
            header: 'COP',
            align: 'end',
            cell: (c) => amount(c.total_cop),
        },
        {
            key: 'seal',
            header: 'Libro sellado',
            mobile: 'badge',
            cell: (closure) => (
                <SealCell closure={closure} canManage={canManage} />
            ),
        },
    ];
}
