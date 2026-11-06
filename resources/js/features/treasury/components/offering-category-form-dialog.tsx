import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SelectField } from '@/components/form/select-field';
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
import type {
    AccountOption,
    OfferingCategoryRow,
} from '@/features/treasury/types';
import { store, update } from '@/routes/treasury/offering-categories';

type OfferingCategoryFormDialogProps = {
    category?: OfferingCategoryRow;
    accounts: AccountOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type CategoryFormData = { name: string; account_id: string };

/** Creates or edits a category and the income account its offerings go to. */
export function OfferingCategoryFormDialog({
    category,
    accounts,
    open,
    onOpenChange,
}: OfferingCategoryFormDialogProps) {
    const form = useForm<CategoryFormData>({
        name: category?.name ?? '',
        account_id: category?.account_id ?? '',
    });

    const options = accounts.map((account) => ({
        value: account.id,
        label: `${account.code} · ${account.name}`,
    }));

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(category ? update(category.id) : store(), {
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
                            {category ? 'Editar categoría' : 'Nueva categoría'}
                        </DialogTitle>
                        <DialogDescription>
                            Las ofrendas de esta categoría se asientan en la
                            cuenta de ingreso que elija. Cambiarla afecta solo
                            lo que se asiente desde ahora.
                        </DialogDescription>
                    </div>

                    <TextField
                        name="name"
                        label="Nombre"
                        autoComplete="off"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        error={form.errors.name}
                        required
                    />

                    <SelectField
                        name="account_id"
                        label="Cuenta de ingreso"
                        options={options}
                        value={form.data.account_id}
                        onValueChange={(value) =>
                            form.setData('account_id', value)
                        }
                        error={form.errors.account_id}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {category ? 'Guardar' : 'Crear categoría'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
