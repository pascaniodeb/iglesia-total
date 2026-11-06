import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { DataTableColumn } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { OfferingCategoryFormDialog } from '@/features/treasury/components/offering-category-form-dialog';
import { OfferingCategoryRowActions } from '@/features/treasury/components/offering-category-row-actions';
import type {
    AccountOption,
    OfferingCategoryFilters,
    OfferingCategoryRow,
} from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { index as categoriesIndex } from '@/routes/treasury/offering-categories';
import type { Paginated } from '@/types';

type OfferingCategoriesIndexProps = {
    categories: Paginated<OfferingCategoryRow>;
    filters: OfferingCategoryFilters;
    accounts: AccountOption[];
};

const columns: DataTableColumn<OfferingCategoryRow, 'name'>[] = [
    {
        key: 'name',
        header: 'Categoría',
        sortKey: 'name',
        mobile: 'primary',
        cell: (category) => (
            <span className="font-semibold break-words">{category.name}</span>
        ),
    },
    {
        key: 'account',
        header: 'Cuenta de ingreso',
        cell: (category) => (
            <span className="break-words">
                <span className="font-mono font-semibold">
                    {category.account_code}
                </span>{' '}
                {category.account_name}
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Estado',
        mobile: 'badge',
        cell: (category) => (
            <StatusBadge
                active={category.is_active}
                activeLabel="Activa"
                inactiveLabel="Inactiva"
            />
        ),
    },
];

export default function OfferingCategoriesIndex({
    categories,
    filters,
    accounts,
}: OfferingCategoriesIndexProps) {
    const { can } = usePermissions();
    const [isCreating, setIsCreating] = useState(false);

    return (
        <>
            <Head title="Categorías de ofrenda" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Tesorería"
                    title="Categorías de ofrenda"
                    description="A qué cuenta de ingreso va cada tipo de ofrenda cuando se cierra el mes."
                    actions={
                        can('catalogs.manage') && (
                            <Button
                                className="shrink-0"
                                onClick={() => setIsCreating(true)}
                            >
                                <Plus aria-hidden />
                                Nueva categoría
                            </Button>
                        )
                    }
                />
                <ServerDataTable
                    url={categoriesIndex().url}
                    prop="categories"
                    data={categories}
                    filters={filters}
                    columns={columns}
                    rowKey={(category) => category.id}
                    rowActions={(category) => (
                        <OfferingCategoryRowActions
                            category={category}
                            accounts={accounts}
                        />
                    )}
                    searchLabel="Buscar categoría"
                    statusLabel="Estado"
                    defaultStatus="active"
                    inactiveLabels={{
                        option: 'Inactivas',
                        all: 'Activas e inactivas',
                    }}
                    empty={{
                        title: 'No hay categorías',
                        description:
                            'Importe las del sistema anterior o cree la primera.',
                    }}
                />
            </div>
            <OfferingCategoryFormDialog
                accounts={accounts}
                open={isCreating}
                onOpenChange={setIsCreating}
            />
        </>
    );
}

OfferingCategoriesIndex.layout = {
    breadcrumbs: [{ title: 'Categorías de ofrenda', href: categoriesIndex() }],
};
