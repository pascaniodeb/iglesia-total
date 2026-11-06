import type { ReactNode } from 'react';

/**
 * Role of the column under the `@3xl` container width, where each row is shown as a card (zero
 * horizontal scroll):
 * - primary: title of the card;
 * - badge: on the right of the title (status, tags);
 * - detail: label/value pair in the body (the default);
 * - hidden: not shown on mobile.
 */
export type MobileRole = 'primary' | 'badge' | 'detail' | 'hidden';

export type DataTableColumn<TRow, TSort extends string = string> = {
    key: string;
    header: string;
    cell: (row: TRow) => ReactNode;
    /** Sort key the server accepts; without it the column is not sortable. */
    sortKey?: TSort;
    align?: 'start' | 'end';
    mobile?: MobileRole;
    /**
     * Priority in the table: secondary columns appear when the table has room (≥ 64rem) and
     * tertiary ones with more (≥ 72rem). It is the width of the table that is measured, not the
     * screen's: it adapts by itself to the sidebar being open or closed. Cards always show all.
     */
    priority?: 'secondary' | 'tertiary';
};

export type FacetOption = { value: string; label: string };

/** Filter by fixed values. "all" means no filter (Radix Select does not accept ""). */
export type DataTableFacet = {
    key: string;
    label: string;
    allLabel: string;
    options: FacetOption[];
};
