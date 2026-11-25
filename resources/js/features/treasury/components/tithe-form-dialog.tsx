import { useForm } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { FormEvent } from 'react';
import { DateField } from '@/components/form/date-field';
import { RemoteSearchField } from '@/components/form/remote-search-field';
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
import type { TitheRow } from '@/features/treasury/types';
import { editableAmount } from '@/lib/money';
import { memberOptions, store, update } from '@/routes/treasury/tithes';

type TitheFormDialogProps = {
    tithe?: TitheRow;
    /** Today in Venezuela: the latest date a tithe can have. */
    today: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type TitheFormData = {
    tithe_date: string;
    member_id: string;
    notes: string;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
};

const CURRENCIES = [
    { field: 'amount_ves', label: 'Bolívares' },
    { field: 'amount_usd', label: 'Dólares' },
    { field: 'amount_cop', label: 'Pesos' },
] as const;

/**
 * Records or corrects a tithe. A tithe always has a member of the registry. Each currency is
 * written apart: they are never converted.
 */
export function TitheFormDialog({
    tithe,
    today,
    open,
    onOpenChange,
}: TitheFormDialogProps) {
    const form = useForm<TitheFormData>({
        tithe_date: tithe?.tithe_date ?? today,
        member_id: tithe?.member?.id ?? '',
        notes: tithe?.notes ?? '',
        amount_ves: tithe ? editableAmount(tithe.amount_ves) : '',
        amount_usd: tithe ? editableAmount(tithe.amount_usd) : '',
        amount_cop: tithe ? editableAmount(tithe.amount_cop) : '',
    });
    const [member, setMember] = useState<{ id: string; name: string } | null>(
        tithe?.member ? { id: tithe.member.id, name: tithe.member.name } : null,
    );
    const searchUrl = useCallback(
        (search: string) => memberOptions({ query: { search } }).url,
        [],
    );

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(tithe ? update(tithe.id) : store(), {
            preserveScroll: true,
            onSuccess: () => {
                if (!tithe) {
                    form.reset(
                        'member_id',
                        'notes',
                        'amount_ves',
                        'amount_usd',
                        'amount_cop',
                    );
                    setMember(null);
                }
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
                        <DialogTitle>
                            {tithe ? 'Corregir diezmo' : 'Registrar diezmo'}
                        </DialogTitle>
                        <DialogDescription>
                            Escriba cada moneda por separado; deje vacío lo que
                            no se recibió. El diezmo no es ingreso de la
                            iglesia: no entra en la contabilidad.
                        </DialogDescription>
                    </div>

                    <DateField
                        name="tithe_date"
                        label="Fecha"
                        value={form.data.tithe_date}
                        onValueChange={(value) =>
                            form.setData('tithe_date', value)
                        }
                        max={today}
                        error={form.errors.tithe_date}
                    />

                    <RemoteSearchField
                        label="Miembro"
                        description="Un diezmo siempre es de un miembro del padrón."
                        value={member}
                        onChange={(choice) => {
                            setMember(choice);
                            form.setData('member_id', choice?.id ?? '');
                        }}
                        searchUrl={searchUrl}
                        placeholder="Buscar miembro…"
                        emptyText="Ningún miembro del padrón coincide."
                        resultsLabel="Miembros que coinciden"
                        error={form.errors.member_id}
                    />

                    <fieldset className="m-0 grid min-w-0 grid-cols-1 gap-4 border-0 p-0 sm:grid-cols-3">
                        <legend className="sr-only">Montos</legend>
                        {CURRENCIES.map(({ field, label }) => (
                            <TextField
                                key={field}
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
                            />
                        ))}
                    </fieldset>

                    <TextField
                        name="notes"
                        label="Notas"
                        description="Opcional."
                        autoComplete="off"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        error={form.errors.notes}
                    />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {tithe ? 'Guardar corrección' : 'Registrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
