import { X } from 'lucide-react';
import type { DataTableFacet } from '@/components/data-table/types';
import { ALL } from '@/hooks/use-table-query';
import type { FilterDefaults } from '@/hooks/use-table-query';

type ActiveFilter = { key: string; label: string; onRemove: () => void };

type DataTableActiveFiltersProps = {
    search: string;
    facets: DataTableFacet[];
    values: Record<string, string>;
    /** Filters whose default is not "all": at their default they show no chip. */
    defaults?: FilterDefaults;
    onClearSearch: () => void;
    onClearFacet: (key: string) => void;
    onReset: () => void;
};

/** Filtros aplicados (distintos de su defecto) como chips removibles, con "Limpiar todo". */
export function DataTableActiveFilters({
    search,
    facets,
    values,
    defaults = {},
    onClearSearch,
    onClearFacet,
    onReset,
}: DataTableActiveFiltersProps) {
    const valueLabel = (facet: DataTableFacet, value: string) =>
        value === ALL
            ? facet.allLabel
            : (facet.options.find((option) => option.value === value)?.label ??
              value);

    const active: ActiveFilter[] = [
        ...(search !== ''
            ? [{ key: 'search', label: `“${search}”`, onRemove: onClearSearch }]
            : []),
        ...facets
            .filter(
                (facet) =>
                    (values[facet.key] ?? ALL) !== (defaults[facet.key] ?? ALL),
            )
            .map((facet) => ({
                key: facet.key,
                label: `${facet.label}: ${valueLabel(facet, values[facet.key] ?? ALL)}`,
                onRemove: () => onClearFacet(facet.key),
            })),
    ];

    if (active.length === 0) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap items-center gap-2"
            aria-label="Filtros aplicados"
        >
            {active.map((filter) => (
                <span
                    key={filter.key}
                    className="inline-flex max-w-full items-center gap-1 rounded-full bg-primary/10 py-1 pr-1 pl-3 text-sm font-semibold text-primary"
                >
                    <span className="truncate">{filter.label}</span>
                    <button
                        type="button"
                        aria-label={`Quitar filtro ${filter.label}`}
                        onClick={filter.onRemove}
                        className="flex size-6 shrink-0 items-center justify-center rounded-full hover:bg-primary/15 focus-visible:ring-4 focus-visible:ring-ring/30 focus-visible:outline-none"
                    >
                        <X aria-hidden className="size-3.5" />
                    </button>
                </span>
            ))}
            {active.length > 1 && (
                <button
                    type="button"
                    onClick={onReset}
                    className="rounded-full px-3 py-1 text-sm font-semibold text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Limpiar todo
                </button>
            )}
        </div>
    );
}
