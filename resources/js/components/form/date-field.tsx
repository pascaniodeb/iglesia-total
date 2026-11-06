import { Field } from '@/components/form/field';
import { Input } from '@/components/ui/input';

type DateFieldProps = {
    name: string;
    label: string;
    /** YYYY-MM-DD, or '' when there is no date. */
    value: string;
    onValueChange: (value: string) => void;
    error?: string;
    description?: string;
    /** Earliest and latest selectable date (YYYY-MM-DD). */
    min?: string;
    max?: string;
    disabled?: boolean;
};

/**
 * Labelled date field. It uses the native date input: the phone opens its own picker, the
 * keyboard works as expected and the value is already the YYYY-MM-DD the server validates.
 */
export function DateField({
    name,
    label,
    value,
    onValueChange,
    error,
    description,
    min,
    max,
    disabled,
}: DateFieldProps) {
    return (
        <Field label={label} error={error} description={description}>
            {(control) => (
                <Input
                    {...control}
                    type="date"
                    name={name}
                    value={value}
                    min={min}
                    max={max}
                    disabled={disabled}
                    onChange={(event) => onValueChange(event.target.value)}
                    // min-w-0: some mobile browsers give the date input an intrinsic width.
                    className="min-w-0"
                />
            )}
        </Field>
    );
}
