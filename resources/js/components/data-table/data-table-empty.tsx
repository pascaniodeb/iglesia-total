import { SearchX, Inbox } from 'lucide-react';
import { Button } from '@/components/ui/button';

type DataTableEmptyProps = {
    /** A search or filters are active: the empty state is due to the filters, not missing data. */
    filtered: boolean;
    title: string;
    description?: string;
    onReset: () => void;
};

export function DataTableEmpty({
    filtered,
    title,
    description,
    onReset,
}: DataTableEmptyProps) {
    const Icon = filtered ? SearchX : Inbox;

    return (
        <div className="flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-muted">
                <Icon aria-hidden className="size-6 text-muted-foreground" />
            </span>
            <div className="flex flex-col gap-1">
                <p className="heading-title">
                    {filtered ? 'Sin resultados' : title}
                </p>
                <p className="text-muted-foreground">
                    {filtered
                        ? 'Ningún registro coincide con la búsqueda y los filtros.'
                        : description}
                </p>
            </div>
            {filtered && (
                <Button variant="outline" size="sm" onClick={onReset}>
                    Limpiar filtros
                </Button>
            )}
        </div>
    );
}
