import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { CURRENCIES } from '../currencies';
import type { CashWidget } from '../types';
import { Panel } from './panel';
import { formatMonth } from '@/lib/month';
import { formatAmount } from '@/lib/money';

/** The cash the church has, per currency, and what came in and went out this month. */
export function CashCard({ cash, month }: { cash: CashWidget; month: string }) {
    return (
        <Panel
            title="Caja"
            description={`Lo que hay hoy y los movimientos de ${formatMonth(month).toLowerCase()}`}
        >
            <ul className="flex flex-col divide-y">
                {CURRENCIES.map((currency) => {
                    const statement = cash[currency.code];

                    return (
                        <li
                            key={currency.key}
                            className="flex flex-col gap-1.5 py-3 first:pt-0 last:pb-0"
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="text-sm font-medium text-muted-foreground">
                                    {currency.name}
                                </span>
                                <span className="text-lg font-bold tabular-nums">
                                    {formatAmount(statement.closing)}{' '}
                                    <span className="text-xs font-semibold text-muted-foreground">
                                        {currency.label}
                                    </span>
                                </span>
                            </div>
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums">
                                <span className="inline-flex items-center gap-1 text-positive">
                                    <ArrowDownLeft
                                        aria-hidden
                                        className="size-3.5"
                                    />
                                    Entró {formatAmount(statement.income)}
                                </span>
                                <span className="inline-flex items-center gap-1 text-negative">
                                    <ArrowUpRight
                                        aria-hidden
                                        className="size-3.5"
                                    />
                                    Salió {formatAmount(statement.expense)}
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}
