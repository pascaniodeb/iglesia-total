import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { DateField } from '@/components/form/date-field';
import { SelectField } from '@/components/form/select-field';
import { TextareaField } from '@/components/form/textarea-field';
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
    PaymentMethod,
    ReceiptFormData,
    ReceiptType,
} from '@/features/treasury/types';
import { formatAmount } from '@/lib/money';
import { store } from '@/routes/treasury/receipts';

type ReceiptFormDialogProps = {
    form: ReceiptFormData;
    /** The number the receipt will most likely get ("000004"): a preview, nothing is reserved. */
    nextNumber: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type ReceiptData = {
    type: ReceiptType;
    issued_on: string;
    account_id: string;
    holder: string;
    counterparty: string;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
    concept: string;
    payment_method: PaymentMethod;
    bank: string;
    payment_reference: string;
    payment_date: string;
    received_by_name: string;
    received_by_id_number: string;
    delivered_by_name: string;
    delivered_by_id_number: string;
    token: string;
};

const TYPE_OPTIONS = [
    { value: 'income', label: 'Ingreso: recibimos dinero' },
    { value: 'expense', label: 'Egreso: pagamos dinero' },
];

const CURRENCIES = [
    { code: 'VES', field: 'amount_ves', label: 'Bolívares' },
    { code: 'USD', field: 'amount_usd', label: 'Dólares' },
    { code: 'COP', field: 'amount_cop', label: 'Pesos COP' },
] as const;

function initial(today: string): ReceiptData {
    return {
        type: 'income',
        issued_on: today,
        account_id: '',
        holder: '',
        counterparty: '',
        amount_ves: '',
        amount_usd: '',
        amount_cop: '',
        concept: '',
        payment_method: 'cash',
        bank: '',
        payment_reference: '',
        payment_date: '',
        received_by_name: '',
        received_by_id_number: '',
        delivered_by_name: '',
        delivered_by_id_number: '',
        token: crypto.randomUUID(),
    };
}

/**
 * Issues a receipt of the IPUV book. The number is assigned when it is saved, in order, and its
 * entry is posted to the journal in the same step; an expense that takes more than the cash has in
 * any currency is refused, and nothing is saved.
 */
export function ReceiptFormDialog({
    form: options,
    nextNumber,
    open,
    onOpenChange,
}: ReceiptFormDialogProps) {
    const form = useForm<ReceiptData>(initial(options.window.max));
    const isExpense = form.data.type === 'expense';
    const accounts = options.accounts[form.data.type];
    const isBanked = form.data.payment_method !== 'cash';

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(store(), {
            preserveScroll: true,
            onSuccess: () => {
                form.setData(initial(options.window.max));
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
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={handleSubmit} className="flex flex-col gap-5">
                    <div className="flex flex-col gap-2">
                        <DialogTitle>Emitir comprobante</DialogTitle>
                        <DialogDescription>
                            El número se asigna al guardar, en orden: el próximo
                            será el{' '}
                            <span className="font-mono font-bold">
                                {nextNumber}
                            </span>
                            . Un comprobante no se edita después: se anula con
                            su motivo y se emite otro.
                        </DialogDescription>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            name="type"
                            label="Tipo"
                            options={TYPE_OPTIONS}
                            value={form.data.type}
                            onValueChange={(value) => {
                                form.setData((data) => ({
                                    ...data,
                                    type: value as ReceiptType,
                                    account_id: '',
                                }));
                            }}
                            error={form.errors.type}
                        />
                        <DateField
                            name="issued_on"
                            label="Fecha de emisión"
                            value={form.data.issued_on}
                            onValueChange={(value) =>
                                form.setData('issued_on', value)
                            }
                            min={options.window.min ?? undefined}
                            max={options.window.max}
                            error={form.errors.issued_on}
                        />
                    </div>

                    <SelectField
                        name="account_id"
                        label="Código contable"
                        description="Un comprobante lleva un solo código."
                        options={accounts.map((account) => ({
                            value: account.id,
                            label: `${account.code} · ${account.name}`,
                        }))}
                        value={form.data.account_id}
                        onValueChange={(value) =>
                            form.setData('account_id', value)
                        }
                        error={form.errors.account_id}
                    />

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <TextField
                            name="holder"
                            label="Titular"
                            autoComplete="off"
                            value={form.data.holder}
                            onChange={(event) =>
                                form.setData('holder', event.target.value)
                            }
                            error={form.errors.holder}
                        />
                        <TextField
                            name="counterparty"
                            label={
                                isExpense
                                    ? 'Hemos pagado a'
                                    : 'Hemos recibido de'
                            }
                            autoComplete="off"
                            value={form.data.counterparty}
                            onChange={(event) =>
                                form.setData('counterparty', event.target.value)
                            }
                            error={form.errors.counterparty}
                        />
                    </div>

                    <fieldset className="flex flex-col gap-3">
                        <legend className="text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                            Monto
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            Llene una o varias monedas, cada una por separado.
                        </p>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {CURRENCIES.map(({ code, field, label }) => (
                                <TextField
                                    key={code}
                                    name={field}
                                    label={label}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder="0,00"
                                    value={form.data[field]}
                                    onChange={(event) =>
                                        form.setData(field, event.target.value)
                                    }
                                    error={form.errors[field]}
                                    description={
                                        isExpense
                                            ? `Disponible hoy: ${formatAmount(options.available[code])}`
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    </fieldset>

                    <TextareaField
                        name="concept"
                        label="Por concepto de"
                        rows={2}
                        value={form.data.concept}
                        onChange={(event) =>
                            form.setData('concept', event.target.value)
                        }
                        error={form.errors.concept}
                    />

                    <SelectField
                        name="payment_method"
                        label="Forma de pago"
                        options={options.payment_methods}
                        value={form.data.payment_method}
                        onValueChange={(value) =>
                            form.setData(
                                'payment_method',
                                value as PaymentMethod,
                            )
                        }
                        error={form.errors.payment_method}
                    />

                    {isBanked && (
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <TextField
                                name="bank"
                                label="Banco"
                                autoComplete="off"
                                value={form.data.bank}
                                onChange={(event) =>
                                    form.setData('bank', event.target.value)
                                }
                                error={form.errors.bank}
                            />
                            <TextField
                                name="payment_reference"
                                label="N.º de referencia"
                                autoComplete="off"
                                value={form.data.payment_reference}
                                onChange={(event) =>
                                    form.setData(
                                        'payment_reference',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.payment_reference}
                            />
                            <DateField
                                name="payment_date"
                                label="Fecha del pago"
                                value={form.data.payment_date}
                                onValueChange={(value) =>
                                    form.setData('payment_date', value)
                                }
                                max={options.window.max}
                                error={form.errors.payment_date}
                            />
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <fieldset className="flex flex-col gap-3">
                            <legend className="mb-1 text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                                Recibí conforme
                            </legend>
                            <TextField
                                name="received_by_name"
                                label="Nombre y apellido"
                                autoComplete="off"
                                value={form.data.received_by_name}
                                onChange={(event) =>
                                    form.setData(
                                        'received_by_name',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.received_by_name}
                            />
                            <TextField
                                name="received_by_id_number"
                                label="N.º C.I."
                                autoComplete="off"
                                value={form.data.received_by_id_number}
                                onChange={(event) =>
                                    form.setData(
                                        'received_by_id_number',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.received_by_id_number}
                            />
                        </fieldset>
                        <fieldset className="flex flex-col gap-3">
                            <legend className="mb-1 text-sm font-bold tracking-[0.15em] text-muted-foreground uppercase">
                                Entregué conforme
                            </legend>
                            <TextField
                                name="delivered_by_name"
                                label="Nombre y apellido"
                                autoComplete="off"
                                value={form.data.delivered_by_name}
                                onChange={(event) =>
                                    form.setData(
                                        'delivered_by_name',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.delivered_by_name}
                            />
                            <TextField
                                name="delivered_by_id_number"
                                label="N.º C.I."
                                autoComplete="off"
                                value={form.data.delivered_by_id_number}
                                onChange={(event) =>
                                    form.setData(
                                        'delivered_by_id_number',
                                        event.target.value,
                                    )
                                }
                                error={form.errors.delivered_by_id_number}
                            />
                        </fieldset>
                    </div>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Emitir comprobante
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
