import { cn } from '@/lib/utils';

export type StepNavItem = {
    label: string;
    /** The section has fields with server errors. */
    hasErrors: boolean;
};

type MemberStepNavProps = {
    steps: StepNavItem[];
    current: number;
    onSelect: (index: number) => void;
};

/**
 * Section selector of the form. Five equal columns so it never overflows: with room (container
 * ≥ 42rem) each shows its number and name, otherwise just the number, and the name of the current
 * one goes on its own line below.
 */
export function MemberStepNav({
    steps,
    current,
    onSelect,
}: MemberStepNavProps) {
    const currentStep = steps[current];

    return (
        <nav aria-label="Secciones de la ficha" className="@container min-w-0">
            <ol
                className="grid gap-2"
                style={{
                    gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))`,
                }}
            >
                {steps.map((step, index) => (
                    <li key={step.label} className="min-w-0">
                        <button
                            type="button"
                            onClick={() => onSelect(index)}
                            aria-current={
                                index === current ? 'step' : undefined
                            }
                            className={cn(
                                'relative flex w-full min-w-0 items-center justify-center gap-2 rounded-2xl px-2 py-2 text-sm font-semibold transition-colors focus-visible:ring-4 focus-visible:ring-ring/30 focus-visible:outline-none @2xl:justify-start @2xl:px-4',
                                index === current
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-foreground hover:bg-accent',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'grid size-7 shrink-0 place-items-center rounded-full text-sm font-bold',
                                    index === current
                                        ? 'bg-primary-foreground/20'
                                        : 'bg-background',
                                )}
                            >
                                {index + 1}
                            </span>
                            <span className="hidden min-w-0 truncate @2xl:inline">
                                {step.label}
                            </span>
                            <span className="sr-only @2xl:hidden">
                                {step.label}
                            </span>
                            {step.hasErrors && (
                                <>
                                    <span
                                        aria-hidden
                                        className="absolute top-1.5 right-2 size-2.5 rounded-full bg-destructive ring-2 ring-background"
                                    />
                                    <span className="sr-only">
                                        , con errores
                                    </span>
                                </>
                            )}
                        </button>
                    </li>
                ))}
            </ol>
            {currentStep && (
                <p className="mt-3 text-sm text-muted-foreground @2xl:hidden">
                    <span className="font-semibold text-foreground">
                        Paso {current + 1} de {steps.length}
                    </span>{' '}
                    · {currentStep.label}
                </p>
            )}
        </nav>
    );
}
