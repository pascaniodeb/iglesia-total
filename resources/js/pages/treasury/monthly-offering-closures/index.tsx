import { Head } from '@inertiajs/react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { DataTableColumn } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { NextMonthCard } from '@/features/treasury/components/next-month-card';
import type {
    MonthlyOfferingClosureRow,
    NextMonth,
} from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { formatAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { index as monthlyIndex } from '@/routes/treasury/monthly-offering-closures';
import type { Paginated, TableFilters } from '@/types';

type MonthlyOfferingClosuresIndexProps = {
    closures: Paginated<MonthlyOfferingClosureRow>;
    filters: TableFilters<'month'>;
    next: NextMonth | null;
};

const amount = (value: string) => (
    <span className="font-semibold whitespace-nowrap tabular-nums">
        {formatAmount(value)}
    </span>
);

const columns: DataTableColumn<MonthlyOfferingClosureRow, 'month'>[] = [
    {
        key: 'month',
        header: 'Mes',
        sortKey: 'month',
        mobile: 'primary',
        cell: (closure) => (
            <span className="flex flex-col">
                <span className="font-semibold">
                    {formatMonth(closure.month)}
                </span>
                <span className="text-xs text-muted-foreground">
                    {closure.offerings_count}{' '}
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
        key: 'entry',
        header: 'Libro Diario',
        mobile: 'badge',
        cell: (closure) =>
            closure.journal_entry_id ? (
                <span className="inline-flex rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold whitespace-nowrap text-primary">
                    Asentado
                </span>
            ) : (
                <span className="text-xs text-muted-foreground">
                    Sin ofrendas
                </span>
            ),
    },
];

export default function MonthlyOfferingClosuresIndex({
    closures,
    filters,
    next,
}: MonthlyOfferingClosuresIndexProps) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Cierre de ofrendas del mes" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Paso 2 del mes"
                    title="Cierre de ofrendas del mes"
                    description="Cuando todas las semanas del mes están cerradas, sus ofrendas se asientan en el Libro Diario, una vez y para siempre."
                />

                {next ? (
                    <NextMonthCard
                        key={next.month}
                        month={next}
                        canManage={can('closures.manage')}
                    />
                ) : (
                    <section className="rounded-[2rem] border-2 border-dashed border-border p-8 text-muted-foreground">
                        Todavía no hay ofrendas registradas: no hay meses para
                        cerrar.
                    </section>
                )}

                <ServerDataTable
                    url={monthlyIndex().url}
                    prop="closures"
                    data={closures}
                    filters={filters}
                    columns={columns}
                    rowKey={(closure) => closure.id}
                    searchLabel="Buscar mes (2026-11)"
                    withStatus={false}
                    fitViewport={false}
                    empty={{
                        title: 'Aún no hay meses cerrados',
                        description:
                            'Los meses cuyas ofrendas cierre aparecerán aquí.',
                    }}
                />
            </div>
        </>
    );
}

MonthlyOfferingClosuresIndex.layout = {
    breadcrumbs: [
        { title: 'Cierre de ofrendas del mes', href: monthlyIndex() },
    ],
};
