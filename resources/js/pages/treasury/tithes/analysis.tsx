import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckboxField } from '@/components/form/checkbox-field';
import { PageHeader } from '@/components/page-header';
import { PeriodPicker } from '@/features/accounting/components/period-picker';
import type { Granularity, PeriodOptions } from '@/features/accounting/types';
import { TitheNav } from '@/features/treasury/components/tithe-nav';
import { formatShortDate } from '@/features/treasury/rates';
import type {
    TitheAnalysis as TitheAnalysisData,
    TitheAnalysisRow,
} from '@/features/treasury/types';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { analysis } from '@/routes/treasury/tithes';

type TitheAnalysisProps = {
    analysis: TitheAnalysisData;
    options: PeriodOptions;
};

const count = new Intl.NumberFormat('es-VE');
const CURRENCIES = [
    { key: 'ves', label: 'Bs' },
    { key: 'usd', label: 'USD' },
    { key: 'cop', label: 'COP' },
] as const;

/** The amounts a member has, one line per currency; "—" when there is none. */
function Stack({
    values,
}: {
    values: Record<'ves' | 'usd' | 'cop', string | null>;
}) {
    const lines = CURRENCIES.filter(
        ({ key }) => values[key] !== null && !isZeroAmount(values[key]),
    );

    if (lines.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="flex flex-col items-end tabular-nums">
            {lines.map(({ key, label }) => (
                <span key={key} className="font-semibold whitespace-nowrap">
                    {formatAmount(values[key] ?? '0')} {label}
                </span>
            ))}
        </span>
    );
}

function lastTithe(row: TitheAnalysisRow) {
    if (row.last_date === null) {
        return <span className="text-muted-foreground">Nunca ha diezmado</span>;
    }

    const ago =
        row.weeks_ago === null || row.weeks_ago === 0
            ? 'esta semana'
            : `hace ${row.weeks_ago} ${row.weeks_ago === 1 ? 'semana' : 'semanas'}`;

    return (
        <span className="flex flex-col">
            <span className="font-semibold tabular-nums">
                {formatShortDate(row.last_date)}
            </span>
            <span className="text-xs text-muted-foreground">{ago}</span>
        </span>
    );
}

export default function TitheAnalysis({
    analysis: data,
    options,
}: TitheAnalysisProps) {
    const [onlyMissing, setOnlyMissing] = useState(false);
    const rows = onlyMissing
        ? data.rows.filter((row) => row.weeks === 0)
        : data.rows;
    const missing = data.rows.filter((row) => row.weeks === 0).length;

    const visit = (granularity: Granularity, period: string) =>
        router.get(
            analysis({ query: { granularity, period } }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Análisis de diezmos" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería · Diezmos · Análisis"
                    title={data.short_label}
                    description={`${data.label}: ${data.sundays} domingos. Cuánto y con qué constancia diezmó cada miembro del padrón, también quienes no diezmaron.`}
                    meta={`${count.format(missing)} sin diezmo en el período`}
                    actions={
                        <PeriodPicker
                            granularity={data.granularity}
                            period={data.period}
                            options={options}
                            onChange={visit}
                        />
                    }
                />

                <TitheNav current="analysis" />

                <p className="rounded-2xl border-2 border-border bg-card px-5 py-4 text-sm text-muted-foreground">
                    <strong className="text-foreground">Dos promedios:</strong>{' '}
                    «por semana que diezmó» es el total entre las semanas en que
                    diezmó (lo que da cuando da); «sobre el período» es el total
                    entre los {data.sundays} domingos del período (lo que da en
                    general). No son lo mismo: léalos juntos antes de sacar una
                    conclusión sobre una persona.
                </p>

                <div className="max-w-sm">
                    <CheckboxField
                        name="only_missing"
                        label="Mostrar solo a quienes no diezmaron en el período"
                        onCheckedChange={setOnlyMissing}
                    />
                </div>

                {rows.length === 0 ? (
                    <p className="rounded-[2rem] border-2 border-dashed border-border px-6 py-10 text-center text-muted-foreground">
                        {data.rows.length === 0
                            ? 'No hay miembros en el padrón.'
                            : 'Todos los miembros diezmaron en este período.'}
                    </p>
                ) : (
                    <>
                        <ul className="flex flex-col gap-3 md:hidden">
                            {rows.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-col gap-3 rounded-2xl border-2 border-border bg-card p-4"
                                >
                                    <span className="font-semibold break-words">
                                        {row.name}
                                    </span>
                                    <span className="text-sm">
                                        {row.weeks} de {data.sundays} domingos
                                    </span>
                                    {lastTithe(row)}
                                    <dl className="grid grid-cols-1 gap-2 text-sm">
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-muted-foreground">
                                                Total
                                            </dt>
                                            <dd>
                                                <Stack values={row.totals} />
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-muted-foreground">
                                                Por semana que diezmó
                                            </dt>
                                            <dd>
                                                <Stack
                                                    values={row.average_given}
                                                />
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-muted-foreground">
                                                Sobre el período
                                            </dt>
                                            <dd>
                                                <Stack
                                                    values={row.average_period}
                                                />
                                            </dd>
                                        </div>
                                    </dl>
                                </li>
                            ))}
                        </ul>

                        <div className="hidden overflow-x-auto rounded-[2rem] border-2 border-border bg-card md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Constancia de cada miembro en {data.label}
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
                                            className="px-3 py-3 font-medium"
                                        >
                                            Semanas
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Último diezmo
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium"
                                        >
                                            Total
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 text-right font-medium"
                                        >
                                            Por semana que diezmó
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-5 py-3 text-right font-medium"
                                        >
                                            Sobre el período
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <th
                                                scope="row"
                                                className="px-5 py-3 text-left font-semibold"
                                            >
                                                {row.name}
                                            </th>
                                            <td className="px-3 py-3 whitespace-nowrap tabular-nums">
                                                {row.weeks} de {data.sundays}
                                            </td>
                                            <td className="px-3 py-3">
                                                {lastTithe(row)}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <Stack values={row.totals} />
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <Stack
                                                    values={row.average_given}
                                                />
                                            </td>
                                            <td className="px-5 py-3 text-right">
                                                <Stack
                                                    values={row.average_period}
                                                />
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

TitheAnalysis.layout = {
    breadcrumbs: [{ title: 'Análisis de diezmos', href: analysis() }],
};
