import type { DataTableColumn } from '@/components/data-table/types';
import { StatusBadge } from '@/components/status-badge';
import type {
    AccountingAccountRow,
    AccountingAccountSortKey,
} from '@/features/accounting/types';

/** Columns of the chart of accounts: the code and name are what the reports print. */
export const accountColumns: DataTableColumn<
    AccountingAccountRow,
    AccountingAccountSortKey
>[] = [
    {
        key: 'code',
        header: 'Código',
        sortKey: 'code',
        cell: (account) => (
            <span className="font-mono font-semibold whitespace-nowrap">
                {account.code}
            </span>
        ),
    },
    {
        key: 'name',
        header: 'Nombre',
        sortKey: 'name',
        mobile: 'primary',
        cell: (account) => (
            <span className="flex flex-col">
                <span className="font-semibold break-words">
                    {account.name}
                </span>
                {account.is_system && (
                    <span className="text-xs text-muted-foreground">
                        Cuenta del sistema
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'type',
        header: 'Tipo',
        sortKey: 'type',
        cell: (account) => account.type_label,
    },
    {
        key: 'status',
        header: 'Estado',
        mobile: 'badge',
        cell: (account) => (
            <StatusBadge
                active={account.is_active}
                activeLabel="Activa"
                inactiveLabel="Inactiva"
            />
        ),
    },
];
