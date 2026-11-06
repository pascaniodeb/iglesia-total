import { MoreHorizontal } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type RowAction = {
    label: string;
    icon: LucideIcon;
    onSelect: () => void;
    destructive?: boolean;
};

/**
 * Row actions menu. Not modal so the dialog it opens receives focus.
 */
export function DataTableRowActions({
    label,
    actions,
}: {
    /** Nombre del registro, para el lector de pantalla. */
    label: string;
    actions: RowAction[];
}) {
    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="rounded-lg"
                    aria-label={`Acciones para ${label}`}
                >
                    <MoreHorizontal aria-hidden />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="min-w-48 rounded-2xl p-2"
            >
                {actions.map((action) => (
                    <DropdownMenuItem
                        key={action.label}
                        variant={action.destructive ? 'destructive' : 'default'}
                        onSelect={action.onSelect}
                        className="gap-3 rounded-xl px-3 py-2.5 text-base"
                    >
                        <action.icon aria-hidden />
                        {action.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
