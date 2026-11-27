import { cn } from '@/lib/utils';

/** A label with a full hash under it, in mono, breaking anywhere: a hash has no spaces to wrap at. */
export function HashField({
    label,
    value,
    className,
}: {
    label: string;
    value: string | null;
    className?: string;
}) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-1', className)}>
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="rounded-lg bg-muted/60 px-3 py-2 font-mono text-xs break-all select-all">
                {value ?? '—'}
            </dd>
        </div>
    );
}
