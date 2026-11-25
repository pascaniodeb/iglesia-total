import type { Granularity } from '@/features/accounting/types';
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

/** Mirrors App\Modules\Treasury\Domain\Receipts\ReceiptType. */
export type ReceiptType = 'income' | 'expense';

/** Mirrors App\Modules\Treasury\Domain\Receipts\PaymentMethod. */
export type PaymentMethod = 'cash' | 'deposit' | 'transfer';

/** A row of the receipt book (ReceiptListing::row()). */
export type ReceiptRow = {
    number: number;
    /** The number as the printed book shows it: "000001". */
    number_label: string;
    /** YYYY-MM-DD. */
    issued_on: string;
    type: ReceiptType;
    type_label: string;
    account_code: string | null;
    account_name: string | null;
    holder: string | null;
    counterparty: string | null;
    concept: string | null;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
    is_void: boolean;
};

export type ReceiptSortKey = 'number' | 'date' | 'ves' | 'usd' | 'cop';

export type ReceiptFilters = TableFilters<ReceiptSortKey> & {
    type: string;
    state: string;
    month: string;
};

/** What the issue form needs, only sent to who can issue (ReceiptController::index()). */
export type ReceiptFormData = {
    accounts: { income: AccountOption[]; expense: AccountOption[] };
    /** What can be spent today per currency code. */
    available: Record<'VES' | 'USD' | 'COP', string>;
    window: { min: string | null; max: string };
    payment_methods: { value: PaymentMethod; label: string }[];
};

/** A receipt in full (ReceiptController::show()). */
export type ReceiptDetail = {
    number: number;
    number_label: string;
    type: ReceiptType;
    type_label: string;
    issued_on: string;
    /** YYYY-MM: the month of its entry in the journal. */
    month: string;
    account_code: string | null;
    account_name: string | null;
    holder: string | null;
    counterparty: string | null;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
    concept: string | null;
    payment_method: PaymentMethod;
    payment_method_label: string;
    bank: string | null;
    payment_reference: string | null;
    payment_date: string | null;
    received_by_name: string | null;
    received_by_id_number: string | null;
    delivered_by_name: string | null;
    delivered_by_id_number: string | null;
    issued_by: string | null;
    created_at: string;
    void: {
        reason: string;
        voided_at: string;
        voided_by: string | null;
    } | null;
};

/** A row of the tithes book (TitheListing::row()). */
export type TitheRow = {
    id: string;
    /** YYYY-MM-DD. */
    tithe_date: string;
    member: MemberOption | null;
    notes: string | null;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
    is_deleted: boolean;
};

export type TitheSortKey = 'date' | 'ves' | 'usd' | 'cop';

export type TitheFilters = TableFilters<TitheSortKey> & { month: string };

type MoneyByCurrency = { ves: string; usd: string; cop: string };

/** The tithes report of a period (TitheReport::of()). */
export type TitheReport = {
    granularity: Granularity;
    period: string;
    label: string;
    short_label: string;
    months: string[];
    totals: MoneyByCurrency;
    members_who_tithed: number;
    members: ({ id: string; name: string; weeks: number } & MoneyByCurrency)[];
};

/** One member of the registry in the tithes analysis (TitheAnalysis::of()). */
export type TitheAnalysisRow = {
    id: string;
    name: string;
    totals: MoneyByCurrency;
    /** Weeks of the period in which they tithed. */
    weeks: number;
    /** Total over the weeks they tithed; null for a currency of a member who did not tithe. */
    average_given: Record<keyof MoneyByCurrency, string | null>;
    /** Total over the Sundays of the period. */
    average_period: MoneyByCurrency;
    /** The last tithe ever, not only of the period. */
    last_date: string | null;
    weeks_ago: number | null;
};

export type TitheAnalysis = {
    granularity: Granularity;
    period: string;
    label: string;
    short_label: string;
    sundays: number;
    rows: TitheAnalysisRow[];
};
