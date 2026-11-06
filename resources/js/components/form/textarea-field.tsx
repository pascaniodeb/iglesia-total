import type { ComponentProps } from 'react';
import { Field } from '@/components/form/field';
import { cn } from '@/lib/utils';

type TextareaFieldProps = Omit<ComponentProps<'textarea'>, 'id'> & {
    name: string;
    label: string;
    error?: string;
    description?: string;
};

/** Labelled long-text field, styled like the other form controls. */
export function TextareaField({
    label,
    error,
    description,
    className,
    rows = 4,
    ...textarea
}: TextareaFieldProps) {
    return (
        <Field label={label} error={error} description={description}>
            {(control) => (
                <textarea
                    {...textarea}
                    {...control}
                    rows={rows}
                    className={cn(
                        'w-full min-w-0 rounded-(--control-radius) border-2 border-input bg-card px-(--control-px) py-3 text-(length:--control-font) transition-[color,border-color,box-shadow] outline-none placeholder:text-sm placeholder:text-muted-foreground',
                        'focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-ring/30',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                        className,
                    )}
                />
            )}
        </Field>
    );
}
