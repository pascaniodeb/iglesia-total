import { router } from '@inertiajs/react';
import { Eye, Pencil, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import type { RowAction } from '@/components/data-table/data-table-row-actions';
import { MemberTrashDialog } from '@/features/members/components/member-trash-dialog';
import type { MemberRow } from '@/features/members/types';
import { usePermissions } from '@/hooks/use-permissions';
import { restore, show } from '@/routes/members';

/** Actions of each member in the listing: open the card, send to the trash or restore. */
export function MemberRowActions({ member }: { member: MemberRow }) {
    const { can } = usePermissions();
    const canManage = can('members.manage');
    const [confirming, setConfirming] = useState(false);

    const editable = canManage && !member.is_deleted;

    const actions: RowAction[] = [
        {
            label: editable ? 'Editar ficha' : 'Ver ficha',
            icon: editable ? Pencil : Eye,
            onSelect: () => router.visit(show(member.id)),
        },
    ];

    if (canManage && member.is_deleted) {
        actions.push({
            label: 'Restaurar',
            icon: Undo2,
            onSelect: () =>
                router.post(restore(member.id), {}, { preserveScroll: true }),
        });
    }

    if (editable) {
        actions.push({
            label: 'Enviar a la papelera',
            icon: Trash2,
            destructive: true,
            onSelect: () => setConfirming(true),
        });
    }

    return (
        <>
            <DataTableRowActions label={member.full_name} actions={actions} />
            <MemberTrashDialog
                member={member}
                open={confirming}
                onOpenChange={setConfirming}
            />
        </>
    );
}
