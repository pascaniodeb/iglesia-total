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
import type {
    CurrencyCode,
    JournalAccountOption,
    JournalEntryRow,
} from '@/features/accounting/types';
import { formatAmount } from '@/lib/money';
import { correct, store } from '@/routes/accounting/journal';

export const CURRENCY_OPTIONS = [
    { value: 'VES', label: 'Bolívares' },
    { value: 'USD', label: 'Dólares' },
    { value: 'COP', label: 'Pesos' },
];

type MovementDialogProps = {
    kind: 'income' | 'expense';
    accounts: JournalAccountOption[];
    window: { min: string | null; max: string };
    /** What can be spent today, per currency (shown for expenses). */
    available: Record<CurrencyCode, string> | null;
    /** The movement to correct; without it the dialog records a new one. */
    entry?: JournalEntryRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type MovementData = {
    kind: 'income' | 'expense';
    account_code: string;
    currency: string;
    amount: string;
    entry_date: string;
    description: string;
    token: string;
    reason: string;
};

function initial(
    kind: 'income' | 'expense',
    max: string,
    entry?: JournalEntryRow,
): MovementData {
    const currency = entry
        ? (Object.entries(entry.cash).find(
              ([, value]) => !/^-?0+(\.0+)?$/.test(value),
          )?.[0] ?? 'COP')
        : 'COP';
    const amount = entry
        ? entry.cash[currency as CurrencyCode]
              .replace('-', '')
              .replace('.', ',')
        : '';

    return {
        kind,
        account_code: entry?.codes[0]?.code ?? '',
        currency,
        amount,
        entry_date: entry?.entry_date ?? max,
        description: entry?.description ?? '',
        token: crypto.randomUUID(),
        reason: '',
    };
}

/**
 * Records by hand an income or an expense in one currency, or corrects one (a reversal of the
 * original plus the corrected movement, with a reason). An expense cannot take more than the
 * cash has: the server refuses it and says how much there is.
 */
export function MovementDialog({
    kind,
    accounts,
    window,
    available,
    entry,
    open,
    onOpenChange,
}: MovementDialogProps) {
    const form = useForm<MovementData>(initial(kind, window.max, entry));
    const isExpense = kind === 'expense';
    const title = entry
        ? 'Corregir movimiento'
        : isExpense
          ? 'Registrar egreso'
          : 'Registrar ingreso';
    const availableHere = available?.[form.data.currency as CurrencyCode];
    // The server also reports problems of the corrected entry itself under `entry`.
    const errors: Partial<Record<string, string>> = form.errors;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(entry ? correct(entry.id) : store(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData(initial(kind, window.max));
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
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>
                            {entry
                                ? 'El original se revierte con un contraasiento y se registra el movimiento corregido. Ambos quedan en el libro.'
                                : 'Una moneda por movimiento. Nada se edita después: un error se corrige o se anula con su motivo.'}
                        </DialogDescription>
                    </div>

                    <SelectField
                        name="account_code"
                        label={
                            isExpense ? 'Cuenta de egreso' : 'Cuenta de ingreso'
                        }
                        options={accounts.map((account) => ({
                            value: account.code,
                            label: `${account.code} · ${account.name}`,
                        }))}
                        value={form.data.account_code}
                        onValueChange={(value) =>
                            form.setData('account_code', value)
                        }
                        error={form.errors.account_code}
                    />

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SelectField
                            name="currency"
                            label="Moneda"
                            options={CURRENCY_OPTIONS}
                            value={form.data.currency}
                            onValueChange={(value) =>
                                form.setData('currency', value)
                            }
                            error={form.errors.currency}
                        />
                        <TextField
                            name="amount"
                            label="Monto"
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0,00"
                            value={form.data.amount}
                            onChange={(event) =>
                                form.setData('amount', event.target.value)
                            }
                            error={form.errors.amount}
                            description={
                                isExpense && availableHere !== undefined
                                    ? `Disponible hoy: ${formatAmount(availableHere)}`
                                    : undefined
                            }
                            required
                        />
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
                    </div>

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

                    {entry && (
                        <TextField
                            name="reason"
                            label="Motivo de la corrección"
                            autoComplete="off"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            error={errors.reason ?? errors.entry}
                            required
                        />
                    )}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {entry ? 'Guardar corrección' : 'Registrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
