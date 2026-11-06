import { Form, Head } from '@inertiajs/react';
import { Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { CurrentRates } from '@/features/treasury/components/current-rates';
import { exchangeRateColumns } from '@/features/treasury/components/exchange-rate-columns';
import { ExchangeRateRowActions } from '@/features/treasury/components/exchange-rate-row-actions';
import { ManualRateDialog } from '@/features/treasury/components/manual-rate-dialog';
import type {
    ExchangeRateFilters,
    ExchangeRateRow,
} from '@/features/treasury/types';
import { cn } from '@/lib/utils';
import {
    index as exchangeRatesIndex,
    sync as exchangeRatesSync,
} from '@/routes/treasury/exchange-rates';
import type { Paginated } from '@/types';

type ExchangeRatesIndexProps = {
    rates: Paginated<ExchangeRateRow>;
    filters: ExchangeRateFilters;
    latest: ExchangeRateRow | null;
    /** Today in Venezuela (YYYY-MM-DD). */
    today: string;
    can_manage: boolean;
};

export default function ExchangeRatesIndex({
    rates,
    filters,
    latest,
    today,
    can_manage: canManage,
}: ExchangeRatesIndexProps) {
    const [isRecording, setIsRecording] = useState(false);

    return (
        <>
            <Head title="Tasas de cambio" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería"
                    title="Tasas de cambio"
                    description="La tasa oficial de cada día, actualizada sola desde el BCV y la TRM. Una tasa registrada a mano prevalece. Las tasas informan: los libros se llevan en cada moneda."
                    actions={
                        canManage && (
                            <>
                                <Form
                                    {...exchangeRatesSync.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="w-full sm:w-auto"
                                        >
                                            <RefreshCw
                                                aria-hidden
                                                className={cn(
                                                    processing &&
                                                        'animate-spin',
                                                )}
                                            />
                                            {processing
                                                ? 'Consultando…'
                                                : 'Actualizar desde el BCV'}
                                        </Button>
                                    )}
                                </Form>
                                <Button
                                    className="shrink-0"
                                    onClick={() => setIsRecording(true)}
                                >
                                    <Plus aria-hidden />
                                    Registrar tasa
                                </Button>
                            </>
                        )
                    }
                />

                <CurrentRates rate={latest} today={today} />

                <ServerDataTable
                    url={exchangeRatesIndex().url}
                    prop="rates"
                    data={rates}
                    filters={filters}
                    columns={exchangeRateColumns}
                    rowKey={(rate) => rate.id}
                    rowActions={
                        canManage
                            ? (rate) => (
                                  <ExchangeRateRowActions
                                      rate={rate}
                                      today={today}
                                  />
                              )
                            : undefined
                    }
                    searchLabel="Buscar por fecha (dd/mm/aaaa)"
                    withStatus={false}
                    fitViewport={false}
                    empty={{
                        title: 'Aún no hay tasas',
                        description:
                            'Las tasas sincronizadas o registradas a mano aparecerán aquí.',
                    }}
                />
            </div>
            {canManage && (
                <ManualRateDialog
                    key={today}
                    today={today}
                    open={isRecording}
                    onOpenChange={setIsRecording}
                />
            )}
        </>
    );
}

ExchangeRatesIndex.layout = {
    breadcrumbs: [{ title: 'Tasas de cambio', href: exchangeRatesIndex() }],
};
