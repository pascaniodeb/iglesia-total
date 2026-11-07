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
    /** Its week is closed: it can no longer change. */
    is_locked: boolean;
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

/** What an offerings workbook would write (OfferingImportPreview::toArray()). */
export type OfferingImportPreview = {
    rows: {
        line: number;
        offering_date: string;
        member_name: string | null;
        category_name: string;
        amount_ves: string;
        amount_usd: string;
        amount_cop: string;
    }[];
    rejected: { line: number; reason: string }[];
    duplicate_lines: number[];
    totals: { ves: string; usd: string; cop: string };
    file_hash: string;
    can_import: boolean;
};

/** A closed week (WeeklyClosureController::index()). */
export type WeeklyClosureRow = {
    id: string;
    week_key: string;
    week_start: string;
    week_end: string;
    offerings_count: number;
    total_ves: string;
    total_usd: string;
    total_cop: string;
    closed_at: string;
    /** The block that seals it, or null while the seal is pending. */
    seal: { height: number; hash: string } | null;
};

/** The next week to close, with what it holds now and why it cannot close yet (if so). */
export type NextWeek = {
    week_key: string;
    week_start: string;
    week_end: string;
    months: string[];
    snapshot: { count: number; ves: string; usd: string; cop: string };
    blocker: string | null;
};

/** A month whose offerings were closed and posted to the journal. */
export type MonthlyOfferingClosureRow = {
    id: string;
    month: string;
    offerings_count: number;
    total_ves: string;
    total_usd: string;
    total_cop: string;
    journal_entry_id: string | null;
    closed_at: string;
};

/** The next month to close: its weeks, its offerings by category and what blocks it. */
export type NextMonth = {
    month: string;
    last_day: string;
    weeks: {
        week_key: string;
        week_start: string;
        week_end: string;
        is_closed: boolean;
    }[];
    snapshot: {
        count: number;
        ves: string;
        usd: string;
        cop: string;
        categories: {
            category_id: string;
            category: string;
            account_code: string | null;
            account_name: string | null;
            count: number;
            ves: string;
            usd: string;
            cop: string;
        }[];
    };
    blocker: string | null;
};
