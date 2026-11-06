import { Search, X } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { useIsMobile } from '@/hooks/use-mobile';

type DataTableSearchProps = {
    value: string;
    label: string;
    onChange: (value: string) => void;
    /** Little room (many filters beside it): short placeholder, as on mobile. */
    compact?: boolean;
};

export function DataTableSearch({
    value,
    label,
    onChange,
    compact = false,
}: DataTableSearchProps) {
    // If the full text does not fit it is shortened; the accessible label stays the full one.
    const placeholder = useIsMobile() || compact ? 'Buscar' : label;

    return (
        <div className="relative min-w-0 flex-1">
            <Search
                aria-hidden
                className="pointer-events-none absolute top-1/2 left-5 size-5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                type="search"
                aria-label={label}
                placeholder={placeholder}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="pr-14 pl-13 [&::-webkit-search-cancel-button]:hidden"
            />
            {value !== '' && (
                <button
                    type="button"
                    aria-label="Borrar búsqueda"
                    onClick={() => onChange('')}
                    className="absolute top-1/2 right-3 flex size-9 -translate-y-1/2 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-4 focus-visible:ring-ring/30 focus-visible:outline-none"
                >
                    <X aria-hidden className="size-5" />
                </button>
            )}
        </div>
    );
}
