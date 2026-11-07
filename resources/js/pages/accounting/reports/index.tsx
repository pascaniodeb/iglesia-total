import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { CashStatementTable } from '@/features/accounting/components/cash-statement-table';
import { CodeTable } from '@/features/accounting/components/code-table';
import { DraftNotice } from '@/features/accounting/components/draft-notice';
import { PeriodPicker } from '@/features/accounting/components/period-picker';
import type {
    Granularity,
    PeriodOptions,
    PeriodReport,
} from '@/features/accounting/types';
import { formatShortDate } from '@/features/treasury/rates';
import { index as reportsIndex } from '@/routes/accounting/reports';

type Props = {
    report: PeriodReport;
    options: PeriodOptions;
};

export default function ReportsIndex({ report, options }: Props) {
    const visit = (granularity: Granularity, period: string) =>
        router.get(
            reportsIndex({ query: { granularity, period } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Reporte contable" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Contabilidad · Reportes"
                    title={report.short_label}
                    description={`${report.label}, del ${formatShortDate(report.first_day)} al ${formatShortDate(report.last_day)}. Un solo saldo anterior y un solo saldo final para todo el período: los saldos de cada mes nunca se suman.`}
                    meta={report.is_draft ? 'Borrador' : 'Meses cerrados'}
                    actions={
                        <PeriodPicker
                            granularity={report.granularity}
                            period={report.period}
                            options={options}
                            onChange={visit}
                        />
                    }
                />

                <DraftNotice months={report.months} />

                <section className="flex flex-col gap-3 rounded-[2rem] border-2 border-border bg-card p-6">
                    <h2 className="text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                        Estado de caja del período
                    </h2>
                    <CashStatementTable
                        currencies={report.currencies}
                        caption="Estado de caja del período por moneda"
                    />
                </section>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <CodeTable
                        title="Ingresos"
                        rows={report.by_code.filter(
                            (row) => row.type === 'income',
                        )}
                    />
                    <CodeTable
                        title="Egresos"
                        rows={report.by_code.filter(
                            (row) => row.type === 'expense',
                        )}
                    />
                </div>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reporte contable', href: reportsIndex() }],
};
