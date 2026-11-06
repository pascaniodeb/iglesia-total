import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { TableFilters } from '@/types';

const SEARCH_DEBOUNCE_MS = 300;
export const ALL = 'all';

type Changes = Record<string, string | number | undefined>;

/** Default value of a filter whose default is not "all" (e.g. members: status "active"). */
export type FilterDefaults = Record<string, string>;

/**
 * Strips default values from the URL: short, shareable links. If a filter's default is not
 * "all", "all" is kept in the URL (otherwise the server would apply the default).
 */
function withoutDefaults(params: Changes, defaults: FilterDefaults): Changes {
    return Object.fromEntries(
        Object.entries(params).filter(
            ([key, value]) =>
                value !== undefined &&
                value !== '' &&
                value !== (defaults[key] ?? ALL),
        ),
    );
}

/**
 * Listing state in the URL: the server filters, sorts and paginates. Only reloads the listing's
 * props, keeps the scroll position and exposes whether a request is in flight.
 */
export function useTableQuery<TFilters extends TableFilters>(
    url: string,
    filters: TFilters,
    only: string[],
    defaults: FilterDefaults = {},
) {
    const [search, setSearch] = useState(filters.search);
    const [isLoading, setIsLoading] = useState(false);
    const searchTimer = useRef<number | undefined>(undefined);

    const visit = (changes: Changes) => {
        window.clearTimeout(searchTimer.current);

        // Any filter change goes back to the first page.
        router.get(
            url,
            withoutDefaults(
                { ...filters, page: undefined, ...changes },
                defaults,
            ),
            {
                only: [...only, 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setIsLoading(true),
                onFinish: () => setIsLoading(false),
            },
        );
    };

    return {
        search,
        isLoading,
        setSearch: (value: string) => {
            setSearch(value);
            window.clearTimeout(searchTimer.current);
            searchTimer.current = window.setTimeout(
                () => visit({ search: value }),
                SEARCH_DEBOUNCE_MS,
            );
        },
        setFilter: (key: string, value: string) => visit({ [key]: value }),
        sort: (sort: string) =>
            visit({
                sort,
                direction:
                    filters.sort === sort && filters.direction === 'asc'
                        ? 'desc'
                        : 'asc',
            }),
        page: (page: number) => visit({ page }),
        perPage: (perPage: number) => visit({ per_page: perPage }),
        /** Resets search and filters to their defaults; keeps sort order and page size. */
        reset: (facetKeys: string[]) => {
            setSearch('');
            visit({
                search: '',
                ...Object.fromEntries(
                    facetKeys.map((key) => [key, defaults[key] ?? ALL]),
                ),
            });
        },
    };
}
