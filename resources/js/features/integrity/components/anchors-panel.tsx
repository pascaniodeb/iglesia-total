import { Anchor, Clock3, ShieldCheck } from 'lucide-react';
import type { AnchorsSummary } from '@/features/integrity/types';
import { formatDateTime } from '@/lib/date';

const CHAIN = { ledger: 'Libro sellado', activity: 'Bitácora' } as const;
const PROVIDER = {
    rfc3161: 'Sello de tiempo (RFC 3161)',
    opentimestamps: 'Bitcoin (OpenTimestamps)',
} as Record<string, string>;

/**
 * What was pinned outside the church: the head of each chain published (as a digest, never data) with a
 * timestamp authority and with Bitcoin, so rewriting history stays provable even with full access to the
 * database.
 */
export function AnchorsPanel({ anchors }: { anchors: AnchorsSummary }) {
    return (
        <section
            aria-label="Anclaje externo"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <header className="flex flex-col gap-0.5">
                <h2 className="text-base font-semibold">Anclaje externo</h2>
                <p className="text-sm text-muted-foreground">
                    {anchors.enabled
                        ? `${anchors.confirmed} confirmados, ${anchors.pending} pendientes. Solo se publica una huella, nunca datos.`
                        : 'Está apagado: la cadena solo se prueba a sí misma. Al activarlo, la huella del último bloque se publica fuera de la iglesia.'}
                </p>
            </header>
            {anchors.recent.length === 0 ? (
                <p className="rounded-xl bg-muted/50 p-4 text-center text-sm text-muted-foreground">
                    Aún no se ha anclado nada.
                </p>
            ) : (
                <ul className="flex flex-col divide-y">
                    {anchors.recent.map((anchor) => (
                        <li
                            key={anchor.id}
                            className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            {anchor.status === 'confirmed' ? (
                                <ShieldCheck
                                    aria-label="Confirmado"
                                    className="mt-0.5 size-4 shrink-0 text-positive"
                                />
                            ) : (
                                <Clock3
                                    aria-label="Pendiente"
                                    className="mt-0.5 size-4 shrink-0 text-warning"
                                />
                            )}
                            <span className="flex min-w-0 flex-col">
                                <span className="text-sm">
                                    {CHAIN[anchor.chain]} · posición{' '}
                                    {anchor.position}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    <Anchor
                                        aria-hidden
                                        className="mr-1 inline size-3"
                                    />
                                    {PROVIDER[anchor.provider] ??
                                        anchor.provider}{' '}
                                    ·{' '}
                                    {anchor.status === 'confirmed'
                                        ? 'confirmado'
                                        : 'pendiente'}{' '}
                                    el {formatDateTime(anchor.at)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
