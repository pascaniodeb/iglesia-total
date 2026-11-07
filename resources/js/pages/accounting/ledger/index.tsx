import { Head, router } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { DraftNotice } from '@/features/accounting/components/draft-notice';
import { PeriodPicker } from '@/features/accounting/components/period-picker';
import { ORIGIN_LABELS } from '@/features/accounting/origins';
import type {
    AccountType,
    CurrencyCode,
    GeneralLedger,
    Granularity,
    LedgerEntry,
    PeriodOptions,
    ReportMonth,
} from '@/features/accounting/types';
import { formatShortDate } from '@/features/treasury/rates';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as ledgerIndex } from '@/routes/accounting/ledger';

type Props = {
    ledger: GeneralLedger;
    period: {
        granularity: Granularity;
        period: string;
        label: string;
        months: ReportMonth[];
        is_draft: boolean;
    };
    accounts: { code: string; name: string; type: AccountType }[];
    options: PeriodOptions;
};

const CURRENCIES: CurrencyCode[] = ['VES', 'USD', 'COP'];
const CURRENCY_LABELS: Record<CurrencyCode, string> = {
    VES: 'Bs',
    USD: 'USD',
    COP: 'COP',
};

/** The currencies the account moved or held in the period; all three when it has none. */
function activeCurrencies(ledger: GeneralLedger): CurrencyCode[] {
    const used = CURRENCIES.filter(
        (code) =>
            !isZeroAmount(ledger.opening[code]) ||
            !isZeroAmount(ledger.closing[code]) ||
            ledger.entries.some((entry) => !isZeroAmount(entry.amounts[code])),
    );

    return used.length > 0 ? used : CURRENCIES;
}

function Amount({ value, muted }: { value: string; muted?: boolean }) {
    return (
        <span
            className={cn(
                'whitespace-nowrap tabular-nums',
                value.startsWith('-') && 'text-destructive-foreground',
                muted && 'text-muted-foreground',
            )}
        >
            {formatAmount(value)}
        </span>
    );
}

function EntryConcept({ entry }: { entry: LedgerEntry }) {
    return (
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
                {entry.is_reversal
                    ? 'Contraasiento'
                    : (ORIGIN_LABELS[entry.origin] ?? entry.origin)}
                {entry.is_reversed && ' · anulado'}
            </span>
        </span>
    );
}

