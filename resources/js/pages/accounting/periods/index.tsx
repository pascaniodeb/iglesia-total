import { Head, router } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck } from 'lucide-react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { DataTableColumn } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { NextPeriodCard } from '@/features/accounting/components/next-period-card';
import type {
    AccountingPeriodRow,
    NextPeriod,
} from '@/features/accounting/types';
import { usePermissions } from '@/hooks/use-permissions';
import { formatAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { index as periodsIndex, seal } from '@/routes/accounting/periods';
import type { Paginated, TableFilters } from '@/types';

type PeriodsIndexProps = {
    periods: Paginated<AccountingPeriodRow>;
    filters: TableFilters<'month'>;
    next: NextPeriod | null;
};

const closing = (period: AccountingPeriodRow, code: 'VES' | 'USD' | 'COP') => (
    <span className="font-semibold whitespace-nowrap tabular-nums">
        {formatAmount(period.currencies[code].closing)}
    </span>
);

function columns(
    canManage: boolean,
): DataTableColumn<AccountingPeriodRow, 'month'>[] {
    return [
        {
            key: 'month',
            header: 'Mes',
            sortKey: 'month',
            mobile: 'primary',
            cell: (period) => (
                <span className="font-semibold">
                    {formatMonth(period.month)}
                </span>
            ),
        },
        {
            key: 'ves',
            header: 'Saldo Bs',
            align: 'end',
            cell: (p) => closing(p, 'VES'),
        },
        {
            key: 'usd',
            header: 'Saldo USD',
            align: 'end',
            cell: (p) => closing(p, 'USD'),
        },
        {
            key: 'cop',
            header: 'Saldo COP',
            align: 'end',
            cell: (p) => closing(p, 'COP'),
        },
        {
            key: 'seal',
            header: 'Libro sellado',
            mobile: 'badge',
            cell: (period) =>
                period.seal ? (
                    <span
                        className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold whitespace-nowrap text-primary"
                        title={period.seal.hash}
                    >
                        <ShieldCheck aria-hidden className="size-3.5" />
                        Bloque {period.seal.height}
                    </span>
                ) : (
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
                                        { month: period.month },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Sellar ahora
                            </Button>
                        )}
                    </span>
                ),
        },
    ];
}

export default function PeriodsIndex({
    periods,
    filters,
    next,
}: PeriodsIndexProps) {
    const { can } = usePermissions();
    const canManage = can('accounting.manage');

    return (
        <>
            <Head title="Cierre contable" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Contabilidad · Paso 4 del mes"
                    title="Cierre contable"
                    description="Con las ofrendas del mes asentadas y el mes terminado, se cierra la contabilidad: se guardan los saldos, el mes se sella y el siguiente arranca con ese saldo anterior."
                />

                {next ? (
                    <NextPeriodCard
                        key={next.month}
                        period={next}
                        canManage={canManage}
                    />
                ) : (
                    <section className="rounded-[2rem] border-2 border-dashed border-border p-8 text-muted-foreground">
                        Todavía no hay asientos: no hay meses para cerrar.
                    </section>
                )}

                <ServerDataTable
                    url={periodsIndex().url}
                    prop="periods"
                    data={periods}
                    filters={filters}
                    columns={columns(canManage)}
                    rowKey={(period) => period.id}
                    searchLabel="Buscar mes (2026-10)"
                    withStatus={false}
                    fitViewport={false}
                    empty={{
                        title: 'Aún no hay meses cerrados',
                        description:
                            'Los meses que cierre aparecerán aquí con su saldo final y su sello.',
                    }}
                />
            </div>
        </>
    );
}

PeriodsIndex.layout = {
    breadcrumbs: [{ title: 'Cierre contable', href: periodsIndex() }],
};
