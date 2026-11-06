import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    title: string;
    description?: ReactNode;
    icon?: LucideIcon;
    /** Optional call to action (a button or link). */
    action?: ReactNode;
    className?: string;
};

export function EmptyState({
    title,
    description,
    icon: Icon = Inbox,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-4 px-6 py-16 text-center',
                className,
            )}
        >
            <span className="flex size-14 items-center justify-center rounded-full bg-muted">
                <Icon aria-hidden className="size-6 text-muted-foreground" />
            </span>
            <div className="flex flex-col gap-1">
                <p className="heading-title">{title}</p>
                {description && (
                    <p className="text-muted-foreground">{description}</p>
                )}
            </div>
            {action}
        </div>
    );
}
