import { Head } from '@inertiajs/react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { FacetOption } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { activityLogColumns } from '@/features/activity-log/components/activity-log-columns';
import { ActivityLogRowActions } from '@/features/activity-log/components/activity-log-row-actions';
import type {
    ActivityLogFilters,
    ActivityLogRow,
} from '@/features/activity-log/types';
import { index as activityLogIndex } from '@/routes/activity-log';
import type { Paginated } from '@/types';

type ActivityLogIndexProps = {
    entries: Paginated<ActivityLogRow>;
    filters: ActivityLogFilters;
    categories: FacetOption[];
};

const count = new Intl.NumberFormat('es-VE');

export default function ActivityLogIndex({
    entries,
    filters,
    categories,
}: ActivityLogIndexProps) {
    return (
        <>
            <Head title="Bitácora" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad"
                    title="Bitácora"
                    description="Quién hizo qué y cuándo. Los registros no se pueden modificar ni borrar."
                    meta={`${count.format(entries.total)} ${entries.total === 1 ? 'registro' : 'registros'}`}
                />
                <ServerDataTable
                    url={activityLogIndex().url}
                    prop="entries"
                    data={entries}
                    filters={filters}
                    columns={activityLogColumns}
                    rowKey={(entry) => entry.id}
                    rowActions={(entry) => (
                        <ActivityLogRowActions entry={entry} />
                    )}
                    searchLabel="Buscar por persona o acción"
                    withStatus={false}
                    facets={[
                        {
                            key: 'category',
                            label: 'Tipo',
                            allLabel: 'Todos los tipos',
                            options: categories,
                        },
                    ]}
                    empty={{
                        title: 'Aún no hay registros',
                        description:
                            'Cada acción sobre los datos, cada inicio de sesión y cada verificación quedará registrada aquí.',
                    }}
                />
            </div>
        </>
    );
}

ActivityLogIndex.layout = {
    breadcrumbs: [{ title: 'Bitácora', href: activityLogIndex() }],
};
