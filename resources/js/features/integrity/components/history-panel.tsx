import { CheckCircle2, XCircle } from 'lucide-react';
import type { HistoryEntry } from '@/features/integrity/types';
import { formatDateTime } from '@/lib/date';

/** The latest verifications, whoever or whatever ran them (the schedule, a command, a person). */
export function HistoryPanel({ history }: { history: HistoryEntry[] }) {
    return (
        <section
            aria-label="Historial de verificaciones"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <h2 className="text-base font-semibold">
                Historial de verificaciones
            </h2>
            {history.length === 0 ? (
                <p className="rounded-xl bg-muted/50 p-4 text-center text-sm text-muted-foreground">
                    Aún no se ha verificado nada.
                </p>
            ) : (
                <ol className="flex flex-col divide-y">
                    {history.map((entry) => (
                        <li
                            key={entry.id}
                            className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            {entry.passed ? (
                                <CheckCircle2
                                    aria-label="Correcta"
                                    className="mt-0.5 size-4 shrink-0 text-positive"
                                />
                            ) : (
                                <XCircle
                                    aria-label="Con diferencias"
                                    className="mt-0.5 size-4 shrink-0 text-negative"
                                />
                            )}
                            <span className="flex min-w-0 flex-col">
                                <span className="text-sm break-words">
                                    {entry.summary}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(entry.at)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
