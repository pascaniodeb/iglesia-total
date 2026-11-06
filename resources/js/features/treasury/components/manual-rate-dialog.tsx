import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { DateField } from '@/components/form/date-field';
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
import { editableRate, formatShortDate } from '@/features/treasury/rates';
import type { ExchangeRateRow } from '@/features/treasury/types';
import { store } from '@/routes/treasury/exchange-rates';

type ManualRateDialogProps = {
    /** When correcting, the day and its current rates; when recording, nothing. */
    rate?: ExchangeRateRow;
    /** Today in Venezuela (YYYY-MM-DD): the latest day that can be recorded. */
    today: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type ManualRateData = { rate_date: string; usd_rate: string; cop_rate: string };

/**
 * Records or corrects by hand the rates of a day. From then on the automatic sync leaves that
 * day alone, and the change is recorded in the activity log with its previous value.
 */
export function ManualRateDialog({
    rate,
    today,
    open,
    onOpenChange,
}: ManualRateDialogProps) {
    const form = useForm<ManualRateData>({
        rate_date: rate?.date ?? today,
        usd_rate: rate ? editableRate(rate.usd_rate) : '',
        cop_rate: rate ? editableRate(rate.cop_rate) : '',
    });

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(store(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    const title: ReactNode = rate
        ? `Corregir las tasas del ${formatShortDate(rate.date)}`
        : 'Registrar tasa';

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
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>
                            {rate
                                ? 'La corrección queda en la bitácora con el valor anterior, y la sincronización ya no cambia este día.'
                                : 'Para un día sin tasa o cuando la oficial no corresponde. La actualización automática ya no lo cambia.'}
                        </DialogDescription>
                    </div>

                    <DateField
                        name="rate_date"
                        label="Fecha"
                        value={form.data.rate_date}
                        onValueChange={(value) =>
                            form.setData('rate_date', value)
                        }
                        max={today}
                        disabled={rate !== undefined}
                        error={form.errors.rate_date}
                    />
                    <TextField
                        name="usd_rate"
                        label="Bolívares por dólar"
                        description="Tasa oficial del BCV, con hasta 4 decimales."
                        inputMode="decimal"
                        autoComplete="off"
                        value={form.data.usd_rate}
                        onChange={(event) =>
                            form.setData('usd_rate', event.target.value)
                        }
                        error={form.errors.usd_rate}
                        required
                    />
                    <TextField
                        name="cop_rate"
                        label="Pesos por bolívar"
                        description="Cuántos pesos colombianos vale un bolívar, con hasta 6 decimales."
                        inputMode="decimal"
                        autoComplete="off"
                        value={form.data.cop_rate}
                        onChange={(event) =>
                            form.setData('cop_rate', event.target.value)
                        }
                        error={form.errors.cop_rate}
                        required
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {rate ? 'Guardar corrección' : 'Guardar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
