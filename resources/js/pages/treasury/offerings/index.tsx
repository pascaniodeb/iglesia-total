import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { offeringColumns } from '@/features/treasury/components/offering-columns';
import { OfferingFormDialog } from '@/features/treasury/components/offering-form-dialog';
import { OfferingRowActions } from '@/features/treasury/components/offering-row-actions';
import type {
    CategoryOption,
    OfferingFilters,
    OfferingRow,
    OfferingTotals,
} from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { formatAmount } from '@/lib/money';
import { index as offeringsIndex } from '@/routes/treasury/offerings';
import type { Paginated } from '@/types';

type OfferingsIndexProps = {
    offerings: Paginated<OfferingRow>;
    filters: OfferingFilters;
    totals: OfferingTotals;
    categories: CategoryOption[];
    /** Months that have offerings, newest first (YYYY-MM). */
    months: string[];
    today: string;
};

const count = new Intl.NumberFormat('es-VE');
const monthName = new Intl.DateTimeFormat('es-VE', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function monthLabel(month: string): string {
    const label = monthName.format(new Date(`${month}-01T00:00:00Z`));

    return label.charAt(0).toUpperCase() + label.slice(1);
}

export default function OfferingsIndex({
    offerings,
    filters,
    totals,
    categories,
    months,
    today,
}: OfferingsIndexProps) {
    const { can } = usePermissions();
    const [isRecording, setIsRecording] = useState(false);

    return (
        <>
            <Head title="Ofrendas" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería"
                    title="Ofrendas"
                    description="El registro de ofrendas, cada moneda por separado. Los totales siguen a los filtros."
                    meta={`${count.format(totals.count)} ${totals.count === 1 ? 'ofrenda' : 'ofrendas'}`}
                    actions={
                        can('offerings.manage') && (
                            <Button
                                className="shrink-0"
                                onClick={() => setIsRecording(true)}
                            >
                                <Plus aria-hidden />
                                Registrar ofrenda
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
                    url={offeringsIndex().url}
                    prop="offerings"
                    reload={['totals']}
                    data={offerings}
                    filters={filters}
                    columns={offeringColumns}
                    rowKey={(offering) => offering.id}
                    rowActions={(offering) => (
                        <OfferingRowActions
                            offering={offering}
                            categories={categories}
                            today={today}
                        />
                    )}
                    searchLabel="Buscar por miembro o concepto"
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
                                label: monthLabel(month),
                            })),
                        },
                        {
                            key: 'category',
                            label: 'Categoría',
                            allLabel: 'Todas las categorías',
                            options: categories.map(({ value, label }) => ({
                                value,
                                label,
                            })),
                        },
                    ]}
                    empty={{
                        title: 'No hay ofrendas con esos filtros',
                        description:
                            'Las ofrendas que registre aparecerán aquí.',
                    }}
                />
            </div>
            {can('offerings.manage') && (
                <OfferingFormDialog
                    categories={categories}
                    today={today}
                    open={isRecording}
                    onOpenChange={setIsRecording}
                />
            )}
        </>
    );
}

OfferingsIndex.layout = {
    breadcrumbs: [{ title: 'Ofrendas', href: offeringsIndex() }],
};
