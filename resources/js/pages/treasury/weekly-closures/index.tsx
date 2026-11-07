import { Head } from '@inertiajs/react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { NextWeekCard } from '@/features/treasury/components/next-week-card';
import { weeklyClosureColumns } from '@/features/treasury/components/weekly-closure-columns';
import type { NextWeek, WeeklyClosureRow } from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { index as weeklyClosuresIndex } from '@/routes/treasury/weekly-closures';
import type { Paginated, TableFilters } from '@/types';

type WeeklyClosuresIndexProps = {
    closures: Paginated<WeeklyClosureRow>;
    filters: TableFilters<'week'>;
    next: NextWeek | null;
};

export default function WeeklyClosuresIndex({
    closures,
    filters,
    next,
}: WeeklyClosuresIndexProps) {
    const { can } = usePermissions();
    const canManage = can('closures.manage');

    return (
        <>
            <Head title="Cierres semanales" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Paso 1 del mes"
                    title="Cierres semanales"
                    description="Las semanas se cierran en orden. Al cerrar, sus ofrendas quedan guardadas y bloqueadas, y la semana se sella en el libro sellado. Una semana cerrada no se reabre."
                />

                {next ? (
                    <NextWeekCard
                        key={next.week_key}
                        week={next}
                        canManage={canManage}
                    />
                ) : (
                    <section className="rounded-[2rem] border-2 border-dashed border-border p-8 text-muted-foreground">
                        Todavía no hay ofrendas registradas: no hay semanas para
                        cerrar.
                    </section>
                )}

                <ServerDataTable
                    url={weeklyClosuresIndex().url}
                    prop="closures"
                    data={closures}
                    filters={filters}
                    columns={weeklyClosureColumns(canManage)}
                    rowKey={(closure) => closure.id}
                    searchLabel="Buscar semana (2026-W45)"
                    withStatus={false}
                    fitViewport={false}
                    empty={{
                        title: 'Aún no hay semanas cerradas',
                        description:
                            'Las semanas que cierre aparecerán aquí con su sello.',
                    }}
                />
            </div>
        </>
    );
}

WeeklyClosuresIndex.layout = {
    breadcrumbs: [{ title: 'Cierres semanales', href: weeklyClosuresIndex() }],
};
