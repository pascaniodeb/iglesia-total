import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ServerDataTable } from '@/components/data-table/server-data-table';
import type { FacetOption } from '@/components/data-table/types';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { memberColumns } from '@/features/members/components/member-columns';
import { MemberRowActions } from '@/features/members/components/member-row-actions';
import type { MemberFilters, MemberRow } from '@/features/members/types';
import { usePermissions } from '@/hooks/use-permissions';
import { create, index as membersIndex } from '@/routes/members';
import type { Paginated } from '@/types';

type MembersIndexProps = {
    members: Paginated<MemberRow>;
    filters: MemberFilters;
    statuses: FacetOption[];
    sexes: FacetOption[];
};

const count = new Intl.NumberFormat('es-VE');

export default function MembersIndex({
    members,
    filters,
    statuses,
    sexes,
}: MembersIndexProps) {
    const { can } = usePermissions();

    return (
        <>
            <Head title="Miembros" />
            <div className="flex min-w-0 flex-1 flex-col gap-8 p-4 md:p-8">
                <PageHeader
                    eyebrow="Secretaría"
                    title="Miembros"
                    description="El padrón de la congregación."
                    meta={`${count.format(members.total)} ${members.total === 1 ? 'miembro' : 'miembros'}`}
                    actions={
                        can('members.manage') && (
                            <Button asChild className="shrink-0">
                                <Link href={create()}>
                                    <Plus aria-hidden />
                                    Registrar miembro
                                </Link>
                            </Button>
                        )
                    }
                />
                <ServerDataTable
                    url={membersIndex().url}
                    prop="members"
                    data={members}
                    filters={filters}
                    columns={memberColumns}
                    rowKey={(member) => member.id}
                    rowActions={(member) => (
                        <MemberRowActions member={member} />
                    )}
                    searchLabel="Buscar por nombre, documento o teléfono"
                    statusLabel="Registro"
                    defaultStatus="active"
                    inactiveLabels={{
                        option: 'En la papelera',
                        all: 'Activos y papelera',
                    }}
                    facets={[
                        {
                            key: 'member_status',
                            label: 'Estado',
                            allLabel: 'Todos los estados',
                            options: statuses,
                        },
                        {
                            key: 'sex',
                            label: 'Sexo',
                            allLabel: 'Ambos sexos',
                            options: sexes,
                        },
                    ]}
                    empty={{
                        title: 'Aún no hay miembros',
                        description:
                            'Los miembros que registre o importe aparecerán aquí.',
                    }}
                />
            </div>
        </>
    );
}

MembersIndex.layout = {
    breadcrumbs: [{ title: 'Miembros', href: membersIndex() }],
};
