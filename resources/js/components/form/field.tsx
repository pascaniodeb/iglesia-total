import { useId } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

/** Ids that link the control to its help text and error (aria-describedby). */
export type FieldControlProps = {
    id: string;
    'aria-invalid': boolean;
    'aria-describedby': string | undefined;
};

type FieldProps = {
    label: string;
    error?: string;
    description?: string;
    /** Receives the accessibility attributes the control must apply. */
    children: (control: FieldControlProps) => ReactNode;
};

/** Form field: label, control, help text and error. Base of every form field. */
export function Field({ label, error, description, children }: FieldProps) {
    const id = useId();
    const descriptionId = description ? `${id}-description` : undefined;
    const errorId = error ? `${id}-error` : undefined;

    return (
        // min-w-0: in a grid or row, the field shrinks to its column instead of overflowing it.
        <div className="flex min-w-0 flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children({
                id,
                'aria-invalid': error !== undefined,
                'aria-describedby':
                    [descriptionId, errorId].filter(Boolean).join(' ') ||
                    undefined,
            })}
            {description && (
                <p id={descriptionId} className="text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            <InputError id={errorId} message={error} />
        </div>
    );
}