export default function LedgerIndex({
    ledger,
    period,
    accounts,
    options,
}: Props) {
    const currencies = activeCurrencies(ledger);
    const visit = (query: {
        account?: string;
        granularity?: Granularity;
        period?: string;
    }) =>
        router.get(
            ledgerIndex({
                query: {
                    account: ledger.account.code,
                    granularity: period.granularity,
                    period: period.period,
                    ...query,
                },
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`Libro Mayor · ${ledger.account.code}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow={`Contabilidad · Libro Mayor · ${ledger.account.code}`}
                    title={ledger.account.name}
                    description={`${period.label}. Cada asiento que movió la cuenta, con el saldo después de cada uno. Cada moneda por separado.`}
                    meta={period.is_draft ? 'Borrador' : 'Meses cerrados'}
                    actions={
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap">
                            <Select
                                value={ledger.account.code}
                                onValueChange={(account) => visit({ account })}
                            >
                                <SelectTrigger
                                    className="w-full sm:w-64"
                                    aria-label="Cuenta"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {accounts.map((account) => (
                                        <SelectItem
                                            key={account.code}
                                            value={account.code}
                                        >
                                            {account.code} · {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <PeriodPicker
                                granularity={period.granularity}
                                period={period.period}
                                options={options}
                                onChange={(granularity, key) =>
                                    visit({ granularity, period: key })
                                }
                            />
                        </div>
                    }
                />

                <DraftNotice months={period.months} />

                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {(
                        [
                            ['opening', 'Saldo anterior'],
                            ['movement', 'Movimiento del período'],
                            ['closing', 'Saldo final'],
                        ] as const
                    ).map(([key, label]) => (
                        <div
                            key={key}
                            className={cn(
                                'flex flex-col gap-2 rounded-[1.5rem] border-2 border-border bg-card px-5 py-4',
                                key === 'closing' && 'border-primary/40',
                            )}
                        >
                            <dt className="text-xs font-bold tracking-[0.15em] text-muted-foreground uppercase">
                                {label}
                            </dt>
                            {currencies.map((code) => (
                                <dd
                                    key={code}
                                    className="flex items-baseline justify-between gap-3"
                                >
                                    <span className="text-sm text-muted-foreground">
                                        {CURRENCY_LABELS[code]}
                                    </span>
                                    <span className="text-lg font-black">
                                        <Amount value={ledger[key][code]} />
                                    </span>
                                </dd>
                            ))}
                        </div>
                    ))}
                </dl>

                {ledger.entries.length === 0 ? (
                    <p className="rounded-[2rem] border-2 border-dashed border-border px-6 py-10 text-center text-muted-foreground">
                        La cuenta no tuvo movimientos en el período.
                    </p>
                ) : (
                    <>
                        <ol className="flex flex-col gap-3 md:hidden">
                            {ledger.entries.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex flex-col gap-2 rounded-2xl border-2 border-border bg-card p-4"
                                >
                                    <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                                        {formatShortDate(entry.entry_date)}
                                    </span>
                                    <EntryConcept entry={entry} />
                                    {currencies
                                        .filter(
                                            (code) =>
                                                !isZeroAmount(
                                                    entry.amounts[code],
                                                ),
                                        )
                                        .map((code) => (
                                            <span
                                                key={code}
                                                className="flex items-baseline justify-between gap-3 text-sm"
                                            >
                                                <span className="font-semibold">
                                                    <Amount
                                                        value={
                                                            entry.amounts[code]
                                                        }
                                                    />{' '}
                                                    {CURRENCY_LABELS[code]}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Saldo{' '}
                                                    <Amount
                                                        value={
                                                            entry.balances[code]
                                                        }
                                                    />
                                                </span>
                                            </span>
                                        ))}
                                </li>
                            ))}
                        </ol>

                        <div className="hidden overflow-x-auto rounded-[2rem] border-2 border-border bg-card md:block">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Movimientos de {ledger.account.code} en{' '}
                                    {period.label}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                                        <th
                                            scope="col"
                                            className="px-5 py-3 font-medium"
                                        >
                                            Fecha
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-3 font-medium"
                                        >
                                            Concepto
                                        </th>
                                        {currencies.map((code) => (
                                            <th
                                                key={code}
                                                scope="col"
                                                colSpan={2}
                                                className="px-3 py-3 text-right font-medium last:pr-5"
                                            >
                                                {CURRENCY_LABELS[code]}{' '}
                                                <span className="font-normal normal-case">
                                                    · movimiento / saldo
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-b bg-muted/40 font-semibold">
                                        <th
                                            scope="row"
                                            colSpan={2}
                                            className="px-5 py-2 text-left"
                                        >
                                            Saldo anterior
                                        </th>
                                        {currencies.map((code) => (
                                            <td
                                                key={code}
                                                colSpan={2}
                                                className="px-3 py-2 text-right last:pr-5"
                                            >
                                                <Amount
                                                    value={ledger.opening[code]}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                    {ledger.entries.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className="border-b align-top last:border-0"
                                        >
                                            <td className="px-5 py-3 font-semibold whitespace-nowrap tabular-nums">
                                                {formatShortDate(
                                                    entry.entry_date,
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                <EntryConcept entry={entry} />
                                            </td>
                                            {currencies.map((code) => (
                                                <Cells
                                                    key={code}
                                                    amount={entry.amounts[code]}
                                                    balance={
                                                        entry.balances[code]
                                                    }
                                                />
                                            ))}
                                        </tr>
                                    ))}
                                    <tr className="bg-muted/40 font-black">
                                        <th
                                            scope="row"
                                            colSpan={2}
                                            className="px-5 py-3 text-left"
                                        >
                                            Saldo final
                                        </th>
                                        {currencies.map((code) => (
                                            <td
                                                key={code}
                                                colSpan={2}
                                                className="px-3 py-3 text-right last:pr-5"
                                            >
                                                <Amount
                                                    value={ledger.closing[code]}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function Cells({ amount, balance }: { amount: string; balance: string }) {
    const isZero = isZeroAmount(amount);

    return (
        <>
            <td className="px-3 py-3 text-right font-semibold">
                {isZero ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <Amount value={amount} />
                )}
            </td>
            <td className="px-3 py-3 text-right last:pr-5">
                <Amount value={balance} muted={isZero} />
            </td>
        </>
    );
}

LedgerIndex.layout = {
    breadcrumbs: [{ title: 'Libro Mayor', href: ledgerIndex() }],
};
