import type { ReactNode } from 'react';
import { DataTableActiveFilters } from '@/components/data-table/data-table-active-filters';
import { DataTableDesktop } from '@/components/data-table/data-table-desktop';
import { DataTableEmpty } from '@/components/data-table/data-table-empty';
import { DataTableSearchPrompt } from '@/components/data-table/data-table-search-prompt';
import { DataTableFacet } from '@/components/data-table/data-table-facet';
import { DataTableFiltersSheet } from '@/components/data-table/data-table-filters-sheet';
import { DataTableMobileList } from '@/components/data-table/data-table-mobile-list';
import { DataTablePagination } from '@/components/data-table/data-table-pagination';
import { DataTableSearch } from '@/components/data-table/data-table-search';
import type {
    DataTableColumn,
    DataTableFacet as Facet,
} from '@/components/data-table/types';
import { ALL, useTableQuery } from '@/hooks/use-table-query';
import type { FilterDefaults } from '@/hooks/use-table-query';
import { cn } from '@/lib/utils';
import type { Paginated, TableFilters } from '@/types';

/** More filters than this do not fit in one row: they are grouped into the filters sheet. */
const MAX_INLINE_FACETS = 3;

/** Label of a listing's inactive state (e.g. "In trash" for members). */
type InactiveLabels = { option: string; all: string };

const INACTIVE: InactiveLabels = {
    option: 'Inactivos',
    all: 'Activos e inactivos',
};

/** Status filter that TableQuery applies to every listing (soft delete). */
function statusFacet(inactive: InactiveLabels, label: string): Facet {
    return {
        key: 'status',
        label,
        allLabel: inactive.all,
        options: [
            { value: 'active', label: 'Activos' },
            { value: 'inactive', label: inactive.option },
        ],
    };
}

type ServerDataTableProps<TRow, TFilters extends TableFilters> = {
    /** Listing URL (Wayfinder) and name of the paginated prop, for partial reloads. */
    url: string;
    prop: string;
    /** Other props that depend on the filters (e.g. options of a chained facet). */
    reload?: string[];
    data: Paginated<TRow>;
    filters: TFilters;
    /** Table columns; without them the page renders the rows with `renderRows`. */
    columns?: DataTableColumn<TRow>[];
    /**
     * Renders the rows its own way inside the same scrolling body: search, filters, chips,
     * loading and empty states and pagination stay those of the listing.
     */
    renderRows?: (rows: TRow[]) => ReactNode;
    /**
     * A panel next to the body that does NOT scroll with it: on large screens it sits on the right with
     * its own scroll, on small ones it goes above the body.
     */
    aside?: ReactNode;
    rowKey: (row: TRow) => string;
    searchLabel: string;
    empty: { title: string; description?: string };
    /** Filters in addition to the status. */
    facets?: Facet[];
    rowActions?: (row: TRow) => ReactNode;
    /** Page actions next to the filters (e.g. "New member"). */
    actions?: ReactNode;
    /**
     * Search tool: with fewer than `minLength` characters nothing is listed and the user is
     * invited to search (instead of showing "No results").
     */
    searchPrompt?: { minLength: number; title: string; description: string };
    /** No soft delete: the status filter is not offered. */
    withStatus?: boolean;
    /** Name of the inactive state in the status filter ("Inactivos" by default). */
    inactiveLabels?: InactiveLabels;
    /** Name of the status filter ("Estatus" by default). */
    statusLabel?: string;
    /** Status when none is chosen: the server's (e.g. members: only the active ones). */
    defaultStatus?: 'all' | 'active';
    /**
     * With other blocks on the page the listing does not fit the screen height:
     * the whole page scrolls.
     */
    fitViewport?: boolean;
};

/**
 * Full server-paginated listing: search, chip filters, sorting, loading and empty states,
 * pagination and mobile cards. Pages only declare columns and filters.
 */
