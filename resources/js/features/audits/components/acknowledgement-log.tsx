import type { AcknowledgementRow } from '@/features/audits/types';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';

/** Every acceptance, with who gave it, why, and who lifted it: nothing here is ever erased. */
export function AcknowledgementLog({ rows }: { rows: AcknowledgementRow[] }) {
    return (
        <section
            aria-label="Hallazgos aceptados"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <h2 className="text-base font-semibold">Hallazgos aceptados</h2>
            {rows.length === 0 ? (
                <p className="rounded-xl bg-muted/50 p-4 text-center text-sm text-muted-foreground">
                    Aún no se aceptó ningún hallazgo.
                </p>
            ) : (
                <ul className="flex flex-col divide-y">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            className="flex min-w-0 flex-col gap-1 py-2.5 first:pt-0 last:pb-0"
                        >
                            <span
                                className={cn(
                                    'text-sm font-semibold break-words',
                                    row.revoked &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {row.subject}
                            </span>
                            <span className="text-xs break-words text-muted-foreground">
                                {row.detail}
                            </span>
                            <span className="text-xs break-words">
                                {row.by ?? 'Alguien'} lo aceptó el{' '}
                                {formatDateTime(row.at)}: {row.reason}
                            </span>
                            {row.revoked && (
                                <span className="text-xs break-words text-negative">
                                    {row.revoked.by ?? 'Alguien'} levantó la
                                    aceptación el{' '}
                                    {formatDateTime(row.revoked.at)}:{' '}
                                    {row.revoked.reason}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
