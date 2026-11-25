import { router } from '@inertiajs/react';
import { Pencil, RotateCcw, Trash2 } from 'lucide-react';
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
import { TitheFormDialog } from '@/features/treasury/components/tithe-form-dialog';
import { formatShortDate } from '@/features/treasury/rates';
import type { TitheRow } from '@/features/treasury/types';
import { usePermissions } from '@/hooks/use-permissions';
import { destroy, restore } from '@/routes/treasury/tithes';

/** Correct a tithe, send it to the trash, or bring it back. */
export function TitheRowActions({
    tithe,
    today,
}: {
    tithe: TitheRow;
    today: string;
}) {
    const { can } = usePermissions();
    const [isEditing, setIsEditing] = useState(false);
    const [isConfirming, setIsConfirming] = useState(false);

    if (!can('tithes.manage')) {
        return null;
    }

    const who = tithe.member?.name ?? 'Diezmo';
    const actions: RowAction[] = tithe.is_deleted
        ? [
              {
                  label: 'Restaurar',
                  icon: RotateCcw,
                  onSelect: () =>
                      router.post(
                          restore(tithe.id),
                          {},
                          { preserveScroll: true },
                      ),
              },
          ]
        : [
              {
                  label: 'Corregir',
                  icon: Pencil,
                  onSelect: () => setIsEditing(true),
              },
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
                label={`${who} ${formatShortDate(tithe.tithe_date)}`}
                actions={actions}
            />
            {!tithe.is_deleted && (
                <>
                    <TitheFormDialog
                        key={tithe.id}
                        tithe={tithe}
                        today={today}
                        open={isEditing}
                        onOpenChange={setIsEditing}
                    />
                    <Dialog open={isConfirming} onOpenChange={setIsConfirming}>
                        <DialogContent>
                            <DialogTitle>
                                ¿Enviar el diezmo a la papelera?
                            </DialogTitle>
                            <DialogDescription>
                                El diezmo de {who} del{' '}
                                {formatShortDate(tithe.tithe_date)} dejará de
                                sumar en los totales y en los reportes. Se puede
                                restaurar y queda registrado en la bitácora.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">
                                        Cancelar
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        setIsConfirming(false);
                                        router.delete(destroy(tithe.id), {
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
            )}
        </>
    );
}
