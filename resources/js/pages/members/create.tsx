import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { MemberForm } from '@/features/members/components/member-form';
import type { MemberOptions } from '@/features/members/types';
import { create, index } from '@/routes/members';

export default function MembersCreate({ options }: { options: MemberOptions }) {
    return (
        <>
            <Head title="Registrar miembro" />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:px-8 md:py-4">
                <PageHeader
                    compact
                    eyebrow="Miembros"
                    title="Registrar miembro"
                    description="Complete la ficha por secciones. Solo el nombre es obligatorio."
                />
                <MemberForm options={options} />
            </div>
        </>
    );
}

MembersCreate.layout = {
    breadcrumbs: [
        { title: 'Miembros', href: index() },
        { title: 'Registrar miembro', href: create() },
    ],
};
