import { Head, router } from '@inertiajs/react';
import { FileText, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { blockColumns } from '@/features/integrity/components/block-columns';
import { AnchorsPanel } from '@/features/integrity/components/anchors-panel';
import { ChainFindings } from '@/features/integrity/components/chain-findings';
import { ChecksPanel } from '@/features/integrity/components/checks-panel';
import { HistoryPanel } from '@/features/integrity/components/history-panel';
import { StatusBanner } from '@/features/integrity/components/status-banner';
import type {
    AnchorsSummary,
    BlockRow,
    BlockSortKey,
    CheckResult,
    HistoryEntry,
    IntegrityStatus,
} from '@/features/integrity/types';
import {
    certificate,
    index as integrityIndex,
    verify,
} from '@/routes/integrity';
import type { Paginated, TableFilters } from '@/types';

type IntegrityIndexProps = {
    status: IntegrityStatus;
    checks: CheckResult[];
    anchors: AnchorsSummary;
    blocks: Paginated<BlockRow>;
    filters: TableFilters<BlockSortKey>;
    kinds: string[];
    history: HistoryEntry[];
};

const KIND_LABEL = {
    genesis: 'Génesis',
    weekly: 'Semanas',
    monthly: 'Meses',
} as Record<string, string>;

export default function IntegrityIndex({
    status,
    checks,
    anchors,
    blocks,
    filters,
    kinds,
    history,
}: IntegrityIndexProps) {
    const [isVerifying, setIsVerifying] = useState(false);

    return (
        <>
            <Head title="Centro de integridad" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad"
                    title="Centro de integridad"
                    description="Cada semana y cada mes cerrados se sellan en una cadena de bloques que no se puede reescribir sin que se note. Aquí se ve si la cadena está íntegra y si los libros de hoy siguen diciendo lo mismo que cuando se cerraron."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={certificate().url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <FileText aria-hidden />
                                    Certificado (PDF)
                                </a>
                            </Button>
                            <Button
                                disabled={isVerifying}
                                onClick={() =>
                                    router.post(
                                        verify(),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setIsVerifying(true),
                                            onFinish: () =>
                                                setIsVerifying(false),
                                        },
                                    )
                                }
                            >
                                <ShieldCheck aria-hidden />
                                {isVerifying
                                    ? 'Verificando…'
                                    : 'Verificar ahora'}
                            </Button>
                        </>
                    }
                />

                <StatusBanner status={status} checks={checks} />

                <div className="grid gap-6 xl:grid-cols-2">
                    <ChecksPanel checks={checks} />
                    <div className="flex min-w-0 flex-col gap-6">
                        <AnchorsPanel anchors={anchors} />
                        <ChainFindings check={status.last_chain_check} />
                        <HistoryPanel history={history} />
                    </div>
                </div>

                <section
                    className="flex min-w-0 flex-col gap-3"
                    aria-label="Bloques sellados"
                >
                    <h2 className="text-base font-semibold">
                        Bloques sellados
                    </h2>
                    <ServerDataTable
                        url={integrityIndex().url}
                        prop="blocks"
                        data={blocks}
                        filters={filters}
                        columns={blockColumns}
                        rowKey={(block) => block.id}
                        searchLabel="Buscar por período o huella"
                        withStatus={false}
                        fitViewport={false}
                        facets={[
                            {
                                key: 'kind',
                                label: 'Tipo',
                                allLabel: 'Todos los tipos',
                                options: kinds.map((kind) => ({
                                    value: kind,
                                    label: KIND_LABEL[kind] ?? kind,
                                })),
                            },
                        ]}
                        empty={{
                            title: 'Aún no hay bloques',
                            description:
                                'El primer bloque se crea al abrir el libro sellado; cada cierre agrega uno.',
                        }}
                    />
                </section>
            </div>
        </>
    );
}

IntegrityIndex.layout = {
    breadcrumbs: [{ title: 'Centro de integridad', href: integrityIndex() }],
};
