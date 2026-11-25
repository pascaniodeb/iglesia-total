import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { TextField } from '@/components/form/text-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { cancel } from '@/routes/treasury/receipts';

type ReceiptVoidDialogProps = {
    number: number;
    numberLabel: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Voids a receipt: it keeps its number and what it says, gets a reason, and the entry it posted is
 * undone by a reversal. Nothing is deleted; to replace a wrong receipt, issue a new one.
 */
export function ReceiptVoidDialog({
    number,
    numberLabel,
    open,
    onOpenChange,
}: ReceiptVoidDialogProps) {
    const form = useForm({ reason: '' });
    // The server also reports problems of the receipt itself under `receipt`.
    const errors: Partial<Record<string, string>> = form.errors;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(cancel(number), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.clearErrors();
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                    <div className="flex flex-col gap-2">
                        <DialogTitle>
                            ¿Anular el comprobante N.º {numberLabel}?
                        </DialogTitle>
                        <DialogDescription>
                            Conserva su número y lo que dice, y queda anotado el
                            motivo. Su asiento se deshace con un contraasiento
                            (si su mes ya cerró, con fecha de hoy). No se puede
                            volver a usar: para reemplazarlo, emita uno nuevo.
                        </DialogDescription>
                    </div>
                    <TextField
                        name="reason"
                        label="Motivo"
                        description="Entre 5 y 500 caracteres."
                        autoComplete="off"
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        error={errors.reason ?? errors.receipt}
                        required
                    />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Anular comprobante
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
