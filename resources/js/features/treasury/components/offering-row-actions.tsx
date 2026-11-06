import { router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import type { RowAction } from '@/components/data-table/data-table-row-actions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { OfferingFormDialog } from '@/features/treasury/components/offering-form-dialog';
import { formatShortDate } from '@/features/treasury/rates';
import type { CategoryOption, OfferingRow } from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { destroy } from '@/routes/treasury/offerings';

/** Correct an offering or send it to the trash (only while its week is open). */
export function OfferingRowActions({
    offering,
    categories,
    today,
}: {
    offering: OfferingRow;
    categories: CategoryOption[];
    today: string;
}) {
    const { can } = usePermissions();
    const [isEditing, setIsEditing] = useState(false);
    const [isConfirming, setIsConfirming] = useState(false);

    if (!can('offerings.manage') || offering.is_deleted) {
        return null;
    }

    const giver = offering.member?.name ?? 'Ofrenda anónima';
    const actions: RowAction[] = [
        { label: 'Corregir', icon: Pencil, onSelect: () => setIsEditing(true) },
        {
            label: 'Enviar a la papelera',
            icon: Trash2,
            destructive: true,
            onSelect: () => setIsConfirming(true),
        },
    ];

    return (
        <>
            <DataTableRowActions
                label={`${giver} ${formatShortDate(offering.offering_date)}`}
                actions={actions}
            />
            <OfferingFormDialog
                key={offering.id}
                offering={offering}
                categories={categories}
                today={today}
                open={isEditing}
                onOpenChange={setIsEditing}
            />
            <Dialog open={isConfirming} onOpenChange={setIsConfirming}>
                <DialogContent>
                    <DialogTitle>¿Enviar la ofrenda a la papelera?</DialogTitle>
                    <DialogDescription>
                        La ofrenda de {giver} del{' '}
                        {formatShortDate(offering.offering_date)} dejará de
                        sumar en los totales. Queda registrada en la bitácora.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setIsConfirming(false);
                                router.delete(destroy(offering.id), {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            Enviar a la papelera
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
