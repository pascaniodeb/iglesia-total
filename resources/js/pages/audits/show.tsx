import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, CircleAlert } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { CheckCard } from '@/features/audits/components/check-card';
import type { AuditSummary, CheckRow } from '@/features/audits/types';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';
import { index as auditsIndex } from '@/routes/audits';

type AuditShowProps = { audit: AuditSummary & { checks: CheckRow[] } };

export default function AuditShow({ audit }: AuditShowProps) {
    const clean = audit.status === 'clean';
    const Icon = clean ? CheckCircle2 : CircleAlert;

    return (
        <>
            <Head title={`Auditoría del ${formatDateTime(audit.ran_at)}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-8">
                <PageHeader
                    eyebrow={`Integridad · Auditoría${audit.is_imported ? ' del sistema anterior' : ''}`}
                    title={formatDateTime(audit.ran_at)}
                    description={`${audit.ran_by ? `La ejecutó ${audit.ran_by}. ` : ''}Esto es lo que se encontró en ese momento, tal como quedó registrado.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={auditsIndex()} prefetch>
                                <ArrowLeft aria-hidden />
                                Auditoría
                            </Link>
                        </Button>
                    }
                />

                <section
                    aria-label="Resultado"
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
                                : `${audit.findings_count} pendientes (${audit.critical_count} críticos)`}
                        </h2>
                        <p className="text-sm text-foreground/80">
                            {audit.acknowledged_count} aceptados con su motivo.
                        </p>
                        {audit.notes && (
                            <p className="text-sm break-words text-foreground">
                                «{audit.notes}»
                            </p>
                        )}
                    </div>
                </section>

                {audit.checks.length > 0 ? (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {audit.checks.map((check) => (
                            <CheckCard
                                key={check.id}
                                check={check}
                                canRecord={false}
                            />
                        ))}
                    </div>
                ) : (
                    <p className="rounded-2xl border bg-card p-6 text-sm text-muted-foreground">
                        Esta auditoría del sistema anterior conserva sus cifras
                        y sus observaciones, pero no el detalle de cada
                        hallazgo.
                    </p>
                )}
            </div>
        </>
    );
}

AuditShow.layout = {
    breadcrumbs: [{ title: 'Auditoría', href: auditsIndex() }],
};
