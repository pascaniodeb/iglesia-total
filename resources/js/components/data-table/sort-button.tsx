import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { SortDirection } from '@/types';

type SortButtonProps = {
    label: string;
    state: SortDirection | null;
    align?: 'start' | 'end';
    onClick: () => void;
};

const STATE_LABEL = {
    asc: '(orden ascendente)',
    desc: '(orden descendente)',
    none: '(ordenar)',
} as const;

export function SortButton({ label, state, align, onClick }: SortButtonProps) {
    const Icon =
        state === 'asc' ? ArrowUp : state === 'desc' ? ArrowDown : ArrowUpDown;

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                '-mx-2 inline-flex items-center gap-1.5 rounded-full px-2 py-1 uppercase transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-4 focus-visible:ring-ring/30 focus-visible:outline-none',
                state !== null && 'text-foreground',
                align === 'end' && 'flex-row-reverse',
            )}
        >
            {label}
            <Icon aria-hidden className="size-3.5" />
            <span className="sr-only">{STATE_LABEL[state ?? 'none']}</span>
        </button>
    );
}
