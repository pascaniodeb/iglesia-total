import type { FacetOption } from '@/components/data-table/types';
import { Field } from '@/components/form/field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/** Radix Select does not accept '' as a value, so "nothing chosen" travels as this sentinel. */
const NONE = '__none__';

type SelectFieldProps = {
    name: string;
    label: string;
    options: FacetOption[];
    /** '' means nothing chosen. */
    value: string;
    onValueChange: (value: string) => void;
    placeholder?: string;
    /** Adds an option that clears the choice. */
    clearLabel?: string;
    error?: string;
    description?: string;
    disabled?: boolean;
};

/** Labelled select wired for accessibility, controlled, with an optional "clear" choice. */
export function SelectField({
    name,
    label,
    options,
    value,
    onValueChange,
    placeholder = 'Seleccione',
    clearLabel,
    error,
    description,
    disabled,
}: SelectFieldProps) {
    return (
        <Field label={label} error={error} description={description}>
            {(control) => (
                <Select
                    name={name}
                    value={value === '' ? undefined : value}
                    onValueChange={(next) =>
                        onValueChange(next === NONE ? '' : next)
                    }
                    disabled={disabled}
                >
                    <SelectTrigger
                        {...control}
                        className="w-full min-w-0 rounded-(--control-radius) border-2 bg-card px-(--control-px) text-(length:--control-font) data-[placeholder]:text-sm data-[size=default]:h-(--control-height)"
                    >
                        <SelectValue placeholder={placeholder} />
                    </SelectTrigger>
                    <SelectContent>
                        {clearLabel && value !== '' && (
                            <SelectItem
                                value={NONE}
                                className="text-muted-foreground"
                            >
                                {clearLabel}
                            </SelectItem>
                        )}
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </Field>
    );
}
