import {
    CheckCircle2,
    CircleAlert,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { CheckRow, FindingRow } from '@/features/audits/types';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';
import { acknowledge, revoke } from '@/routes/audits';
import { ReasonDialog } from './reason-dialog';
import { SeverityBadge } from './severity-badge';

type CheckCardProps = {
    check: CheckRow;
    /** Who may accept a finding or lift an acceptance; false on a stored audit (read-only). */
    canRecord: boolean;
};

/** One check of the audit: what it verifies, and each thing it found with what was decided about it. */
export function CheckCard({ check, canRecord }: CheckCardProps) {
    const [accepting, setAccepting] = useState<FindingRow | null>(null);
    const [lifting, setLifting] = useState<FindingRow | null>(null);
    const holds = check.findings.length === 0;

    return (
        <article
            className={cn(
                'flex min-w-0 flex-col gap-3 rounded-2xl border bg-card p-5',
                check.pending > 0 &&
                    check.severity !== 'info' &&
                    'border-warning/50',
            )}
        >
            <header className="flex items-start gap-3">
                {holds ? (
                    <CheckCircle2
                        aria-label="Se cumple"
                        className="mt-0.5 size-5 shrink-0 text-positive"
                    />
                ) : (
                    <CircleAlert
                        aria-label="Con hallazgos"
                        className={cn(
                            'mt-0.5 size-5 shrink-0',
                            check.severity === 'info'
                                ? 'text-muted-foreground'
                                : 'text-warning',
                        )}
                    />
                )}
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-base font-semibold">
                            {check.label}
                        </h3>
                        <SeverityBadge severity={check.severity} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {check.description}
                    </p>
                </div>
            </header>

            {holds ? (
                <p className="rounded-xl bg-positive/10 px-3 py-2 text-sm text-positive">
                    Se cumple: no hay nada que reportar.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {check.findings.map((finding) => (
                        <li
                            key={finding.fingerprint}
                            className="flex min-w-0 flex-col gap-2 rounded-xl border p-3"
                        >
                            <div className="flex min-w-0 flex-wrap items-start justify-between gap-2">
                                <div className="flex min-w-0 flex-col">
                                    <span className="text-sm font-semibold break-words">
                                        {finding.subject}
                                    </span>
                                    <span className="text-sm break-words text-muted-foreground">
                                        {finding.detail}
                                    </span>
                                </div>
                                {canRecord &&
                                    (finding.acknowledgement === null ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setAccepting(finding)
                                            }
                                        >
                                            <ShieldCheck aria-hidden />
                                            Aceptar
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setLifting(finding)}
                                        >
                                            <RotateCcw aria-hidden />
                                            Levantar
                                        </Button>
                                    ))}
                            </div>
                            {finding.acknowledgement && (
                                <p className="rounded-lg bg-muted/60 px-3 py-2 text-xs">
                                    <span className="font-semibold">
                                        Aceptado
                                    </span>
                                    {finding.acknowledgement.by
                                        ? ` por ${finding.acknowledgement.by}`
                                        : ''}{' '}
                                    el{' '}
                                    {formatDateTime(finding.acknowledgement.at)}
                                    : {finding.acknowledgement.reason}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {accepting && (
                <ReasonDialog
                    key={accepting.fingerprint}
                    open
                    onOpenChange={(open) => !open && setAccepting(null)}
                    title="¿Aceptar este hallazgo?"
                    description={`${accepting.subject}: ${accepting.detail} Queda anotado quién lo aceptó y por qué. Solo vale para este hallazgo con estos datos: si los datos cambian, vuelve a aparecer como pendiente.`}
                    field="reason"
                    label="Motivo"
                    help="Al menos 10 caracteres. Una aceptación sin motivo es esconder algo."
                    submitLabel="Aceptar hallazgo"
                    url={acknowledge().url}
                    extra={{ fingerprint: accepting.fingerprint }}
                />
            )}
            {lifting?.acknowledgement && (
                <ReasonDialog
                    key={lifting.acknowledgement.id}
                    open
                    onOpenChange={(open) => !open && setLifting(null)}
                    title="¿Levantar la aceptación?"
                    description={`El hallazgo «${lifting.subject}» vuelve a quedar pendiente. La aceptación anterior se conserva en el historial.`}
                    field="reason"
                    label="Motivo"
                    help="Al menos 10 caracteres."
                    submitLabel="Levantar aceptación"
                    destructive
                    url={revoke(lifting.acknowledgement.id).url}
                />
            )}
        </article>
    );
}
