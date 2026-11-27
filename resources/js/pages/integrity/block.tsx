import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, FileSearch, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { HashField } from '@/features/integrity/components/hash-field';
import type { BlockDetail } from '@/features/integrity/types';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';
import { index as integrityIndex } from '@/routes/integrity';
import { proof } from '@/routes/integrity';

const KIND = { genesis: 'Génesis', weekly: 'Semana', monthly: 'Mes' } as const;

function Verdict({ ok, children }: { ok: boolean; children: ReactNode }) {
    const Icon = ok ? CheckCircle2 : XCircle;

    return (
        <li
            className={cn(
                'flex items-start gap-3 rounded-xl border p-3 text-sm',
                ok
                    ? 'border-positive/30 bg-positive/5'
                    : 'border-negative/40 bg-negative/5',
            )}
        >
            <Icon
                aria-hidden
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    ok ? 'text-positive' : 'text-negative',
                )}
            />
            <span className="min-w-0">{children}</span>
        </li>
    );
}

export default function IntegrityBlock({
    block,
    hash_recomputes: hashRecomputes,
    payload_recomputes: payloadRecomputes,
    books,
    payload,
    leaves,
    leaves_total: leavesTotal,
    proofs_offered: proofsOffered,
}: BlockDetail) {
    const title =
        block.period_key === null
            ? KIND[block.kind]
            : `${KIND[block.kind]} ${block.period_key}`;

    return (
        <>
            <Head title={`Bloque ${block.height}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad · Bloque sellado"
                    title={`#${block.height}`}
                    description={`${title}. Sellado el ${formatDateTime(block.sealed_at)}${block.sealed_by ? ` por ${block.sealed_by}` : ''}.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={integrityIndex()} prefetch>
                                <ArrowLeft aria-hidden />
                                Centro de integridad
                            </Link>
                        </Button>
                    }
                />

                <ul
                    className="grid gap-3 lg:grid-cols-3"
                    aria-label="Verificación del bloque"
                >
                    <Verdict ok={hashRecomputes}>
                        {hashRecomputes
                            ? 'La huella del bloque se recalcula y coincide.'
                            : 'La huella guardada NO coincide con la que sale al recalcularla.'}
                    </Verdict>
                    <Verdict ok={payloadRecomputes}>
                        {payloadRecomputes
                            ? 'El contenido sellado coincide con su huella.'
                            : 'El contenido sellado NO coincide con su huella.'}
                    </Verdict>
                    <Verdict ok={books === 'matches'}>
                        {books === 'matches' &&
                            'Los libros de hoy dicen lo mismo que cuando se selló.'}
                        {books === 'drifted' &&
                            'Los libros cambiaron después de sellarse este período.'}
                        {books === 'unavailable' &&
                            'No se pudo reconstruir el período para compararlo con los libros.'}
                    </Verdict>
                </ul>

                <section
                    className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
                    aria-label="Huellas"
                >
                    <h2 className="text-base font-semibold">Huellas</h2>
                    <dl className="grid gap-4 lg:grid-cols-2">
                        <HashField
                            label="Huella de este bloque"
                            value={block.hash}
                        />
                        <HashField
                            label="Huella del bloque anterior"
                            value={block.prev_hash}
                        />
                        <HashField
                            label="Raíz del árbol de registros (Merkle)"
                            value={block.merkle_root}
                        />
                        <HashField
                            label="Huella del contenido sellado"
                            value={block.payload_hash}
                        />
                    </dl>
                    <p className="text-sm text-muted-foreground">
                        La huella del bloque se calcula con su altura, la huella
                        del anterior, la raíz de sus registros y la huella de su
                        contenido: cambiar cualquiera rompe toda la cadena de
                        ahí en adelante.
                    </p>
                </section>

                <section
                    className="flex min-w-0 flex-col gap-3 rounded-2xl border bg-card p-5"
                    aria-label="Contenido sellado"
                >
                    <h2 className="text-base font-semibold">
                        Contenido sellado
                    </h2>
                    <pre className="max-h-96 overflow-auto rounded-xl bg-muted/60 p-4 text-xs leading-relaxed">
                        {JSON.stringify(payload, null, 2)}
                    </pre>
                </section>

                {proofsOffered && (
                    <section
                        className="flex min-w-0 flex-col gap-3 rounded-2xl border bg-card p-5"
                        aria-label="Registros sellados"
                    >
                        <header className="flex flex-col gap-0.5">
                            <h2 className="text-base font-semibold">
                                Registros sellados
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {leavesTotal}{' '}
                                {leavesTotal === 1 ? 'registro' : 'registros'}
                                {leavesTotal > leaves.length
                                    ? `; se muestran los primeros ${leaves.length}`
                                    : ''}
                                . La prueba de un registro demuestra que estaba
                                dentro del período cuando se cerró.
                            </p>
                        </header>
                        <ul className="flex flex-col divide-y">
                            {leaves.map((leaf) => (
                                <li
                                    key={leaf.key}
                                    className="flex min-w-0 flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <span className="flex min-w-0 flex-col">
                                        <span className="font-mono text-xs break-all">
                                            {leaf.key}
                                        </span>
                                        <span className="font-mono text-[0.65rem] break-all text-muted-foreground">
                                            {leaf.leaf}
                                        </span>
                                    </span>
                                    <Button size="sm" variant="outline" asChild>
                                        <Link
                                            href={proof({
                                                query: {
                                                    kind: block.kind,
                                                    period:
                                                        block.period_key ?? '',
                                                    record: leaf.key,
                                                },
                                            })}
                                        >
                                            <FileSearch aria-hidden />
                                            Prueba
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

IntegrityBlock.layout = {
    breadcrumbs: [{ title: 'Centro de integridad', href: integrityIndex() }],
};
