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
import { formatShortDate } from '@/features/treasury/rates';
import type { NextWeek } from '@/features/treasury/types';
import { formatAmount } from '@/lib/money';
import { store } from '@/routes/treasury/weekly-closures';

/**
 * The next week to close: what it holds right now and the button to close it. A disabled close
 * always says why. Closing asks for an explicit confirmation: it is irreversible.
 */
export function NextWeekCard({
    week,
    canManage,
}: {
    week: NextWeek;
    canManage: boolean;
}) {
    const [isConfirming, setIsConfirming] = useState(false);
    const form = useForm({ week_key: week.week_key });
    const range = `${formatShortDate(week.week_start)} al ${formatShortDate(week.week_end)}`;

    function close() {
        form.submit(store(), {
            preserveScroll: true,
            onFinish: () => setIsConfirming(false),
        });
    }

    return (
        <section
            aria-labelledby="next-week-title"
            className="grid grid-cols-1 gap-6 rounded-[2rem] border-2 border-border bg-card p-6 shadow-sm md:p-8 lg:grid-cols-[1.2fr_2fr]"
        >
            <div className="flex flex-col gap-3">
                <span className="text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                    Próxima semana a cerrar
                </span>
                <h2
                    id="next-week-title"
                    className="text-3xl font-black tracking-tight"
                >
                    Semana del {range}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {week.week_key} · {week.snapshot.count}{' '}
                    {week.snapshot.count === 1 ? 'ofrenda' : 'ofrendas'}
                    {week.months.length > 1 &&
                        ' · comparte días entre dos meses'}
                </p>
                {week.blocker ? (
                    <p className="text-sm font-semibold text-warning">
                        {week.blocker}
                    </p>
                ) : (
                    canManage && (
                        <Button
                            className="w-full sm:w-auto"
                            onClick={() => setIsConfirming(true)}
                        >
                            <Lock aria-hidden />
                            Cerrar semana
                        </Button>
                    )
                )}
            </div>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Fact label="Bolívares" value={week.snapshot.ves} />
                <Fact label="Dólares" value={week.snapshot.usd} />
                <Fact label="Pesos" value={week.snapshot.cop} />
            </dl>

            <Dialog open={isConfirming} onOpenChange={setIsConfirming}>
                <DialogContent>
                    <DialogTitle>¿Cerrar la semana del {range}?</DialogTitle>
                    <DialogDescription>
                        Se guardan {week.snapshot.count}{' '}
                        {week.snapshot.count === 1 ? 'ofrenda' : 'ofrendas'} con
                        estos totales: Bs {formatAmount(week.snapshot.ves)} ·
                        USD {formatAmount(week.snapshot.usd)} · COP{' '}
                        {formatAmount(week.snapshot.cop)}. Después nadie podrá
                        agregar, corregir ni borrar ofrendas de esta semana. No
                        se puede deshacer.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button disabled={form.processing} onClick={close}>
                            {form.processing ? 'Cerrando…' : 'Cerrar semana'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col justify-end gap-1 rounded-3xl bg-muted/60 p-5">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-black tracking-tight tabular-nums">
                {formatAmount(value)}
            </dd>
        </div>
    );
}
