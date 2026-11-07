import { useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
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
import { CashStatementTable } from '@/features/accounting/components/cash-statement-table';
import type { NextPeriod } from '@/features/accounting/types';
import { formatAmount } from '@/lib/money';
import { formatMonth } from '@/lib/month';
import { store } from '@/routes/accounting/periods';

/**
 * Step 4: the month to close, its cash statement and the button. A disabled close always says
 * why; closing is irreversible and the next month opens with this closing balance.
 */
export function NextPeriodCard({
    period,
    canManage,
}: {
    period: NextPeriod;
    canManage: boolean;
}) {
    const [isConfirming, setIsConfirming] = useState(false);
    const form = useForm({ month: period.month });
    const name = formatMonth(period.month);
    const closing = period.summary.currencies;

    function close() {
        form.submit(store(), {
            preserveScroll: true,
            onFinish: () => setIsConfirming(false),
        });
    }

    return (
        <section
            aria-labelledby="next-period-title"
            className="flex flex-col gap-6 rounded-[2rem] border-2 border-border bg-card p-6 shadow-sm md:p-8"
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex flex-col gap-2">
                    <span className="text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                        Próximo mes a cerrar
                    </span>
                    <h2
                        id="next-period-title"
                        className="text-3xl font-black tracking-tight"
                    >
                        {name}
                    </h2>
                </div>
                {period.blocker ? (
                    <p className="max-w-md text-sm font-semibold text-warning">
                        {period.blocker}
                    </p>
                ) : (
                    canManage && (
                        <Button
                            className="w-full shrink-0 sm:w-auto"
                            onClick={() => setIsConfirming(true)}
                        >
                            <Lock aria-hidden />
                            Cerrar el mes
                        </Button>
                    )
                )}
            </div>

            <CashStatementTable currencies={closing} />

            <Dialog open={isConfirming} onOpenChange={setIsConfirming}>
                <DialogContent>
                    <DialogTitle>
                        ¿Cerrar la contabilidad de {name}?
                    </DialogTitle>
                    <DialogDescription>
                        Se guardan los saldos de todas las cuentas y el mes se
                        sella. El mes siguiente arranca con este saldo: Bs{' '}
                        {formatAmount(closing.VES.closing)} · USD{' '}
                        {formatAmount(closing.USD.closing)} · COP{' '}
                        {formatAmount(closing.COP.closing)}. Un mes cerrado no
                        se reabre.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button disabled={form.processing} onClick={close}>
                            {form.processing ? 'Cerrando…' : 'Cerrar el mes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