export function ServerDataTable<TRow, TFilters extends TableFilters>({
    url,
    prop,
    reload = [],
    data,
    filters,
    columns = [],
    renderRows,
    aside,
    rowKey,
    searchLabel,
    empty,
    facets = [],
    rowActions,
    actions,
    searchPrompt,
    withStatus = true,
    inactiveLabels = INACTIVE,
    statusLabel = 'Estatus',
    defaultStatus = 'all',
    fitViewport = true,
}: ServerDataTableProps<TRow, TFilters>) {
    const defaults: FilterDefaults =
        withStatus && defaultStatus !== ALL ? { status: defaultStatus } : {};
    const query = useTableQuery(url, filters, [prop, ...reload], defaults);
    const allFacets = withStatus
        ? [...facets, statusFacet(inactiveLabels, statusLabel)]
        : facets;
    const values = Object.fromEntries(
        allFacets.map((facet) => [facet.key, facetValue(filters, facet.key)]),
    );
    const facetKeys = allFacets.map((facet) => facet.key);
    const inlineFacets = allFacets.length <= MAX_INLINE_FACETS;
    const activeFacets = Object.entries(values).filter(
        ([key, value]) => value !== (defaults[key] ?? ALL),
    ).length;
    const isFiltered = filters.search !== '' || activeFacets > 0;
    const { data: rows, ...meta } = data;
    const view = { rows, columns, rowKey, rowActions };

    return (
        // data-fit-viewport: on tablet and desktop the listing is as tall as the screen and only the
        // table scrolls (app.css); on phones the natural page scroll is kept.
        <div
            data-fit-viewport={fitViewport ? '' : undefined}
            className="flex flex-col gap-4"
        >
            {/* Mobile (or more than MAX_INLINE_FACETS filters): search + filters button.
                md–xl: search on its own row and filters in equal parts below. xl: one row. */}
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex min-w-0 basis-full items-center gap-3 xl:grow xl:basis-0">
                    <DataTableSearch
                        value={query.search}
                        label={searchLabel}
                        onChange={query.setSearch}
                        compact={allFacets.length >= 3 && inlineFacets}
                    />
                    <div className={cn(inlineFacets && 'md:hidden')}>
                        <DataTableFiltersSheet
                            facets={allFacets}
                            values={values}
                            activeCount={activeFacets}
                            onChange={query.setFilter}
                            onReset={() => query.reset(facetKeys)}
                        />
                    </div>
                </div>
                {inlineFacets &&
                    allFacets.map((facet) => (
                        <div
                            key={facet.key}
                            className="hidden min-w-0 flex-1 md:block xl:w-64 xl:flex-none"
                        >
                            <DataTableFacet
                                facet={facet}
                                value={values[facet.key] ?? ALL}
                                onChange={(value) =>
                                    query.setFilter(facet.key, value)
                                }
                            />
                        </div>
                    ))}
                {actions}
            </div>

            <DataTableActiveFilters
                search={filters.search}
                facets={allFacets}
                values={values}
                defaults={defaults}
                onClearSearch={() => query.setSearch('')}
                onClearFacet={(key) =>
                    query.setFilter(key, defaults[key] ?? ALL)
                }
                onReset={() => query.reset(facetKeys)}
            />

            <div
                className={cn(
                    aside
                        ? 'flex flex-col gap-4 lg:flex-row-reverse'
                        : 'contents',
                    aside && fitViewport && 'md:min-h-0 md:flex-1',
                )}
            >
                {aside}
                <section
                    data-fit-scroll
                    aria-busy={query.isLoading}
                    // overflow-clip, not hidden: rounds the corners without becoming a scroll container, so sticky
                    // table headers stick to the page scroll.
                    className="relative overflow-clip rounded-3xl border-2 border-border bg-card"
                >
                    <div
                        aria-hidden
                        className={cn(
                            'absolute inset-x-0 top-0 h-1 origin-left bg-primary transition-opacity',
                            query.isLoading
                                ? 'animate-pulse opacity-100'
                                : 'opacity-0',
                        )}
                    />
                    <div
                        data-fit-body
                        className={cn(
                            '@container transition-opacity',
                            query.isLoading && 'opacity-60',
                        )}
                    >
                        {searchPrompt &&
                        filters.search.length < searchPrompt.minLength ? (
                            <DataTableSearchPrompt {...searchPrompt} />
                        ) : rows.length === 0 ? (
                            <DataTableEmpty
                                filtered={isFiltered}
                                title={empty.title}
                                description={empty.description}
                                onReset={() => query.reset(facetKeys)}
                            />
                        ) : renderRows ? (
                            renderRows(rows)
                        ) : (
                            <>
                                {/* Table or cards depending on the real available width (not the
                                    screen's): with the menu open, a laptop can have
                                    less room than a tablet. */}
                                <div
                                    data-fit-table
                                    className="hidden @3xl:block"
                                >
                                    <DataTableDesktop
                                        {...view}
                                        sort={filters.sort}
                                        direction={filters.direction}
                                        onSort={query.sort}
                                    />
                                </div>
                                <div className="@3xl:hidden">
                                    <DataTableMobileList {...view} />
                                </div>
                            </>
                        )}
                    </div>
                </section>
            </div>

            {meta.total > 0 && (
                <DataTablePagination
                    meta={meta}
                    onPage={query.page}
                    onPerPage={query.perPage}
                />
            )}
        </div>
    );
}

/** Valor de un filtro en los filtros normalizados del servidor; "all" si no aplica. */
function facetValue(filters: TableFilters, key: string): string {
    const value: unknown = (filters as Record<string, unknown>)[key];

    return typeof value === 'string' && value !== '' ? value : ALL;
}
