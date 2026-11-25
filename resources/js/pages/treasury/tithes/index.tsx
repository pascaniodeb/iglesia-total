import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { titheColumns } from '@/features/treasury/components/tithe-columns';
import { TitheFormDialog } from '@/features/treasury/components/tithe-form-dialog';
import { TitheRowActions } from '@/features/treasury/components/tithe-row-actions';
import type {
    OfferingTotals,
    TitheFilters,
    TitheRow,
} from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { formatAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { index as tithesIndex } from '@/routes/treasury/tithes';
import type { Paginated } from '@/types';

type TithesIndexProps = {
    tithes: Paginated<TitheRow>;
    filters: TitheFilters;
    totals: OfferingTotals;
    /** Months that have tithes, newest first (YYYY-MM). */
    months: string[];
    today: string;
};

const count = new Intl.NumberFormat('es-VE');

export default function TithesIndex({
    tithes,
    filters,
    totals,
    months,
    today,
}: TithesIndexProps) {
    const { can } = usePermissions();
    const [isRecording, setIsRecording] = useState(false);

    return (
        <>
            <Head title="Diezmos" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Solo el pastor"
                    title="Diezmos"
                    description="El libro de diezmos, cada moneda por separado. No es ingreso de la iglesia: no entra en la contabilidad ni en los cierres."
                    meta={`${count.format(totals.count)} ${totals.count === 1 ? 'diezmo' : 'diezmos'}`}
                    actions={
                        can('tithes.manage') && (
                            <Button
                                className="shrink-0"
                                onClick={() => setIsRecording(true)}
                            >
                                <Plus aria-hidden />
                                Registrar diezmo
                            </Button>
                        )
                    }
                />

                <dl
                    aria-label="Totales de lo filtrado"
                    className="grid grid-cols-1 gap-3 sm:grid-cols-3"
                >
                    {(
                        [
                            ['Bolívares', totals.ves],
                            ['Dólares', totals.usd],
                            ['Pesos', totals.cop],
                        ] as const
                    ).map(([label, value]) => (
                        <div
                            key={label}
                            className="flex flex-col gap-1 rounded-3xl border-2 border-border bg-card px-5 py-4"
                        >
                            <dt className="text-sm text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="text-2xl font-black tracking-tight tabular-nums">
                                {formatAmount(value)}
                            </dd>
                        </div>
                    ))}
                </dl>

                <ServerDataTable
                    url={tithesIndex().url}
                    prop="tithes"
                    reload={['totals']}
                    data={tithes}
                    filters={filters}
                    columns={titheColumns}
                    rowKey={(tithe) => tithe.id}
                    rowActions={(tithe) => (
                        <TitheRowActions tithe={tithe} today={today} />
                    )}
                    searchLabel="Buscar por miembro o notas"
                    statusLabel="Registro"
                    defaultStatus="active"
                    inactiveLabels={{
                        option: 'En la papelera',
                        all: 'Vigentes y papelera',
                    }}
                    facets={[
                        {
                            key: 'month',
                            label: 'Mes',
                            allLabel: 'Todos los meses',
                            options: months.map((month) => ({
                                value: month,
                                label: formatMonth(month),
                            })),
                        },
                    ]}
                    empty={{
                        title: 'No hay diezmos con esos filtros',
                        description:
                            'Los diezmos que registre aparecerán aquí.',
                    }}
                />
            </div>
            {can('tithes.manage') && (
                <TitheFormDialog
                    today={today}
                    open={isRecording}
                    onOpenChange={setIsRecording}
                />
            )}
        </>
    );
}

TithesIndex.layout = {
    breadcrumbs: [{ title: 'Diezmos', href: tithesIndex() }],
};
