import { useForm } from '@inertiajs/react';
import { BookCheck, CheckCircle2, CircleDashed } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatShortDate } from '@/features/treasury/rates';
import type { NextMonth } from '@/features/treasury/types';
import { cn } from '@/lib/utils';
import { formatAmount, isZeroAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { store } from '@/routes/treasury/monthly-offering-closures';

const amount = (value: string) =>
    isZeroAmount(value) ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        formatAmount(value)
    );

/**
 * The next month whose offerings close: its weeks (all must be closed), what will be posted to
 * each income account, and the button. A disabled close always says why; closing is
 * irreversible and posts to the journal.
 */
export function NextMonthCard({
    month,
    canManage,
}: {
    month: NextMonth;
    canManage: boolean;
}) {
    const [isConfirming, setIsConfirming] = useState(false);
    const form = useForm({ month: month.month });
    const name = formatMonth(month.month);

    function close() {
        form.submit(store(), {
            preserveScroll: true,
            onFinish: () => setIsConfirming(false),
        });
    }

    return (
        <section
            aria-labelledby="next-month-title"
            className="flex flex-col gap-6 rounded-[2rem] border-2 border-border bg-card p-6 shadow-sm md:p-8"
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex flex-col gap-2">
                    <span className="text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                        Próximo mes a cerrar
                    </span>
                    <h2
                        id="next-month-title"
                        className="text-3xl font-black tracking-tight"
                    >
                        Ofrendas de {name}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {month.snapshot.count}{' '}
                        {month.snapshot.count === 1 ? 'ofrenda' : 'ofrendas'} ·
                        se asientan en el Libro Diario con fecha{' '}
                        {formatShortDate(month.last_day)}
                    </p>
                </div>
                {month.blocker ? (
                    <p className="max-w-md text-sm font-semibold text-warning">
                        {month.blocker}
                    </p>
                ) : (
                    canManage && (
                        <Button
                            className="w-full shrink-0 sm:w-auto"
                            onClick={() => setIsConfirming(true)}
                        >
                            <BookCheck aria-hidden />
                            Cerrar ofrendas del mes
                        </Button>
                    )
                )}
            </div>

            <ul aria-label="Semanas del mes" className="flex flex-wrap gap-2">
                {month.weeks.map((week) => (
                    <li
                        key={week.week_key}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                            week.is_closed
                                ? 'bg-primary/10 text-primary'
                                : 'bg-warning/15 text-warning',
                        )}
                    >
                        {week.is_closed ? (
                            <CheckCircle2 aria-hidden className="size-3.5" />
                        ) : (
                            <CircleDashed aria-hidden className="size-3.5" />
                        )}
                        {formatShortDate(week.week_start)} al{' '}
                        {formatShortDate(week.week_end)}
                        <span className="sr-only">
                            {week.is_closed ? ' (cerrada)' : ' (abierta)'}
                        </span>
                    </li>
                ))}
            </ul>

            <div className="min-w-0">
                <table className="w-full table-fixed text-xs sm:text-sm">
                    <caption className="sr-only">
                        Lo que se asentará por categoría
                    </caption>
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground uppercase">
                            <th
                                scope="col"
                                className="w-2/5 py-2 pr-3 font-medium"
                            >
                                Categoría → cuenta
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
                        {month.snapshot.categories.map((category) => (
                            <tr key={category.category_id} className="border-b">
                                <th
                                    scope="row"
                                    className="py-2 pr-3 text-left font-medium"
                                >
                                    {category.category}
                                    <span className="block text-xs font-normal text-muted-foreground">
                                        {category.account_code}{' '}
                                        {category.account_name}
                                    </span>
                                </th>
                                <td className="py-2 pr-3 text-right">
                                    {amount(category.ves)}
                                </td>
                                <td className="py-2 pr-3 text-right">
                                    {amount(category.usd)}
                                </td>
                                <td className="py-2 text-right">
                                    {amount(category.cop)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="font-black tabular-nums">
                        <tr>
                            <th scope="row" className="py-2 pr-3 text-left">
                                Total del mes
                            </th>
                            <td className="py-2 pr-3 text-right">
                                {formatAmount(month.snapshot.ves)}
                            </td>
                            <td className="py-2 pr-3 text-right">
                                {formatAmount(month.snapshot.usd)}
                            </td>
                            <td className="py-2 text-right">
                                {formatAmount(month.snapshot.cop)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <Dialog open={isConfirming} onOpenChange={setIsConfirming}>
                <DialogContent>
                    <DialogTitle>¿Cerrar las ofrendas de {name}?</DialogTitle>
                    <DialogDescription>
                        Se asienta en el Libro Diario un ingreso por cada
                        cuenta, con estos totales: Bs{' '}
                        {formatAmount(month.snapshot.ves)} · USD{' '}
                        {formatAmount(month.snapshot.usd)} · COP{' '}
                        {formatAmount(month.snapshot.cop)}. El cierre no se
                        puede deshacer; un error se corrige después con un
                        contraasiento.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button disabled={form.processing} onClick={close}>
                            {form.processing ? 'Cerrando…' : 'Cerrar y asentar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
