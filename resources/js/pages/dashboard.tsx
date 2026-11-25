import { Head, usePage } from '@inertiajs/react';
import {
    Banknote,
    CalendarClock,
    HandCoins,
    HeartHandshake,
} from 'lucide-react';
import { useState } from 'react';
import { CashCard } from '@/features/dashboard/components/cash-card';
import { CategoryBreakdown } from '@/features/dashboard/components/category-breakdown';
import { CurrencySwitch } from '@/features/dashboard/components/currency-switch';
import { KpiCard } from '@/features/dashboard/components/kpi-card';
import { PendingClosures } from '@/features/dashboard/components/pending-closures';
import { RatesCard } from '@/features/dashboard/components/rates-card';
import { WeeklyTrend } from '@/features/dashboard/components/weekly-trend';
import { currencyLabel } from '@/features/dashboard/currencies';
import type { CurrencyKey, DashboardProps } from '@/features/dashboard/types';
import { formatLongDate } from '@/features/treasury/rates';
import { formatAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { dashboard } from '@/routes';

const CURRENCY_CODE = { ves: 'VES', usd: 'USD', cop: 'COP' } as const;

/** "2026-10" → "octubre". */
function previousMonthName(month: string): string {
    return formatMonth(month).split(' ')[0]?.toLowerCase() ?? month;
}

function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] ?? name;
}

export default function Dashboard({
    today,
    month,
    offerings,
    tithes,
    pending,
    cash,
    rates,
}: DashboardProps) {
    const { auth } = usePage().props;
    const [currency, setCurrency] = useState<CurrencyKey>('cop');
    const label = currencyLabel(currency);
    const tasks = pending
        ? pending.weeks_pending +
          (pending.month ? 1 : 0) +
          (pending.accounting ? 1 : 0)
        : 0;
    const hasAnything = offerings || tithes || pending || cash || rates;

    return (
        <>
            <Head title="Panel" />
            <div
                data-fit-viewport
                className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8"
            >
                <header className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <p className="eyebrow">{formatLongDate(today)}</p>
                        <h1 className="text-3xl font-bold tracking-tight text-balance md:text-4xl">
                            Hola, {firstName(auth.user.name)}
                        </h1>
                        <p className="text-muted-foreground">
                            Así van las finanzas de la iglesia en{' '}
                            {formatMonth(month).toLowerCase()}.
                        </p>
                    </div>
                    {(offerings || cash || tithes) && (
                        <CurrencySwitch
                            value={currency}
                            onChange={setCurrency}
                        />
                    )}
                </header>

                <div data-fit-body className="min-w-0 pb-1">
                    {!hasAnything ? (
                        <p className="rounded-2xl border bg-card p-8 text-center text-muted-foreground">
                            Su cuenta aún no tiene secciones para mostrar en el
                            panel.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-4 md:gap-5">
                            <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                                {offerings && (
                                    <KpiCard
                                        label="Ofrendas del mes"
                                        icon={HandCoins}
                                        value={formatAmount(
                                            offerings.current[currency],
                                        )}
                                        unit={label}
                                        hint={`${offerings.current.count} ${offerings.current.count === 1 ? 'ofrenda' : 'ofrendas'} · ${previousMonthName(offerings.previous_month)}: ${formatAmount(offerings.previous[currency])}`}
                                    />
                                )}
                                {tithes && (
                                    <KpiCard
                                        label="Diezmos del mes"
                                        icon={HeartHandshake}
                                        tone="positive"
                                        value={formatAmount(tithes[currency])}
                                        unit={label}
                                        hint={
                                            tithes.members === 0
                                                ? 'Nadie ha diezmado aún'
                                                : `${tithes.members} ${tithes.members === 1 ? 'hermano diezmó' : 'hermanos diezmaron'}`
                                        }
                                    />
                                )}
                                {cash && (
                                    <KpiCard
                                        label="Dinero en caja"
                                        icon={Banknote}
                                        value={formatAmount(
                                            cash[CURRENCY_CODE[currency]]
                                                .closing,
                                        )}
                                        unit={label}
                                        hint="Saldo de hoy"
                                    />
                                )}
                                {pending && (
                                    <KpiCard
                                        label="Cierres pendientes"
                                        icon={CalendarClock}
                                        tone={
                                            tasks === 0 ? 'positive' : 'warning'
                                        }
                                        value={String(tasks)}
                                        unit={
                                            tasks === 1
                                                ? 'pendiente'
                                                : 'pendientes'
                                        }
                                        hint={
                                            tasks === 0
                                                ? 'Todo al día'
                                                : 'Ver detalle abajo'
                                        }
                                    />
                                )}
                            </div>

                            <div className="grid gap-4 md:gap-5 xl:grid-cols-3">
                                {offerings && (
                                    <div className="min-w-0 xl:col-span-2">
                                        <WeeklyTrend
                                            weeks={offerings.weeks}
                                            currency={currency}
                                        />
                                    </div>
                                )}
                                {pending && (
                                    <PendingClosures pending={pending} />
                                )}
                                {offerings && (
                                    <CategoryBreakdown
                                        month={offerings.month}
                                        categories={offerings.categories}
                                        currency={currency}
                                    />
                                )}
                                {cash && <CashCard cash={cash} month={month} />}
                                {rates && <RatesCard rates={rates} />}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Panel',
            href: dashboard(),
        },
    ],
};
