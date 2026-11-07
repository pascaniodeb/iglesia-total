import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, Minus, Paperclip, Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { DataTableColumn } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CashStatementTable } from '@/features/accounting/components/cash-statement-table';
import { CodeTable } from '@/features/accounting/components/code-table';
import { ExchangeDialog } from '@/features/accounting/components/exchange-dialog';
import { JournalEntryRowActions } from '@/features/accounting/components/journal-entry-row-actions';
import { MovementDialog } from '@/features/accounting/components/movement-dialog';
import { ORIGIN_LABELS } from '@/features/accounting/origins';
import type {
    CurrencyCode,
    JournalEntryRow,
    JournalPageProps,
} from '@/features/accounting/types';
import { formatShortDate } from '@/features/treasury/rates';
import { cn } from '@/lib/utils';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { index as journalIndex, support } from '@/routes/accounting/journal';
import type { Paginated, TableFilters } from '@/types';

type JournalIndexProps = JournalPageProps & {
    entries: Paginated<JournalEntryRow>;
    filters: TableFilters<'date'> & { month: string };
};

const CURRENCIES: CurrencyCode[] = ['VES', 'USD', 'COP'];

function cashCell(entry: JournalEntryRow) {
    const moved = CURRENCIES.filter((code) => !isZeroAmount(entry.cash[code]));

    if (moved.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="flex flex-col items-end tabular-nums">
            {moved.map((code) => (
                <span
                    key={code}
                    className={cn(
                        'font-semibold whitespace-nowrap',
                        entry.cash[code].startsWith('-') &&
                            'text-destructive-foreground',
                    )}
                >
                    {formatAmount(entry.cash[code])} {code}
                </span>
            ))}
        </span>
    );
}

