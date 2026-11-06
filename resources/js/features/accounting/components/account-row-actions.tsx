import { router } from '@inertiajs/react';
import { Pencil, Power, PowerOff } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import type { RowAction } from '@/components/data-table/data-table-row-actions';
import { AccountFormDialog } from '@/features/accounting/components/account-form-dialog';
import type { AccountingAccountRow } from '@/features/accounting/types';
import { usePermissions } from '@/hooks/use-permissions';
import { toggle } from '@/routes/accounting/accounts';

/** Rename, deactivate or reactivate an account. System accounts have no actions. */
export function AccountRowActions({
    account,
}: {
    account: AccountingAccountRow;
}) {
    const { can } = usePermissions();
    const [isEditing, setIsEditing] = useState(false);

    if (!can('catalogs.manage') || account.is_system) {
        return null;
    }

    const actions: RowAction[] = [
        {
            label: 'Editar nombre',
            icon: Pencil,
            onSelect: () => setIsEditing(true),
        },
        {
            label: account.is_active ? 'Desactivar' : 'Reactivar',
            icon: account.is_active ? PowerOff : Power,
            destructive: account.is_active,
            onSelect: () =>
                router.patch(
                    toggle(account.id),
                    { is_active: !account.is_active },
                    { preserveScroll: true },
                ),
        },
    ];

    return (
        <>
            <DataTableRowActions
                label={`${account.code} ${account.name}`}
                actions={actions}
            />
            <AccountFormDialog
                key={account.id}
                account={account}
                open={isEditing}
                onOpenChange={setIsEditing}
            />
        </>
    );
}
