import { Link } from '@inertiajs/react';
import {
    CalendarCheck,
    CheckCircle2,
    ChevronRight,
    Landmark,
    Layers,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { PendingWidget } from '../types';
import { Panel } from './panel';
import { formatShortDate } from '@/features/treasury/rates';
import { formatMonth } from '@/lib/month';
import { cn } from '@/lib/utils';
import { index as periods } from '@/routes/accounting/periods';
import { index as monthlyClosures } from '@/routes/treasury/monthly-offering-closures';
import { index as weeklyClosures } from '@/routes/treasury/weekly-closures';

type Task = {
    key: string;
    icon: LucideIcon;
    title: string;
    detail: ReactNode;
    ready: boolean;
    href: ReturnType<typeof weeklyClosures>;
};

function tasks(pending: PendingWidget): Task[] {
    const list: Task[] = [];

    if (pending.weeks_pending > 0) {
        const first = pending.weeks[0];
        list.push({
            key: 'weeks',
            icon: CalendarCheck,
            title:
                pending.weeks_pending === 1
                    ? '1 semana por cerrar'
                    : `${pending.weeks_pending} semanas por cerrar`,
            detail: first
                ? `Se cierran en orden, empezando por la semana ${first.key.split('-W')[1]} (terminó el ${formatShortDate(first.end)}).`
                : null,
            ready: pending.week_can_close,
            href: weeklyClosures(),
        });
    }

    if (pending.month) {
        list.push({
            key: 'month',
            icon: Layers,
            title: `Ofrendas de ${formatMonth(pending.month.key)}`,
            detail:
                pending.month.blocker ??
                'Todas sus semanas están cerradas: se puede cerrar el mes.',
            ready: pending.month.blocker === null,
            href: monthlyClosures(),
        });
    }

    if (pending.accounting) {
        list.push({
            key: 'accounting',
            icon: Landmark,
            title: `Contabilidad de ${formatMonth(pending.accounting.key)}`,
            detail:
                pending.accounting.blocker ??
                'Los libros del mes están listos para cerrarse y sellarse.',
            ready: pending.accounting.blocker === null,
            href: periods(),
        });
    }

    return list;
}

/** What is waiting to be closed, in the order it has to be done, and whether it can be done now. */
export function PendingClosures({ pending }: { pending: PendingWidget }) {
    const list = tasks(pending);

    return (
        <Panel
            title="Cierres pendientes"
            description="Semanas y meses esperando su cierre"
        >
            {list.length === 0 ? (
                <div className="flex items-center gap-3 rounded-xl bg-positive/10 p-4 text-positive">
                    <CheckCircle2 aria-hidden className="size-6 shrink-0" />
                    <p className="text-sm font-medium">
                        Todo al día: no hay nada por cerrar.
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col gap-2.5">
                    {list.map((task) => (
                        <li key={task.key}>
                            <Link
                                href={task.href}
                                prefetch
                                className={cn(
                                    'group flex items-center gap-3 rounded-xl border p-3.5 outline-none motion-safe:transition-colors',
                                    'hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                        task.ready
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    <task.icon aria-hidden className="size-5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span className="text-sm font-semibold">
                                            {task.title}
                                        </span>
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-[0.7rem] font-semibold',
                                                task.ready
                                                    ? 'bg-positive/10 text-positive'
                                                    : 'bg-warning/20 text-warning-foreground',
                                            )}
                                        >
                                            {task.ready
                                                ? 'Listo para cerrar'
                                                : 'Aún no se puede'}
                                        </span>
                                    </span>
                                    <span className="mt-0.5 block text-sm text-muted-foreground">
                                        {task.detail}
                                    </span>
                                </span>
                                <ChevronRight
                                    aria-hidden
                                    className="size-4 shrink-0 text-muted-foreground group-hover:translate-x-0.5 motion-safe:transition-transform"
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
