import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PanelProps = {
    title: string;
    description?: ReactNode;
    /** Something on the right of the title (a link, a legend). */
    aside?: ReactNode;
    className?: string;
    children: ReactNode;
};

/** One tile of the dashboard: a title, what it means, and its content. */
export function Panel({
    title,
    description,
    aside,
    className,
    children,
}: PanelProps) {
    return (
        <section
            aria-label={title}
            className={cn(
                '@container flex min-w-0 flex-col gap-5 rounded-2xl border bg-card p-5 text-card-foreground shadow-xs',
                className,
            )}
        >
            <header className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 className="text-base font-semibold">{title}</h2>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {aside && <div className="shrink-0">{aside}</div>}
            </header>
            {children}
        </section>
    );
}
