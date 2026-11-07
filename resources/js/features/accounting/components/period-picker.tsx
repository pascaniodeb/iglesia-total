import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Granularity, PeriodOptions } from '@/features/accounting/types';

const GRANULARITIES: { value: Granularity; label: string }[] = [
    { value: 'month', label: 'Mes' },
    { value: 'quarter', label: 'Trimestre' },
    { value: 'semester', label: 'Semestre' },
    { value: 'year', label: 'Año' },
    { value: 'range', label: 'Rango de meses' },
];

/** A range opens on the last three months there is something to report on. */
const RANGE_MONTHS = 3;

type Props = {
    granularity: Granularity;
    period: string;
    options: PeriodOptions;
    onChange: (granularity: Granularity, period: string) => void;
};

/**
 * Chooses the span of a report: a month, quarter, semester or year from the ones there is
 * something to report on, or a range between two months. Switching the kind of period opens its
 * most recent one.
 */
export function PeriodPicker({
    granularity,
    period,
    options,
    onChange,
}: Props) {
    const months = options.month;
    const [rangeFrom, rangeTo] = period.split('..');
    const from = (granularity === 'range' ? rangeFrom : undefined) ?? period;
    const to = (granularity === 'range' ? rangeTo : undefined) ?? period;

    const changeGranularity = (value: string) => {
        const next = value as Granularity;

        if (next === 'range') {
            const last = months[0]?.key ?? period;
            const first =
                months[Math.min(RANGE_MONTHS, months.length) - 1]?.key ?? last;
            onChange('range', `${first}..${last}`);

            return;
        }

        const latest = options[next][0];

        if (latest) {
            onChange(next, latest.key);
        }
    };

    const changeRange = (edge: 'from' | 'to', value: string) => {
        const start = edge === 'from' ? value : from;
        const end = edge === 'to' ? value : to;
        onChange(
            'range',
            start <= end ? `${start}..${end}` : `${end}..${start}`,
        );
    };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap">
            <Select value={granularity} onValueChange={changeGranularity}>
                <SelectTrigger
                    className="w-full sm:w-40"
                    aria-label="Tipo de período"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {GRANULARITIES.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {granularity === 'range' ? (
                <>
                    <Select
                        value={from}
                        onValueChange={(value) => changeRange('from', value)}
                    >
                        <SelectTrigger
                            className="w-full sm:w-44"
                            aria-label="Desde"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {months.map((option) => (
                                <SelectItem key={option.key} value={option.key}>
                                    Desde {option.label.toLowerCase()}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={to}
                        onValueChange={(value) => changeRange('to', value)}
                    >
                        <SelectTrigger
                            className="w-full sm:w-44"
                            aria-label="Hasta"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {months.map((option) => (
                                <SelectItem key={option.key} value={option.key}>
                                    Hasta {option.label.toLowerCase()}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </>
            ) : (
                <Select
                    value={period}
                    onValueChange={(value) => onChange(granularity, value)}
                >
                    <SelectTrigger
                        className="w-full sm:w-72"
                        aria-label="Período"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options[granularity].map((option) => (
                            <SelectItem key={option.key} value={option.key}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}
