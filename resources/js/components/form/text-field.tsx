import type { ComponentProps } from 'react';
import { Field } from '@/components/form/field';
import { Input } from '@/components/ui/input';

type TextFieldProps = Omit<ComponentProps<typeof Input>, 'id'> & {
    name: string;
    label: string;
    error?: string;
    description?: string;
};

/** Labelled text input wired for accessibility (label, help text and error are linked). */
export function TextField({
    label,
    error,
    description,
    ...input
}: TextFieldProps) {
    return (
        <Field label={label} error={error} description={description}>
            {(control) => <Input {...input} {...control} />}
        </Field>
    );
}
