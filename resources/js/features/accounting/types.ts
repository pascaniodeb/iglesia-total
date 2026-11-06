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
