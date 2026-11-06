import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type FormActionsProps = {
    submitLabel: string;
    processing: boolean;
    /** destructive: the action deactivates or deletes. */
    variant?: 'default' | 'destructive';
    onCancel?: () => void;
    /** Submitting is not allowed yet, without showing a busy state. */
    disabled?: boolean;
};

export function FormActions({
    submitLabel,
    processing,
    variant = 'default',
    onCancel,
    disabled = false,
}: FormActionsProps) {
    return (
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            {onCancel && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={processing}
                >
                    Cancelar
                </Button>
            )}
            <Button
                type="submit"
                variant={variant}
                disabled={processing || disabled}
            >
                {processing && <Spinner />}
                {submitLabel}
            </Button>
        </div>
    );
}
