import { ShieldAlert, ShieldCheck, ShieldQuestion } from 'lucide-react';
import type { CheckResult, IntegrityStatus } from '@/features/integrity/types';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';
import { shortHash } from './block-columns';

type Tone = 'ok' | 'warning' | 'unknown';

function verdict(
    status: IntegrityStatus,
    checks: CheckResult[],
): { tone: Tone; title: string; detail: string } {
    const last = status.last_chain_check;
    const failing = checks.filter((check) => !check.passed).length;

    if (last === null) {
        return {
            tone: 'unknown',
            title: 'La cadena aún no se ha verificado',
            detail: 'Ejecute la verificación para saber si está íntegra y si coincide con los libros.',
        };
    }

    if (!last.intact) {
        return {
            tone: 'warning',
            title: 'La cadena fue alterada',
            detail: 'Un bloque no coincide con su huella: es un incidente de seguridad.',
        };
    }

    if (!last.matches_books || failing > 0) {
        return {
            tone: 'warning',
            title: 'Hay diferencias que revisar',
            detail:
                failing > 0
                    ? `${failing} ${failing === 1 ? 'control no se cumple' : 'controles no se cumplen'}.`
                    : 'Los libros cambiaron después de sellarse.',
        };
    }

    return {
        tone: 'ok',
        title: 'Cadena íntegra y cuadrada con los libros',
        detail: 'Cada bloque coincide con su huella y con lo que hoy dicen los libros.',
    };
}

const ICON = { ok: ShieldCheck, warning: ShieldAlert, unknown: ShieldQuestion };

const TONE = {
    ok: 'border-positive/40 bg-positive/10 text-positive',
    warning: 'border-warning/50 bg-warning/15 text-warning-foreground',
    unknown: 'border-border bg-muted text-muted-foreground',
} as const;

/** The one sentence that says whether the books can be trusted, with the head of the chain under it. */
export function StatusBanner({
    status,
    checks,
}: {
    status: IntegrityStatus;
    checks: CheckResult[];
}) {
    const { tone, title, detail } = verdict(status, checks);
    const Icon = ICON[tone];

    return (
        <section
            aria-label="Estado de la cadena"
            className={cn(
                'flex flex-col gap-5 rounded-2xl border-2 p-5 md:p-6',
                TONE[tone],
            )}
        >
            <div className="flex items-start gap-4">
                <Icon aria-hidden className="mt-0.5 size-9 shrink-0" />
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 className="text-xl font-bold tracking-tight text-foreground">
                        {title}
                    </h2>
                    <p className="text-sm text-foreground/80">{detail}</p>
                    {status.last_chain_check && (
                        <p className="text-xs text-foreground/70">
                            Última verificación:{' '}
                            {formatDateTime(status.last_chain_check.at)}
                        </p>
                    )}
                </div>
            </div>
            <dl className="grid grid-cols-2 gap-3 text-foreground md:grid-cols-4">
                <Stat label="Bloques sellados" value={String(status.blocks)} />
                <Stat
                    label="Último bloque"
                    value={status.height === null ? '—' : `#${status.height}`}
                />
                <Stat
                    label="Huella del último bloque"
                    value={
                        status.head_hash === null
                            ? '—'
                            : shortHash(status.head_hash)
                    }
                    title={status.head_hash ?? undefined}
                    mono
                />
                <Stat
                    label="Cadena anterior (archivada)"
                    value={
                        status.legacy === null
                            ? '—'
                            : `#${status.legacy.height} · ${shortHash(status.legacy.hash)}`
                    }
                    title={status.legacy?.hash}
                    mono
                />
            </dl>
        </section>
    );
}

function Stat({
    label,
    value,
    title,
    mono = false,
}: {
    label: string;
    value: string;
    title?: string | undefined;
    mono?: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5 rounded-xl bg-background/70 p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'font-semibold tabular-nums',
                    mono && 'font-mono text-xs break-all',
                )}
                title={title}
            >
                {value}
            </dd>
        </div>
    );
}
