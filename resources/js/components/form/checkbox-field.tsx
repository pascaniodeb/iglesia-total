import { useId } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

type CheckboxFieldProps = {
    name: string;
    label: string;
    defaultChecked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
    description?: string;
    error?: string;
};

/**
 * Checkbox of an Inertia form: when checked it sends the field; when unchecked it does not, and
 * the server takes it as false.
 */
export function CheckboxField({
    name,
    label,
    defaultChecked,
    onCheckedChange,
    description,
    error,
}: CheckboxFieldProps) {
    const id = useId();

    return (
        <div className="flex flex-col gap-2">
            <div className="flex min-h-(--control-height) items-center gap-3 rounded-(--control-radius) border-2 border-border bg-card px-(--control-px)">
                <Checkbox
                    id={id}
                    name={name}
                    value="1"
                    defaultChecked={defaultChecked}
                    onCheckedChange={(checked) =>
                        onCheckedChange?.(checked === true)
                    }
                    aria-invalid={error !== undefined}
                />
                <Label htmlFor={id} className="text-(length:--control-font)">
                    {label}
                </Label>
            </div>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
            <InputError message={error} />
        </div>
    );
}
