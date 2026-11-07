import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { DateField } from '@/components/form/date-field';
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
import { CURRENCY_OPTIONS } from '@/features/accounting/components/movement-dialog';
import type { CurrencyCode } from '@/features/accounting/types';
import { formatAmount } from '@/lib/money';
import { exchange } from '@/routes/accounting/journal';

type ExchangeDialogProps = {
    window: { min: string | null; max: string };
    available: Record<CurrencyCode, string> | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function initial(max: string) {
    return {
        from_currency: 'USD',
        from_amount: '',
        to_currency: 'COP',
        to_amount: '',
        entry_date: max,
        description: '',
        token: crypto.randomUUID(),
    };
}

/**
 * A currency exchange with the amounts actually given and received (never computed from a rate).
 * The currency given cannot exceed what the cash has.
 */
export function ExchangeDialog({
    window,
    available,
    open,
    onOpenChange,
}: ExchangeDialogProps) {
    const form = useForm(initial(window.max));
    const availableHere = available?.[form.data.from_currency as CurrencyCode];

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(exchange(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData(initial(window.max));
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
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                    <div className="flex flex-col gap-2">
                        <DialogTitle>Cambio de divisas</DialogTitle>
                        <DialogDescription>
                            Escriba lo que salió de la caja y lo que entró, tal
                            como se cambió.
                        </DialogDescription>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            name="from_currency"
                            label="Sale"
                            options={CURRENCY_OPTIONS}
                            value={form.data.from_currency}
                            onValueChange={(value) =>
                                form.setData('from_currency', value)
                            }
                            error={form.errors.from_currency}
                        />
                        <TextField
                            name="from_amount"
                            label="Monto que sale"
                            inputMode="decimal"
                            autoComplete="off"
                            value={form.data.from_amount}
                            onChange={(event) =>
                                form.setData('from_amount', event.target.value)
                            }
                            error={form.errors.from_amount}
                            description={
                                availableHere !== undefined
                                    ? `Disponible hoy: ${formatAmount(availableHere)}`
                                    : undefined
                            }
                            required
                        />
                        <SelectField
                            name="to_currency"
                            label="Entra"
                            options={CURRENCY_OPTIONS}
                            value={form.data.to_currency}
                            onValueChange={(value) =>
                                form.setData('to_currency', value)
                            }
                            error={form.errors.to_currency}
                        />
                        <TextField
                            name="to_amount"
                            label="Monto que entra"
                            inputMode="decimal"
                            autoComplete="off"
                            value={form.data.to_amount}
                            onChange={(event) =>
                                form.setData('to_amount', event.target.value)
                            }
                            error={form.errors.to_amount}
                            required
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_2fr]">
                        <DateField
                            name="entry_date"
                            label="Fecha"
                            value={form.data.entry_date}
                            onValueChange={(value) =>
                                form.setData('entry_date', value)
                            }
                            min={window.min ?? undefined}
                            max={window.max}
                            error={form.errors.entry_date}
                        />
                        <TextField
                            name="description"
                            label="Concepto"
                            autoComplete="off"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            error={form.errors.description}
                            required
                        />
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Registrar cambio
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
