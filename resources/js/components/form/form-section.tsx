import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type FormSectionProps = {
    /** Anchor of the section: lets you link to it (`/members/1#contact`). */
    id?: string;
    title: string;
    description?: string;
    /**
     * `columns`: two even columns. `rows`: each row is built with <Cell> of a third, a half, two
     * thirds or the full width (short selects three per row, names two per row, wider address).
     */
    layout?: 'columns' | 'rows';
    children: ReactNode;
};

/**
 * Block of a long form: title, help text and fields. Width is measured by the container (like
 * tables): on small screens everything goes in one column.
 */
export function FormSection({
    id,
    title,
    description,
    layout = 'columns',
    children,
}: FormSectionProps) {
    return (
        <section
            id={id}
            className="@container flex scroll-mt-24 flex-col gap-6 rounded-3xl border-2 border-border bg-card p-6 md:p-8"
        >
            <header className="flex flex-col gap-1">
                <h2 className="text-2xl font-black tracking-tight">{title}</h2>
                {description && (
                    <p className="text-muted-foreground">{description}</p>
                )}
            </header>
            <div
                className={cn(
                    'grid grid-cols-1 gap-6',
                    layout === 'rows'
                        ? '@md:grid-cols-2 @2xl:grid-cols-6'
                        : '@2xl:grid-cols-2',
                )}
            >
                {children}
            </div>
        </section>
    );
}

/** Width of a cell in a six-part row (literal classes so Tailwind can see them). */
const SPANS = {
    third: '@2xl:col-span-2',
    half: '@2xl:col-span-3',
    twoThirds: '@md:col-span-2 @2xl:col-span-4',
    full: '@md:col-span-2 @2xl:col-span-6',
} as const;

export type CellSpan = keyof typeof SPANS;

/** Cell of a `rows` section: a third, a half, two thirds or the full width. */
export function Cell({
    span,
    children,
}: {
    span: CellSpan;
    children: ReactNode;
}) {
    return <div className={cn('min-w-0', SPANS[span])}>{children}</div>;
}
