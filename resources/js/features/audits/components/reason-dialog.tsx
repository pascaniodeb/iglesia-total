import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { TextareaField } from '@/components/form/textarea-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';

type ReasonDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    field: string;
    label: string;
    help: string;
    submitLabel: string;
    destructive?: boolean;
    required?: boolean;
    extra?: Record<string, string>;
    url: string;
};

/**
 * One reason, written by a person, sent to the server: the shape of every act of the audit (run it
 * with notes, accept a finding, lift an acceptance).
 */
export function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    field,
    label,
    help,
    submitLabel,
    destructive = false,
    required = true,
    extra = {},
    url,
}: ReasonDialogProps) {
    const form = useForm<Record<string, string>>({ [field]: '', ...extra });
    const errors: Partial<Record<string, string>> = form.errors;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        form.post(url, {
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
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </div>
                    <TextareaField
                        name={field}
                        label={label}
                        description={help}
                        value={form.data[field] ?? ''}
                        onChange={(event) =>
                            form.setData(field, event.target.value)
                        }
                        error={
                            errors[field] ??
                            errors.fingerprint ??
                            errors.acknowledgement
                        }
                        required={required}
                    />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={form.processing}
                        >
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
