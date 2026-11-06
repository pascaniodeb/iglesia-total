import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { FacetOption } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { accountColumns } from '@/features/accounting/components/account-columns';
import { AccountFormDialog } from '@/features/accounting/components/account-form-dialog';
import { AccountRowActions } from '@/features/accounting/components/account-row-actions';
import type {
    AccountingAccountFilters,
    AccountingAccountRow,
} from '@/features/accounting/types';
import { usePermissions } from '@/hooks/use-permissions';
import { index as accountsIndex } from '@/routes/accounting/accounts';
import type { Paginated } from '@/types';

type AccountsIndexProps = {
    accounts: Paginated<AccountingAccountRow>;
    filters: AccountingAccountFilters;
    types: FacetOption[];
};

const count = new Intl.NumberFormat('es-VE');

export default function AccountsIndex({
    accounts,
    filters,
    types,
}: AccountsIndexProps) {
    const { can } = usePermissions();
    const [isCreating, setIsCreating] = useState(false);

    return (
        <>
            <Head title="Plan de cuentas" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Contabilidad"
                    title="Plan de cuentas"
                    description="Los códigos de ingreso y egreso que usan el Libro Diario y los reportes."
                    meta={`${count.format(accounts.total)} ${accounts.total === 1 ? 'cuenta' : 'cuentas'}`}
                    actions={
                        can('catalogs.manage') && (
                            <Button
                                className="shrink-0"
                                onClick={() => setIsCreating(true)}
                            >
                                <Plus aria-hidden />
                                Nueva cuenta
                            </Button>
                        )
                    }
                />
                <ServerDataTable
                    url={accountsIndex().url}
                    prop="accounts"
                    data={accounts}
                    filters={filters}
                    columns={accountColumns}
                    rowKey={(account) => account.id}
                    rowActions={(account) => (
                        <AccountRowActions account={account} />
                    )}
                    searchLabel="Buscar por código o nombre"
                    statusLabel="Estado"
                    defaultStatus="active"
                    inactiveLabels={{
                        option: 'Inactivas',
                        all: 'Activas e inactivas',
                    }}
                    facets={[
                        {
                            key: 'type',
                            label: 'Tipo',
                            allLabel: 'Todos los tipos',
                            options: types,
                        },
                    ]}
                    empty={{
                        title: 'No hay cuentas con esos filtros',
                        description:
                            'Importe el plan del sistema anterior o cree una cuenta nueva.',
                    }}
                />
            </div>
            <AccountFormDialog open={isCreating} onOpenChange={setIsCreating} />
        </>
    );
}

AccountsIndex.layout = {
    breadcrumbs: [{ title: 'Plan de cuentas', href: accountsIndex() }],
};
