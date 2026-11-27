import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    ShieldAlert,
    ShieldCheck,
    ShieldQuestion,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { HashField } from '@/features/integrity/components/hash-field';
import type { ProofStatus, RecordProof } from '@/features/integrity/types';
import { cn } from '@/lib/utils';
import {
    index as integrityIndex,
    proof as proofRoute,
} from '@/routes/integrity';
import { show } from '@/routes/integrity/blocks';

const VERDICT: Record<
    ProofStatus,
    { tone: 'ok' | 'warning' | 'unknown'; title: string; detail: string }
> = {
    valid: {
        tone: 'ok',
        title: 'El registro estaba en el período cuando se cerró',
        detail: 'El camino desde el registro hasta la raíz sellada se recalcula y coincide: el registro no ha cambiado desde el cierre.',
    },
    changed: {
        tone: 'warning',
        title: 'El registro cambió después de sellarse',
        detail: 'Con lo que hoy dicen los libros, el camino no llega a la raíz que se selló: el registro fue modificado, o ya no es el mismo.',
    },
    missing: {
        tone: 'warning',
        title: 'Hoy ese registro no está en el período',
        detail: 'Los libros ya no contienen un registro con esa clave en este período, así que no hay con qué armar la prueba.',
    },
    unsealed: {
        tone: 'unknown',
        title: 'Este período aún no está sellado',
        detail: 'No hay bloque que respalde la prueba. Ciérrelo y séllelo primero.',
    },
    unsupported: {
        tone: 'unknown',
        title: 'Este tipo de período no ofrece pruebas por registro',
        detail: 'Sus bloques sellan totales, no registros individuales.',
    },
};

const ICON = { ok: ShieldCheck, warning: ShieldAlert, unknown: ShieldQuestion };
const TONE = {
    ok: 'border-positive/40 bg-positive/10 text-positive',
    warning: 'border-warning/50 bg-warning/15 text-warning-foreground',
    unknown: 'border-border bg-muted text-muted-foreground',
} as const;

export default function IntegrityProof({ proof }: { proof: RecordProof }) {
    const verdict = VERDICT[proof.status];
    const Icon = ICON[verdict.tone];
    const json = proofRoute({
        query: {
            kind: proof.kind,
            period: proof.period_key,
            record: proof.record_key,
            format: 'json',
        },
    });

    return (
        <>
            <Head title="Prueba de sellado" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad · Prueba de sellado"
                    title="Prueba de un registro"
                    description={`Período ${proof.period_key}. Un registro y los hashes que lo unen, paso a paso, con la raíz que quedó sellada.`}
                    actions={
                        <>
                            {proof.height !== null && (
                                <Button variant="outline" asChild>
                                    <Link href={show(proof.height)} prefetch>
                                        <ArrowLeft aria-hidden />
                                        Bloque #{proof.height}
                                    </Link>
                                </Button>
                            )}
                            {proof.leaf !== null && (
                                <Button variant="outline" asChild>
                                    <a href={json.url}>
                                        <Download aria-hidden />
                                        Descargar prueba (JSON)
                                    </a>
                                </Button>
                            )}
                        </>
                    }
                />

                <section
                    aria-label="Resultado"
                    className={cn(
                        'flex items-start gap-4 rounded-2xl border-2 p-5',
                        TONE[verdict.tone],
                    )}
                >
                    <Icon aria-hidden className="mt-0.5 size-9 shrink-0" />
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="text-xl font-bold tracking-tight text-foreground">
                            {verdict.title}
                        </h2>
                        <p className="text-sm text-foreground/80">
                            {verdict.detail}
                        </p>
                    </div>
                </section>

                {proof.leaf !== null && (
                    <section
                        aria-label="Camino de la prueba"
                        className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
                    >
                        <h2 className="text-base font-semibold">
                            El camino hasta la raíz
                        </h2>
                        <dl className="grid gap-4">
                            <HashField
                                label={`Registro ${proof.record_key}: su huella`}
                                value={proof.leaf}
                            />
                        </dl>
                        <ol className="flex flex-col gap-3">
                            {proof.steps.map((step, index) => (
                                <li
                                    key={`${index}-${step.hash}`}
                                    className="flex min-w-0 flex-col gap-1 rounded-xl border p-3"
                                >
                                    <span className="text-xs font-medium text-muted-foreground">
                                        Paso {index + 1}: se une con este hash,
                                        que va a la{' '}
                                        {step.side === 'left'
                                            ? 'izquierda'
                                            : 'derecha'}
                                        , y se vuelve a calcular
                                    </span>
                                    <span className="font-mono text-xs break-all">
                                        {step.hash}
                                    </span>
                                </li>
                            ))}
                            {proof.steps.length === 0 && (
                                <li className="rounded-xl border p-3 text-sm text-muted-foreground">
                                    Este período tiene un solo registro: su
                                    huella es la raíz.
                                </li>
                            )}
                        </ol>
                        <dl className="grid gap-4">
                            <HashField
                                label="Raíz sellada en el bloque (a donde debe llegar)"
                                value={proof.merkle_root}
                            />
                        </dl>
                    </section>
                )}
            </div>
        </>
    );
}

IntegrityProof.layout = {
    breadcrumbs: [{ title: 'Centro de integridad', href: integrityIndex() }],
};
