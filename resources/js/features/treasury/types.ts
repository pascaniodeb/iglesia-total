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

/** Mirrors App\Modules\Treasury\Domain\Enums\RateOrigin. */
export type RateOrigin = 'market' | 'manual' | 'legacy';

/** The rates of a Venezuelan day, as exact decimal text. */
export type ExchangeRateRow = {
    id: string;
    /** YYYY-MM-DD. */
    date: string;
    /** Bolívares per dollar (4 decimals). */
    usd_rate: string;
    /** Bolívares per euro (4 decimals). */
    eur_rate: string | null;
    /** Pesos per dollar, TRM (4 decimals). */
    trm_cop_usd: string | null;
    /** Pesos per bolívar (6 decimals). */
    cop_rate: string;
    origin: RateOrigin;
    origin_label: string;
};

export type ExchangeRateFilters = TableFilters<'date'>;

/** A member as the offerings screens show it (MemberRef::toOption()). */
export type MemberOption = {
    id: string;
    name: string;
    document: string | null;
};

/** A row of the offerings registry (OfferingListing::row()). */
export type OfferingRow = {
    id: string;
    /** YYYY-MM-DD. */
    offering_date: string;
    /** ISO week, e.g. "2026-W45". */
    week_key: string;
    member: MemberOption | null;
    category_id: string;
    category: string;
    concept: string | null;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
    is_deleted: boolean;
};

export type OfferingSortKey = 'date' | 'ves' | 'usd' | 'cop';

export type OfferingFilters = TableFilters<OfferingSortKey> & {
    month: string;
    category: string;
};

/** Count and totals per currency of what the filters select. */
export type OfferingTotals = {
    count: number;
    ves: string;
    usd: string;
    cop: string;
};

export type CategoryOption = {
    value: string;
    label: string;
    is_active: boolean;
};
