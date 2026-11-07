import type { MonthSummary } from '@/features/accounting/types';
import { formatAmount, isZeroAmount } from '@/lib/money';

const amount = (value: string) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        formatAmount(value)
    );

/** One row per income or expense code, as the IPUV paper prints it, each currency apart. */
export function CodeTable({
    title,
    rows,
}: {
    title: string;
    rows: MonthSummary['by_code'];
}) {
    return (
        <section aria-label={title} className="flex flex-col gap-2">
            <h3 className="text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                {title}
            </h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Sin movimientos.
                </p>
            ) : (
                <table className="w-full table-fixed text-xs sm:text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                            <th
                                scope="col"
                                className="w-2/5 py-2 pr-3 font-medium"
                            >
                                Código
                            </th>
                            <th
                                scope="col"
                                className="py-2 pr-3 text-right font-medium"
                            >
                                Bs
                            </th>
                            <th
                                scope="col"
                                className="py-2 pr-3 text-right font-medium"
                            >
                                USD
                            </th>
                            <th
                                scope="col"
                                className="py-2 text-right font-medium"
                            >
                                COP
                            </th>
                        </tr>
                    </thead>
                    <tbody className="tabular-nums">
                        {rows.map((row) => (
                            <tr
                                key={row.code}
                                className="border-b last:border-0"
                            >
                                <th
                                    scope="row"
                                    className="py-2 pr-3 text-left font-medium"
                                >
                                    <span className="font-mono">
                                        {row.code}
                                    </span>
                                    <span className="block text-xs font-normal break-words text-muted-foreground">
                                        {row.name}
                                    </span>
                                </th>
                                <td className="py-2 pr-3 text-right">
                                    {amount(row.ves)}
                                </td>
                                <td className="py-2 pr-3 text-right">
                                    {amount(row.usd)}
                                </td>
                                <td className="py-2 text-right">
                                    {amount(row.cop)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </section>
    );
}
