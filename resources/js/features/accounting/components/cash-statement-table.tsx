import type { CashStatement, CurrencyCode } from '@/features/accounting/types';
import { formatAmount, isZeroAmount } from '@/lib/money';

const CURRENCIES: { code: CurrencyCode; label: string }[] = [
    { code: 'VES', label: 'Bs' },
    { code: 'USD', label: 'USD' },
    { code: 'COP', label: 'COP' },
];

/**
 * The month as the IPUV report reads it, per currency: opening + income − expense (± capital
 * and exchange when there are any) = closing. Each currency in its own column, never converted.
 */
export function CashStatementTable({
    currencies,
    caption = 'Estado de caja del mes por moneda',
}: {
    currencies: Record<CurrencyCode, CashStatement>;
    caption?: string;
}) {
    const has = (key: keyof CashStatement) =>
        CURRENCIES.some(({ code }) => !isZeroAmount(currencies[code][key]));
    const rows: {
        key: keyof CashStatement;
        label: string;
        strong?: boolean;
    }[] = [
        { key: 'opening', label: 'Saldo anterior' },
        { key: 'income', label: '+ Ingresos' },
        { key: 'expense', label: '− Egresos' },
        ...(has('capital')
            ? [
                  {
                      key: 'capital' as const,
                      label: '+ Saldo inicial del sistema anterior',
                  },
              ]
            : []),
        ...(has('exchange')
            ? [{ key: 'exchange' as const, label: '± Cambio de divisas' }]
            : []),
        { key: 'closing', label: '= Saldo final', strong: true },
    ];

    return (
        <table className="w-full table-fixed text-xs sm:text-sm">
            <caption className="sr-only">{caption}</caption>
            <thead>
                <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                    <th scope="col" className="w-2/5 py-2 pr-3 font-medium">
                        Concepto
                    </th>
                    {CURRENCIES.map(({ code, label }) => (
                        <th
                            key={code}
                            scope="col"
                            className="py-2 pr-3 text-right font-medium last:pr-0"
                        >
                            {label}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody className="tabular-nums">
                {rows.map((row) => (
                    <tr
                        key={row.key}
                        className={row.strong ? 'font-black' : 'border-b'}
                    >
                        <th
                            scope="row"
                            className="py-2 pr-3 text-left font-medium"
                        >
                            {row.label}
                        </th>
                        {CURRENCIES.map(({ code }) => (
                            <td
                                key={code}
                                className="py-2 pr-3 text-right break-words last:pr-0"
                            >
                                {formatAmount(currencies[code][row.key])}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
