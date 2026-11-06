import { useForm } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { FormEvent } from 'react';
import { DateField } from '@/components/form/date-field';
import { RemoteSearchField } from '@/components/form/remote-search-field';
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
import type { CategoryOption, OfferingRow } from '@/features/treasury/types';
import { editableAmount } from '@/lib/money';
import { memberOptions, store, update } from '@/routes/treasury/offerings';

type OfferingFormDialogProps = {
    offering?: OfferingRow;
    categories: CategoryOption[];
    /** Today in Venezuela: the latest date an offering can have. */
    today: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type OfferingFormData = {
    offering_date: string;
    offering_category_id: string;
    member_id: string;
    concept: string;
    amount_ves: string;
    amount_usd: string;
    amount_cop: string;
};

/**
 * Records or corrects an offering. The giver is searched in the registry; without one the
 * offering is anonymous. Each currency is written apart: they are never converted.
 */
export function OfferingFormDialog({
    offering,
    categories,
    today,
    open,
    onOpenChange,
}: OfferingFormDialogProps) {
    const form = useForm<OfferingFormData>({
        offering_date: offering?.offering_date ?? today,
        offering_category_id: offering?.category_id ?? '',
        member_id: offering?.member?.id ?? '',
        concept: offering?.concept ?? '',
        amount_ves: offering ? editableAmount(offering.amount_ves) : '',
        amount_usd: offering ? editableAmount(offering.amount_usd) : '',
        amount_cop: offering ? editableAmount(offering.amount_cop) : '',
    });
    const [member, setMember] = useMemberChoice(offering);
    const searchUrl = useCallback(
        (search: string) => memberOptions({ query: { search } }).url,
        [],
    );

    const options = categories
        .filter(
            (category) =>
                category.is_active || category.value === offering?.category_id,
        )
        .map((category) => ({ value: category.value, label: category.label }));

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.submit(offering ? update(offering.id) : store(), {
            preserveScroll: true,
            onSuccess: () => {
                if (!offering) {
                    form.reset(
                        'member_id',
                        'concept',
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
                            {offering
                                ? 'Corregir ofrenda'
                                : 'Registrar ofrenda'}
                        </DialogTitle>
                        <DialogDescription>
                            Escriba cada moneda por separado; deje vacío lo que
                            no se recibió.
                        </DialogDescription>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <DateField
                            name="offering_date"
                            label="Fecha"
                            value={form.data.offering_date}
                            onValueChange={(value) =>
                                form.setData('offering_date', value)
                            }
                            max={today}
                            error={form.errors.offering_date}
                        />
                        <SelectField
                            name="offering_category_id"
                            label="Categoría"
                            options={options}
                            value={form.data.offering_category_id}
                            onValueChange={(value) =>
                                form.setData('offering_category_id', value)
                            }
                            error={form.errors.offering_category_id}
                        />
                    </div>

                    <RemoteSearchField
                        label="Miembro"
                        description="Sin miembro, la ofrenda queda como anónima."
                        value={member}
                        onChange={(choice) => {
                            setMember(choice);
                            form.setData('member_id', choice?.id ?? '');
                        }}
                        searchUrl={searchUrl}
                        placeholder="Buscar miembro…"
                        clearLabel="Ofrenda anónima"
                        emptyText="Ningún miembro del padrón coincide."
                        resultsLabel="Miembros que coinciden"
                        error={form.errors.member_id}
                    />

                    <TextField
                        name="concept"
                        label="Concepto"
                        description="Opcional."
                        autoComplete="off"
                        value={form.data.concept}
                        onChange={(event) =>
                            form.setData('concept', event.target.value)
                        }
                        error={form.errors.concept}
                    />

                    <fieldset className="m-0 grid min-w-0 grid-cols-1 gap-4 border-0 p-0 sm:grid-cols-3">
                        <legend className="sr-only">Montos</legend>
                        <TextField
                            name="amount_ves"
                            label="Bolívares"
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0,00"
                            value={form.data.amount_ves}
                            onChange={(event) =>
                                form.setData('amount_ves', event.target.value)
                            }
                            error={form.errors.amount_ves}
                        />
                        <TextField
                            name="amount_usd"
                            label="Dólares"
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0,00"
                            value={form.data.amount_usd}
                            onChange={(event) =>
                                form.setData('amount_usd', event.target.value)
                            }
                            error={form.errors.amount_usd}
                        />
                        <TextField
                            name="amount_cop"
                            label="Pesos"
                            inputMode="decimal"
                            autoComplete="off"
                            placeholder="0,00"
                            value={form.data.amount_cop}
                            onChange={(event) =>
                                form.setData('amount_cop', event.target.value)
                            }
                            error={form.errors.amount_cop}
                        />
                    </fieldset>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {offering ? 'Guardar corrección' : 'Registrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function useMemberChoice(offering?: OfferingRow) {
    return useState<{ id: string; name: string } | null>(
        offering?.member
            ? { id: offering.member.id, name: offering.member.name }
            : null,
    );
}
