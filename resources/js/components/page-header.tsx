import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PageHeaderProps = {
    eyebrow?: string;
    /** Short datum next to the eyebrow (e.g. the total of a listing). */
    meta?: ReactNode;
    title: string;
    description?: ReactNode;
    /** Page actions, from secondary to primary (the primary one goes on the right). */
    actions?: ReactNode;
    /**
     * Shorter header for pages that need the height (forms): a smaller title, the description
     * only on phones, and everything on one row from tablet up.
     */
    compact?: boolean;
};

export function PageHeader({
    eyebrow,
    meta,
    title,
    description,
    actions,
    compact = false,
}: PageHeaderProps) {
    return (
        <header
            className={cn(
                'flex flex-col md:flex-row md:justify-between',
                compact ? 'gap-3 md:items-center' : 'gap-6 md:items-end',
            )}
        >
            <div
                className={cn(
                    'flex min-w-0 flex-1 flex-col',
                    compact ? 'gap-1.5' : 'gap-3',
                )}
            >
                {(eyebrow || meta) && (
                    <p className="flex flex-wrap items-center gap-x-2 eyebrow text-muted-foreground">
                        {eyebrow}
                        {eyebrow && meta && <span aria-hidden>·</span>}
                        {meta && (
                            <span className="text-foreground">{meta}</span>
                        )}
                    </p>
                )}
                <h1 className={compact ? 'heading-title' : 'heading-page'}>
                    {title}
                    <span className="text-primary">.</span>
                </h1>
                {description && (
                    <p
                        className={cn(
                            'text-muted-foreground',
                            compact ? 'text-base md:hidden' : 'text-lg',
                        )}
                    >
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
