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
import type { AccountingAccountRow } from '@/features/accounting/types';
import { store, update } from '@/routes/accounting/accounts';

type AccountFormDialogProps = {
    /** The account to rename; without it the dialog creates one. */
    account?: AccountingAccountRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type AccountFormData = { code: string; name: string };

/**
 * Creates an income or expense account, or renames one. The code is fixed once created: it is
 * what every past report prints.
 */
export function AccountFormDialog({
    account,
    open,
    onOpenChange,
}: AccountFormDialogProps) {
    const form = useForm<AccountFormData>({
        code: account?.code ?? '',
        name: account?.name ?? '',
    });

    function handleSubmit(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        };

        // Editing sends only the name: the code is fixed once created.
        form.transform((data) => (account ? { name: data.name } : data));
        form.submit(account ? update(account.id) : store(), options);
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
                            {account
                                ? `Editar cuenta ${account.code}`
                                : 'Nueva cuenta'}
                        </DialogTitle>
                        <DialogDescription>
                            {account
                                ? 'El código no cambia: es lo que imprimen los reportes anteriores.'
                                : 'Use I- para ingresos y E- para egresos, seguido de tres dígitos.'}
                        </DialogDescription>
                    </div>

                    {!account && (
                        <TextField
                            name="code"
                            label="Código"
                            placeholder="I-010"
                            autoComplete="off"
                            value={form.data.code}
                            onChange={(event) =>
                                form.setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            error={form.errors.code}
                            required
                        />
                    )}

                    <TextField
                        name="name"
                        label="Nombre"
                        autoComplete="off"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData(
                                'name',
                                event.target.value.toUpperCase(),
                            )
                        }
                        error={form.errors.name}
                        required
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {account ? 'Guardar' : 'Crear cuenta'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
