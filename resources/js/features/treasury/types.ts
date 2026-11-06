import type { TableFilters } from '@/types';

/** An income account offered for a category (AccountRef::toOption()). */
export type AccountOption = { id: string; code: string; name: string };

/** A row of the offering categories listing. */
export type OfferingCategoryRow = {
    id: string;
    name: string;
    account_id: string;
    account_code: string | null;
    account_name: string | null;
    is_active: boolean;
};

export type OfferingCategoryFilters = TableFilters<'name'>;
