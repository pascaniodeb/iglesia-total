import { cn } from '@/lib/utils';
import type { MemberRow } from '@/features/members/types';

/** Tone of each member status. Every pair keeps AA contrast in both themes. */
const TONES: Record<string, string> = {
    activo: 'bg-primary/10 text-primary',
    trasladado: 'bg-muted text-foreground',
    inactivo: 'bg-muted text-muted-foreground',
    fallecido: 'bg-muted text-muted-foreground',
};

const TRASH = 'bg-destructive-foreground/10 text-destructive-foreground';

/** The status of a member; a member in the trash shows that instead. */
export function MemberStatusBadge({
    member,
}: {
    member: Pick<MemberRow, 'status' | 'status_label' | 'is_deleted'>;
}) {
    const label = member.is_deleted ? 'En papelera' : member.status_label;

    if (label === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const tone = member.is_deleted ? TRASH : (TONES[member.status ?? ''] ?? '');

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold whitespace-nowrap',
                tone,
            )}
        >
            {label}
        </span>
    );
}
