import type { ReactNode } from 'react';

type PageHeaderProps = {
    eyebrow?: string;
    /** Short datum next to the eyebrow (e.g. the total of a listing). */
    meta?: ReactNode;
    title: string;
    description?: ReactNode;
    /** Page actions, from secondary to primary (the primary one goes on the right). */
    actions?: ReactNode;
};

export function PageHeader({
    eyebrow,
    meta,
    title,
    description,
    actions,
}: PageHeaderProps) {
    return (
        <header className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                {(eyebrow || meta) && (
                    <p className="flex flex-wrap items-center gap-x-2 eyebrow text-muted-foreground">
                        {eyebrow}
                        {eyebrow && meta && <span aria-hidden>·</span>}
                        {meta && (
                            <span className="text-foreground">{meta}</span>
                        )}
                    </p>
                )}
                <h1 className="heading-page">
                    {title}
                    <span className="text-primary">.</span>
                </h1>
                {description && (
                    <p className="text-lg text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                // One row on desktop, aligned with the title; stacked on phones.
                <div className="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-center">
                    {actions}
                </div>
            )}
        </header>
    );
}