export default function JournalIndex(props: JournalIndexProps) {
    const {
        month,
        status,
        is_opening_provisional: isProvisional,
        summary,
        available,
        entries,
        filters,
        months,
        window,
        accounts,
        can_manage: canManage,
    } = props;
    const [dialog, setDialog] = useState<
        'income' | 'expense' | 'exchange' | null
    >(null);
    const isOpen = status === 'open';

    const columns: DataTableColumn<JournalEntryRow, 'date'>[] = [
        {
            key: 'date',
            header: 'Fecha',
            sortKey: 'date',
            cell: (entry) => (
                <span className="font-semibold whitespace-nowrap tabular-nums">
                    {formatShortDate(entry.entry_date)}
                </span>
            ),
        },
        {
            key: 'description',
            header: 'Concepto',
            mobile: 'primary',
            cell: (entry) => (
                <span className="flex flex-col">
                    <span
                        className={cn(
                            'font-semibold break-words',
                            entry.is_reversed && 'line-through decoration-2',
                        )}
                    >
                        {entry.description}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {ORIGIN_LABELS[entry.origin] ?? entry.origin}
                        {entry.codes.length > 0 &&
                            ` · ${entry.codes.map((code) => code.code).join(', ')}`}
                        {entry.reversal_reason &&
                            ` · Motivo: ${entry.reversal_reason}`}
                        {entry.is_reversed && ' · anulado'}
                        {entry.receipt_number &&
                            ` · Recibo ${entry.receipt_number}`}
                    </span>
                    {entry.has_file && (
                        <a
                            href={support(entry.id).url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex w-fit items-center gap-1 text-xs font-semibold text-primary underline-offset-4 hover:underline"
                        >
                            <Paperclip aria-hidden className="size-3.5" />
                            Ver soporte
                        </a>
                    )}
                </span>
            ),
        },
        {
            key: 'cash',
            header: 'Caja',
            align: 'end',
            mobile: 'badge',
            cell: cashCell,
        },
    ];

    return (
        <>
            <Head title="Libro Diario" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Contabilidad · Paso 3 del mes"
                    title={`Libro Diario · ${formatMonth(month)}`}
                    description={
                        status === 'closed'
                            ? 'Mes cerrado: solo consulta. Un error se corrige en un mes abierto.'
                            : 'Ingresos y egresos del mes, cada moneda por separado. Nada se edita: se corrige o se anula con su motivo.'
                    }
                    meta={
                        status === 'closed'
                            ? 'Cerrado'
                            : isOpen
                              ? 'Abierto'
                              : 'Fuera de los meses abiertos'
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Select
                                value={month}
                                onValueChange={(value) =>
                                    router.get(
                                        journalIndex({
                                            query: { month: value },
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    className="w-48"
                                    aria-label="Mes"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {months.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {formatMonth(option)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {canManage && isOpen && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('income')}
                                    >
                                        <Plus aria-hidden />
                                        Ingreso
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('expense')}
                                    >
                                        <Minus aria-hidden />
                                        Egreso
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('exchange')}
                                    >
                                        <ArrowLeftRight aria-hidden />
                                        Cambio
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-[3fr_2fr]">
                    <section className="flex flex-col gap-3 rounded-[2rem] border-2 border-border bg-card p-6">
                        <h2 className="text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                            Estado de caja
                        </h2>
                        {isProvisional && (
                            <p className="text-sm font-semibold text-warning">
                                El saldo anterior es provisional: el mes
                                anterior todavía no está cerrado.
                            </p>
                        )}
                        <CashStatementTable currencies={summary.currencies} />
                    </section>
                    {available && (
                        <section className="flex flex-col gap-3 rounded-[2rem] border-2 border-border bg-card p-6">
                            <h2 className="text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                                Disponible para gastar hoy
                            </h2>
                            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                {CURRENCIES.map((code) => (
                                    <div
                                        key={code}
                                        className="flex items-baseline justify-between gap-3 rounded-2xl bg-muted/60 px-4 py-3"
                                    >
                                        <dt className="text-sm text-muted-foreground">
                                            {code}
                                        </dt>
                                        <dd className="text-xl font-black tabular-nums">
                                            {formatAmount(available[code])}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                Incluye las ofrendas de semanas ya cerradas.
                            </p>
                        </section>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <CodeTable
                        title="Ingresos"
                        rows={summary.by_code.filter(
                            (row) => row.type === 'income',
                        )}
                    />
                    <CodeTable
                        title="Egresos"
                        rows={summary.by_code.filter(
                            (row) => row.type === 'expense',
                        )}
                    />
                </div>

                <ServerDataTable
                    url={journalIndex().url}
                    prop="entries"
                    data={entries}
                    filters={filters}
                    columns={columns}
                    rowKey={(entry) => entry.id}
                    rowActions={
                        canManage
                            ? (entry) => (
                                  <JournalEntryRowActions
                                      entry={entry}
                                      accounts={accounts}
                                      window={window}
                                      available={available}
                                  />
                              )
                            : undefined
                    }
                    searchLabel="Buscar por concepto"
                    withStatus={false}
                    fitViewport={false}
                    empty={{
                        title: 'Sin asientos en el mes',
                        description: 'Los movimientos del mes aparecerán aquí.',
                    }}
                />
            </div>

            {canManage && isOpen && (
                <>
                    <MovementDialog
                        kind="income"
                        accounts={accounts.income}
                        window={window}
                        available={available}
                        open={dialog === 'income'}
                        onOpenChange={(open) =>
                            setDialog(open ? 'income' : null)
                        }
                    />
                    <MovementDialog
                        kind="expense"
                        accounts={accounts.expense}
                        window={window}
                        available={available}
                        open={dialog === 'expense'}
                        onOpenChange={(open) =>
                            setDialog(open ? 'expense' : null)
                        }
                    />
                    <ExchangeDialog
                        window={window}
                        available={available}
                        open={dialog === 'exchange'}
                        onOpenChange={(open) =>
                            setDialog(open ? 'exchange' : null)
                        }
                    />
                </>
            )}
        </>
    );
}

JournalIndex.layout = {
    breadcrumbs: [{ title: 'Libro Diario', href: journalIndex() }],
};
