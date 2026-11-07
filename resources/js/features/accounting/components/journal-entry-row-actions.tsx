import { useForm } from '@inertiajs/react';
import { Ban, Pencil } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import type { RowAction } from '@/components/data-table/data-table-row-actions';
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
import { MovementDialog } from '@/features/accounting/components/movement-dialog';
import type {
    CurrencyCode,
    JournalAccountOption,
    JournalEntryRow,
} from '@/features/accounting/types';
import { cancel } from '@/routes/accounting/journal';

type JournalEntryRowActionsProps = {
    entry: JournalEntryRow;
    accounts: {
        income: JournalAccountOption[];
        expense: JournalAccountOption[];
    };
    window: { min: string | null; max: string };
    available: Record<CurrencyCode, string> | null;
};

/** Correct or void a movement recorded by hand; both keep the original and say why. */
export function JournalEntryRowActions({
    entry,
    accounts,
    window,
    available,
}: JournalEntryRowActionsProps) {
    const [isCorrecting, setIsCorrecting] = useState(false);
    const [isVoiding, setIsVoiding] = useState(false);
    const form = useForm({ reason: '' });
    const kind = entry.origin_purpose === 'income' ? 'income' : 'expense';
    // The server also reports problems of the entry itself under `entry`.
    const errors: Partial<Record<string, string>> = form.errors;

    if (!entry.can_void && !entry.can_correct) {
        return null;
    }

    const actions: RowAction[] = [];

    if (entry.can_correct) {
        actions.push({
            label: 'Corregir',
            icon: Pencil,
            onSelect: () => setIsCorrecting(true),
        });
    }

    if (entry.can_void) {
        actions.push({
            label: 'Anular',
            icon: Ban,
            destructive: true,
            onSelect: () => setIsVoiding(true),
        });
    }

    function handleVoid(event: FormEvent) {
        event.preventDefault();
        form.submit(cancel(entry.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setIsVoiding(false);
            },
        });
    }

    return (
        <>
            <DataTableRowActions label={entry.description} actions={actions} />
            {entry.can_correct && (
                <MovementDialog
                    key={entry.id}
                    kind={kind}
                    accounts={accounts[kind]}
                    window={window}
                    available={available}
                    entry={entry}
                    open={isCorrecting}
                    onOpenChange={setIsCorrecting}
                />
            )}
            <Dialog open={isVoiding} onOpenChange={setIsVoiding}>
                <DialogContent>
                    <form onSubmit={handleVoid} className="flex flex-col gap-5">
                        <div className="flex flex-col gap-2">
                            <DialogTitle>
                                ¿Anular «{entry.description}»?
                            </DialogTitle>
                            <DialogDescription>
                                Se registra un contraasiento con el motivo. El
                                movimiento original queda en el libro.
                            </DialogDescription>
                        </div>
                        <TextField
                            name="reason"
                            label="Motivo"
                            autoComplete="off"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            error={errors.reason ?? errors.entry}
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
                                Anular
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
