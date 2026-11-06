import { Link } from '@inertiajs/react';
import type { DataTableColumn } from '@/components/data-table/types';
import { MemberStatusBadge } from '@/features/members/components/member-status-badge';
import type { MemberRow, MemberSortKey } from '@/features/members/types';
import { show } from '@/routes/members';

const dash = <span className="text-muted-foreground">—</span>;

/** "05/03/2024" from a YYYY-MM-DD date, without going through a timezone. */
function formatDate(iso: string | null): string | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');

    return match ? `${match[3]}/${match[2]}/${match[1]}` : null;
}

/**
 * Columns of the members table. Under the container width of a table they become cards (name
 * as the title, status as a badge, the rest as label/value pairs): no horizontal scroll.
 * Secondary columns only show when the table has room.
 */
export const memberColumns: DataTableColumn<MemberRow, MemberSortKey>[] = [
    {
        key: 'name',
        header: 'Nombre',
        sortKey: 'name',
        mobile: 'primary',
        cell: (member) => (
            <Link
                href={show(member.id)}
                className="font-semibold break-words underline-offset-4 hover:underline focus-visible:underline"
            >
                {member.full_name}
            </Link>
        ),
    },
    {
        key: 'document',
        header: 'Documento',
        sortKey: 'document',
        cell: (member) => member.document ?? dash,
    },
    {
        key: 'phone',
        header: 'Teléfono',
        priority: 'secondary',
        cell: (member) => member.phone ?? dash,
    },
    {
        key: 'joined',
        header: 'Ingreso',
        sortKey: 'joined',
        priority: 'tertiary',
        cell: (member) => formatDate(member.joined_date) ?? dash,
    },
    {
        key: 'status',
        header: 'Estado',
        sortKey: 'status',
        mobile: 'badge',
        cell: (member) => <MemberStatusBadge member={member} />,
    },
];
