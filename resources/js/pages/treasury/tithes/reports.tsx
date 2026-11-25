import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { PeriodPicker } from '@/features/accounting/components/period-picker';
import type { Granularity, PeriodOptions } from '@/features/accounting/types';
import { TitheNav } from '@/features/treasury/components/tithe-nav';
import type { TitheReport } from '@/features/treasury/types';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { reports } from '@/routes/treasury/tithes';

type TitheReportsProps = {
    report: TitheReport;
    options: PeriodOptions;
};

const count = new Intl.NumberFormat('es-VE');

const amount = (value: string) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        formatAmount(value)
    );

export default function TitheReports({ report, options }: TitheReportsProps) {
    const visit = (granularity: Granularity, period: string) =>
        router.get(
            reports({ query: { granularity, period } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Reporte de diezmos" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Diezmos · Reporte"
                    title={report.short_label}
                    description={`${report.label}. Lo que se diezmó en el período, cada moneda por separado. Los miembros van por orden alfabético: no es un ranking.`}
                    meta={`${count.format(report.members_who_tithed)} ${report.members_who_tithed === 1 ? 'miembro diezmó' : 'miembros diezmaron'}`}
                    actions={
                        <PeriodPicker
                            granularity={report.granularity}
                            period={report.period}
                            options={options}
                            onChange={visit}
                        />
                    }
                />

                <TitheNav current="reports" />

                <dl
                    aria-label="Totales del período"
                    className="grid grid-cols-1 gap-3 sm:grid-cols-3"
                >
                    {(
                        [
                            ['Bolívares', report.totals.ves],
                            ['Dólares', report.totals.usd],
                            ['Pesos', report.totals.cop],
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

                {report.members.length === 0 ? (
                    <p className="rounded-[2rem] border-2 border-dashed border-border px-6 py-10 text-center text-muted-foreground">
                        Nadie diezmó en este período.
                    </p>
                ) : (
                    <>
                        <ul className="flex flex-col gap-3 md:hidden">
                            {report.members.map((member) => (
                                <li
                                    key={member.id}
                                    className="flex flex-col gap-2 rounded-2xl border-2 border-border bg-card p-4"
                                >
                                    <span className="font-semibold break-words">
                                        {member.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {member.weeks}{' '}
                                        {member.weeks === 1
                                            ? 'semana'
                                            : 'semanas'}{' '}
                                        con diezmo
                                    </span>
                                    <dl className="grid grid-cols-3 gap-2 text-sm tabular-nums">
                                        {(
                                            [
                                                ['Bs', member.ves],
                                                ['USD', member.usd],
                                                ['COP', member.cop],
                                            ] as const
                                        ).map(([label, value]) => (
                                            <div key={label}>
                                                <dt className="text-xs text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="font-semibold">
                                                    {amount(value)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </li>
                            ))}
                        </ul>

                        <div className="hidden overflow-x-auto rounded-[2rem] border-2 border-border bg-card md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Diezmos por miembro en {report.label}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                                        <th
                                            scope="col"
                                            className="px-5 py-3 font-medium"
                                        >
                                            Miembro
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium"
                                        >
                                            Semanas
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium"
                                        >
                                            Bs
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium"
                                        >
                                            USD
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-5 py-3 text-right font-medium"
                                        >
                                            COP
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="tabular-nums">
                                    {report.members.map((member) => (
                                        <tr
                                            key={member.id}
                                            className="border-b last:border-0"
                                        >
                                            <th
                                                scope="row"
                                                className="px-5 py-3 text-left font-semibold"
                                            >
                                                {member.name}
                                            </th>
                                            <td className="px-3 py-3 text-right">
                                                {member.weeks}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                {amount(member.ves)}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                {amount(member.usd)}
                                            </td>
                                            <td className="px-5 py-3 text-right">
                                                {amount(member.cop)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

TitheReports.layout = {
    breadcrumbs: [{ title: 'Reporte de diezmos', href: reports() }],
};
