import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleAlert } from 'lucide-react';
import type { AuditSummary } from '@/features/audits/types';
import { formatDateTime } from '@/lib/date';
import { show } from '@/routes/audits';

/** The audits that were run, newest first: the answer to "how long has nobody looked". */
export function AuditHistory({ audits }: { audits: AuditSummary[] }) {
    return (
        <section
            aria-label="Auditorías realizadas"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <h2 className="text-base font-semibold">Auditorías realizadas</h2>
            {audits.length === 0 ? (
                <p className="rounded-xl bg-muted/50 p-4 text-center text-sm text-muted-foreground">
                    Aún no se ha ejecutado ninguna auditoría.
                </p>
            ) : (
                <ol className="flex flex-col divide-y">
                    {audits.map((audit) => (
                        <li
                            key={audit.id}
                            className="py-2.5 first:pt-0 last:pb-0"
                        >
                            <Link
                                href={show(audit.id)}
                                prefetch
                                className="group flex min-w-0 items-start gap-3"
                            >
                                {audit.status === 'clean' ? (
                                    <CheckCircle2
                                        aria-label="Sin hallazgos"
                                        className="mt-0.5 size-5 shrink-0 text-positive"
                                    />
                                ) : (
                                    <CircleAlert
                                        aria-label="Con hallazgos"
                                        className="mt-0.5 size-5 shrink-0 text-warning"
                                    />
                                )}
                                <span className="flex min-w-0 flex-col">
                                    <span className="text-sm font-semibold group-hover:underline">
                                        {formatDateTime(audit.ran_at)}
                                        {audit.is_imported
                                            ? ' · sistema anterior'
                                            : ''}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {audit.status === 'clean'
                                            ? 'Sin hallazgos pendientes'
                                            : `${audit.findings_count} pendientes (${audit.critical_count} críticos)`}
                                        {audit.acknowledged_count > 0
                                            ? ` · ${audit.acknowledged_count} aceptados`
                                            : ''}
                                        {audit.ran_by
                                            ? ` · ${audit.ran_by}`
                                            : ''}
                                    </span>
                                    {audit.notes && (
                                        <span className="mt-0.5 text-xs break-words">
                                            {audit.notes}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
