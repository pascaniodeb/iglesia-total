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
