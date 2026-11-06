export type PaginationLink = {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
};

/** Shape of Laravel's LengthAwarePaginator once serialized. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

/** Values of App\Http\Tables\TableQuery::STATUSES. */
export const TABLE_STATUSES = ['all', 'active', 'inactive'] as const;
export type TableStatus = (typeof TABLE_STATUSES)[number];

/** Values of App\Http\Tables\TableQuery::PER_PAGE_OPTIONS. */
export const PER_PAGE_OPTIONS = [15, 25, 50] as const;

export type SortDirection = 'asc' | 'desc';

/** Normalized filters the server echoes back (App\Http\Tables\TableQuery::filters()). */
export type TableFilters<TSort extends string = string> = {
    search: string;
    status: TableStatus;
    sort: TSort;
    direction: SortDirection;
    per_page: number;
};
