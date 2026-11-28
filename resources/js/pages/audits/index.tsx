import { Head } from '@inertiajs/react';
import { ClipboardCheck, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { AcknowledgementLog } from '@/features/audits/components/acknowledgement-log';
import { AuditHistory } from '@/features/audits/components/audit-history';
import { CheckCard } from '@/features/audits/components/check-card';
import { ReasonDialog } from '@/features/audits/components/reason-dialog';
import type {
    AcknowledgementRow,
    AuditSummary,
    Evaluation,
} from '@/features/audits/types';
import { cn } from '@/lib/utils';
import { index as auditsIndex, store } from '@/routes/audits';

type AuditsIndexProps = {
    evaluation: Evaluation;
    last_run_at: string | null;
    days_since_last_run: number | null;
    audits: AuditSummary[];
    acknowledgements: AcknowledgementRow[];
    can_record: boolean;
};

function lastRun(days: number | null): string {
    if (days === null) {
        return 'Nunca se ha ejecutado una auditoría.';
    }

    if (days === 0) {
        return 'La última auditoría se ejecutó hoy.';
    }

    return `La última auditoría se ejecutó hace ${days} ${days === 1 ? 'día' : 'días'}.`;
}

export default function AuditsIndex({
    evaluation,
    days_since_last_run: days,
    audits,
    acknowledgements,
    can_record: canRecord,
}: AuditsIndexProps) {
    const [isRunning, setIsRunning] = useState(false);
    const clean = evaluation.pending_count === 0;
    const Icon = clean ? ShieldCheck : TriangleAlert;

    return (
        <>
            <Head title="Auditoría" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow="Integridad · Contraloría"
                    title="Auditoría"
                    description="Cada control recalcula una cifra desde sus registros y la compara con lo que el sistema guardó. Lo que ve aquí se calcula en este momento; lo que se guarda es el acto de auditar: quién, cuándo y qué encontró."
                    actions={
                        canRecord && (
                            <Button onClick={() => setIsRunning(true)}>
                                <ClipboardCheck aria-hidden />
                                Ejecutar auditoría
                            </Button>
                        )
                    }
                />

                <section
                    aria-label="Resumen"
                    className={cn(
                        'flex items-start gap-4 rounded-2xl border-2 p-5',
                        clean
                            ? 'border-positive/40 bg-positive/10 text-positive'
                            : 'border-warning/50 bg-warning/15 text-warning',
                    )}
                >
                    <Icon aria-hidden className="mt-0.5 size-9 shrink-0" />
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="text-xl font-bold tracking-tight text-foreground">
                            {clean
                                ? 'Sin hallazgos pendientes'
                                : `${evaluation.pending_count} ${evaluation.pending_count === 1 ? 'hallazgo pendiente' : 'hallazgos pendientes'}${evaluation.critical_count > 0 ? ` (${evaluation.critical_count} ${evaluation.critical_count === 1 ? 'crítico' : 'críticos'})` : ''}`}
                        </h2>
                        <p className="text-sm text-foreground/80">
                            {evaluation.acknowledged_count > 0
                                ? `${evaluation.acknowledged_count} aceptados con su motivo. `
                                : ''}
                            {lastRun(days)}
                        </p>
                    </div>
                </section>

                <div className="grid gap-4 xl:grid-cols-2">
                    {evaluation.checks.map((check) => (
                        <CheckCard
                            key={check.id}
                            check={check}
                            canRecord={canRecord}
                        />
                    ))}
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <AuditHistory audits={audits} />
                    <AcknowledgementLog rows={acknowledgements} />
                </div>

                {isRunning && (
                    <ReasonDialog
                        open
                        onOpenChange={(open) => !open && setIsRunning(false)}
                        title="Ejecutar auditoría"
                        description="Se recalculan todos los controles y queda registrado quién la ejecutó, cuándo y qué se encontró, incluidos los hallazgos ya aceptados."
                        field="notes"
                        label="Observaciones"
                        help="Opcional. Lo que concluye quien audita."
                        submitLabel="Ejecutar y registrar"
                        required={false}
                        url={store().url}
                    />
                )}
            </div>
        </>
    );
}

AuditsIndex.layout = {
    breadcrumbs: [{ title: 'Auditoría', href: auditsIndex() }],
};
