import { CheckCircle2, XCircle } from 'lucide-react';
import type { CheckResult } from '@/features/integrity/types';
import { cn } from '@/lib/utils';

/** Every control the modules registered, run live: what must always hold, and what breaks it. */
export function ChecksPanel({ checks }: { checks: CheckResult[] }) {
    const failing = checks.filter((check) => !check.passed).length;

    return (
        <section
            aria-label="Controles de los libros"
            className="flex min-w-0 flex-col gap-4 rounded-2xl border bg-card p-5"
        >
            <header className="flex flex-col gap-0.5">
                <h2 className="text-base font-semibold">
                    Controles de los libros
                </h2>
                <p className="text-sm text-muted-foreground">
                    {failing === 0
                        ? 'Todos se cumplen ahora mismo.'
                        : `${failing} de ${checks.length} no se cumplen.`}
                </p>
            </header>
            <ul className="flex flex-col gap-2">
                {checks.map((check) => (
                    <li
                        key={check.id}
                        className={cn(
                            'flex min-w-0 flex-col gap-2 rounded-xl border p-3',
                            !check.passed && 'border-negative/40 bg-negative/5',
                        )}
                    >
                        <div className="flex items-start gap-3">
                            {check.passed ? (
                                <CheckCircle2
                                    aria-label="Se cumple"
                                    className="mt-0.5 size-5 shrink-0 text-positive"
                                />
                            ) : (
                                <XCircle
                                    aria-label="No se cumple"
                                    className="mt-0.5 size-5 shrink-0 text-negative"
                                />
                            )}
                            <span className="min-w-0 text-sm">
                                {check.title}
                            </span>
                        </div>
                        {check.findings.length > 0 && (
                            <ul className="ml-8 flex flex-col gap-1 text-xs text-negative">
                                {check.findings.map((finding) => (
                                    <li key={finding} className="break-words">
                                        {finding}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
