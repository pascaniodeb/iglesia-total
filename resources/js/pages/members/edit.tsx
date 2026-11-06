import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { MemberForm } from '@/features/members/components/member-form';
import { MemberTrashDialog } from '@/features/members/components/member-trash-dialog';
import type { MemberDetail, MemberOptions } from '@/features/members/types';
import { usePermissions } from '@/hooks/use-permissions';
import { index, restore, show } from '@/routes/members';

type MembersEditProps = {
    member: MemberDetail;
    options: MemberOptions;
    /** The server decides: a member in the trash, or an account that cannot manage members. */
    read_only: boolean;
};

export default function MembersEdit({
    member,
    options,
    read_only,
}: MembersEditProps) {
    const { can } = usePermissions();
    const canManage = can('members.manage');
    const [confirming, setConfirming] = useState(false);

    // The breadcrumb carries the member's name, so it cannot be a static `layout` object.
    setLayoutProps({
        breadcrumbs: [
            { title: 'Miembros', href: index() },
            { title: member.full_name, href: show(member.id) },
        ],
    });

    const notice = member.is_deleted
        ? 'Este miembro está en la papelera. Restáurelo para poder modificar su ficha.'
        : read_only
          ? 'Solo lectura: su rol puede consultar el padrón, pero no modificarlo.'
          : undefined;

    return (
        <>
            <Head title={member.full_name} />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:px-8 md:py-4">
                <PageHeader
                    compact
                    eyebrow={
                        member.is_deleted ? 'Miembros · Papelera' : 'Miembros'
                    }
                    title={member.full_name}
                    description={
                        read_only
                            ? 'Ficha del miembro.'
                            : 'Edite la ficha por secciones.'
                    }
                    actions={
                        canManage &&
                        (member.is_deleted ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        restore(member.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Undo2 aria-hidden />
                                Restaurar
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                onClick={() => setConfirming(true)}
                            >
                                <Trash2 aria-hidden />
                                Enviar a la papelera
                            </Button>
                        ))
                    }
                />
                <MemberForm
                    // A saved member brings a new card: start the form again from it.
                    key={`${member.id}-${member.is_deleted}`}
                    member={member}
                    options={options}
                    readOnly={read_only}
                    notice={notice}
                />
            </div>
            <MemberTrashDialog
                member={member}
                open={confirming}
                onOpenChange={setConfirming}
            />
        </>
    );
}
