import { Clock3, TriangleAlert } from 'lucide-react';
import type { ChainCheck } from '@/features/integrity/types';

const KIND = { weekly: 'Semana', monthly: 'Mes' } as Record<string, string>;

/**
 * What the last chain verification found: periods closed but not sealed yet ("seal pending") and
 * blocks that do not match. It renders nothing when there is nothing to say.
 */
export function ChainFindings({ check }: { check: ChainCheck | null }) {
    if (
        check === null ||
        (check.unsealed.length === 0 && check.issues.length === 0)
    ) {
        return null;
    }

    return (
        <section
            aria-label="Hallazgos de la cadena"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <h2 className="text-base font-semibold">Hallazgos de la cadena</h2>

            {check.issues.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {check.issues.map((issue) => (
                        <li
                            key={`${issue.type}-${issue.height}`}
                            className="flex items-start gap-3 rounded-xl border border-negative/40 bg-negative/5 p-3 text-sm"
                        >
                            <TriangleAlert
                                aria-hidden
                                className="mt-0.5 size-4 shrink-0 text-negative"
                            />
                            <span className="min-w-0 break-words">
                                Bloque #{issue.height} (
                                {KIND[issue.kind] ?? issue.kind}{' '}
                                {issue.period_key}):{' '}
                                {issue.type === 'drift'
                                    ? 'los libros cambiaron después de sellarse'
                                    : 'la huella no coincide'}
                                .
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {check.unsealed.length > 0 && (
                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-medium text-muted-foreground">
                        Cerrados, con el sello pendiente
                    </h3>
                    <ul className="flex flex-wrap gap-2">
                        {check.unsealed.map((period) => (
                            <li
                                key={`${period.kind}-${period.period_key}`}
                                className="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-3 py-1 text-xs font-semibold text-warning-foreground"
                            >
                                <Clock3 aria-hidden className="size-3.5" />
                                {KIND[period.kind] ?? period.kind}{' '}
                                {period.period_key}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
