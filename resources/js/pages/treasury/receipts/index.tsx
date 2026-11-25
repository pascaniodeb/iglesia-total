import { Head } from '@inertiajs/react';
import { Hash, Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { receiptColumns } from '@/features/treasury/components/receipt-columns';
import { ReceiptFormDialog } from '@/features/treasury/components/receipt-form-dialog';
import { ReceiptRowActions } from '@/features/treasury/components/receipt-row-actions';
import { ReceiptSettingsDialog } from '@/features/treasury/components/receipt-settings-dialog';
import type {
    ReceiptFilters,
    ReceiptFormData,
    ReceiptRow,
} from '@/features/treasury/types';
import { formatMonth } from '@/lib/month';
import { index as receiptsIndex } from '@/routes/treasury/receipts';
import type { Paginated } from '@/types';

type ReceiptsIndexProps = {
    receipts: Paginated<ReceiptRow>;
    filters: ReceiptFilters;
    /** Months that have receipts, newest first (YYYY-MM). */
    months: string[];
    /** The number the next receipt will most likely get: a preview. */
    next_number: string;
    start_number: number;
    can_issue: boolean;
    can_administer: boolean;
    /** What the issue form needs; null for who cannot issue. */
    form: ReceiptFormData | null;
};

const count = new Intl.NumberFormat('es-VE');

export default function ReceiptsIndex({
    receipts,
    filters,
    months,
    next_number: nextNumber,
    start_number: startNumber,
    can_issue: canIssue,
    can_administer: canAdminister,
    form,
}: ReceiptsIndexProps) {
    const [isIssuing, setIsIssuing] = useState(false);
    const [isConfiguring, setIsConfiguring] = useState(false);

    return (
        <>
            <Head title="Comprobantes" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería"
                    title="Comprobantes"
                    description={`El talonario: una sola serie para ingresos y egresos, en orden y sin saltos. El próximo será el ${nextNumber}.`}
                    meta={`${count.format(receipts.total)} ${receipts.total === 1 ? 'comprobante' : 'comprobantes'}`}
                    actions={
                        <>
                            {canAdminister && (
                                <Button
                                    variant="outline"
                                    className="shrink-0"
                                    onClick={() => setIsConfiguring(true)}
                                >
                                    <Hash aria-hidden />
                                    Número inicial
                                </Button>
                            )}
                            {canIssue && form && (
                                <Button
                                    className="shrink-0"
                                    onClick={() => setIsIssuing(true)}
                                >
                                    <Plus aria-hidden />
                                    Emitir comprobante
                                </Button>
                            )}
                        </>
                    }
                />

                <ServerDataTable
                    url={receiptsIndex().url}
                    prop="receipts"
                    data={receipts}
                    filters={filters}
                    columns={receiptColumns}
                    rowKey={(receipt) => String(receipt.number)}
                    rowActions={(receipt) => (
                        <ReceiptRowActions
                            receipt={receipt}
                            canAdminister={canAdminister}
                        />
                    )}
                    searchLabel="Buscar por número, titular o concepto"
                    withStatus={false}
                    facets={[
                        {
                            key: 'type',
                            label: 'Tipo',
                            allLabel: 'Ingresos y egresos',
                            options: [
                                { value: 'income', label: 'Ingresos' },
                                { value: 'expense', label: 'Egresos' },
                            ],
                        },
                        {
                            key: 'state',
                            label: 'Estado',
                            allLabel: 'Emitidos y anulados',
                            options: [
                                { value: 'issued', label: 'Emitidos' },
                                { value: 'void', label: 'Anulados' },
                            ],
                        },
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
                        title: 'No hay comprobantes con esos filtros',
                        description:
                            'Los comprobantes que emita aparecerán aquí.',
                    }}
                />
            </div>
            {canIssue && form && (
                <ReceiptFormDialog
                    form={form}
                    nextNumber={nextNumber}
                    open={isIssuing}
                    onOpenChange={setIsIssuing}
                />
            )}
            {canAdminister && (
                <ReceiptSettingsDialog
                    startNumber={startNumber}
                    open={isConfiguring}
                    onOpenChange={setIsConfiguring}
                />
            )}
        </>
    );
}

ReceiptsIndex.layout = {
    breadcrumbs: [{ title: 'Comprobantes', href: receiptsIndex() }],
};
