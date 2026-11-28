import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { DataTableColumn } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/date';
import { index as activityLogIndex, legacy } from '@/routes/activity-log';
import type { Paginated, TableFilters } from '@/types';

type LegacyRow = {
    id: string;
    legacy_id: number;
    occurred_at: string;
    actor: string;
    actor_role: string;
    action: string;
    entity: string;
    summary: string;
    ip: string | null;
};

type LegacyProps = {
    entries: Paginated<LegacyRow>;
    filters: TableFilters<'occurred'>;
};

const count = new Intl.NumberFormat('es-VE');

const columns: DataTableColumn<LegacyRow, 'occurred'>[] = [
    {
        key: 'summary',
        header: 'Qué se hizo',
        mobile: 'primary',
        cell: (entry) => (
            <span className="font-semibold break-words">{entry.summary}</span>
        ),
    },
    {
        key: 'actor',
        header: 'Quién',
        cell: (entry) => (
            <span className="flex flex-col">
                <span className="break-words">{entry.actor}</span>
                <span className="text-xs text-muted-foreground capitalize">
                    {entry.actor_role}
                </span>
            </span>
        ),
    },
    {
        key: 'occurred',
        header: 'Cuándo',
        sortKey: 'occurred',
        cell: (entry) => (
            <span className="whitespace-nowrap">
                {formatDateTime(entry.occurred_at)}
            </span>
        ),
    },
    {
        key: 'action',
        header: 'Acción',
        priority: 'secondary',
        mobile: 'badge',
        cell: (entry) => (
            <span className="inline-flex rounded-full bg-muted px-3 py-1 font-mono text-xs whitespace-nowrap">
                {entry.action} · {entry.entity}
            </span>
        ),
    },
];

export default function LegacyActivityLog({ entries, filters }: LegacyProps) {
    return (
        <>
            <Head title="Bitácora anterior" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad"
                    title="Bitácora anterior"
                    description="Lo que se hizo en el sistema anterior, tal como quedó registrado. Se archivó sin cambios y no se puede modificar ni borrar; el Centro de integridad comprueba que sigue siendo exactamente lo que se archivó."
                    meta={`${count.format(entries.total)} ${entries.total === 1 ? 'registro' : 'registros'}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={activityLogIndex()} prefetch>
                                <ArrowLeft aria-hidden />
                                Bitácora
                            </Link>
                        </Button>
                    }
                />
                <ServerDataTable
                    url={legacy().url}
                    prop="entries"
                    data={entries}
                    filters={filters}
                    columns={columns}
                    rowKey={(entry) => entry.id}
                    searchLabel="Buscar por persona o acción"
                    withStatus={false}
                    empty={{
                        title: 'No hay registros archivados',
                        description:
                            'La bitácora del sistema anterior se archiva al migrar los datos.',
                    }}
                />
            </div>
        </>
    );
}

LegacyActivityLog.layout = {
    breadcrumbs: [
        { title: 'Bitácora', href: activityLogIndex() },
        { title: 'Bitácora anterior', href: legacy() },
    ],
};
