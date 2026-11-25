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
import { settings } from '@/routes/treasury/receipts';

type ReceiptSettingsDialogProps = {
    startNumber: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * The number the receipt book starts at. Raising it begins a new physical book; the series never
 * goes back, because the next receipt is always the one after the highest issued.
 */
export function ReceiptSettingsDialog({
    startNumber,
    open,
    onOpenChange,
}: ReceiptSettingsDialogProps) {
    const form = useForm({ start_number: String(startNumber) });

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(settings(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.clearErrors();
                    form.setData('start_number', String(startNumber));
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                    <div className="flex flex-col gap-2">
                        <DialogTitle>Número inicial del talonario</DialogTitle>
                        <DialogDescription>
                            Es el número del primer comprobante de un talonario
                            nuevo. Súbalo cuando empiece uno; el sistema nunca
                            repite un número ya emitido, aunque lo baje.
                        </DialogDescription>
                    </div>
                    <TextField
                        name="start_number"
                        label="Número inicial"
                        inputMode="numeric"
                        autoComplete="off"
                        value={form.data.start_number}
                        onChange={(event) =>
                            form.setData('start_number', event.target.value)
                        }
                        error={form.errors.start_number}
                        required
                    />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
