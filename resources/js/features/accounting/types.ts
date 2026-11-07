import type { TableFilters } from '@/types';

/** Mirrors App\Modules\Accounting\Domain\Enums\AccountType. */
export type AccountType = 'asset' | 'equity' | 'income' | 'expense';

/** A row of the chart of accounts (AccountingAccountListing::row()). */
export type AccountingAccountRow = {
    id: string;
    code: string;
    name: string;
    type: AccountType;
    type_label: string;
    is_active: boolean;
    is_system: boolean;
};

export type AccountingAccountSortKey = 'code' | 'name' | 'type';

export type AccountingAccountFilters =
    TableFilters<AccountingAccountSortKey> & {
        type: string;
    };

export type CurrencyCode = 'VES' | 'USD' | 'COP';

/** The cash statement of a month in one currency (MonthSummary). */
export type CashStatement = {
    opening: string;
    income: string;
    expense: string;
    capital: string;
    exchange: string;
    closing: string;
};

export type MonthSummary = {
    month: string;
    currencies: Record<CurrencyCode, CashStatement>;
    by_code: {
        code: string;
        name: string;
        type: string;
        entries: number;
        ves: string;
        usd: string;
        cop: string;
    }[];
};

/** A closed accounting month. */
export type AccountingPeriodRow = {
    id: string;
    month: string;
    closed_at: string;
    currencies: Record<CurrencyCode, CashStatement>;
    seal: { height: number; hash: string } | null;
};

export type NextPeriod = {
    month: string;
    summary: MonthSummary;
    blocker: string | null;
};
