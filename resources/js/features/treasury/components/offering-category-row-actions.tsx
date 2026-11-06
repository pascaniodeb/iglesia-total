import { router } from '@inertiajs/react';
import { Pencil, Power, PowerOff } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import { OfferingCategoryFormDialog } from '@/features/treasury/components/offering-category-form-dialog';
import type {
    AccountOption,
    OfferingCategoryRow,
} from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { toggle } from '@/routes/treasury/offering-categories';

export function OfferingCategoryRowActions({
    category,
    accounts,
}: {
    category: OfferingCategoryRow;
    accounts: AccountOption[];
}) {
    const { can } = usePermissions();
    const [isEditing, setIsEditing] = useState(false);

    if (!can('catalogs.manage')) {
        return null;
    }

    return (
        <>
            <DataTableRowActions
                label={category.name}
                actions={[
                    {
                        label: 'Editar',
                        icon: Pencil,
                        onSelect: () => setIsEditing(true),
                    },
                    {
                        label: category.is_active ? 'Desactivar' : 'Reactivar',
                        icon: category.is_active ? PowerOff : Power,
                        destructive: category.is_active,
                        onSelect: () =>
                            router.patch(
                                toggle(category.id),
                                { is_active: !category.is_active },
                                { preserveScroll: true },
                            ),
                    },
                ]}
            />
            <OfferingCategoryFormDialog
                key={category.id}
                category={category}
                accounts={accounts}
                open={isEditing}
                onOpenChange={setIsEditing}
            />
        </>
    );
}
