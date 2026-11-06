import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/members';

type MemberTrashDialogProps = {
    member: { id: string; full_name: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Asks for confirmation before sending a member to the trash. */
export function MemberTrashDialog({
    member,
    open,
    onOpenChange,
}: MemberTrashDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>¿Enviar a la papelera?</DialogTitle>
                <DialogDescription>
                    «{member.full_name}» dejará de aparecer en el padrón y se
                    soltará su vínculo de cónyuge. Podrá restaurarlo desde la
                    papelera.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={() => {
                            onOpenChange(false);
                            router.delete(destroy(member.id), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        Enviar a la papelera
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
