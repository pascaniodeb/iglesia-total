import { useForm, useHttp } from '@inertiajs/react';
import { CircleAlert, Download, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { OfferingImportPreview } from '@/features/treasury/types';
import { formatAmount } from '@/lib/money';
import {
    preview as previewRoute,
    store,
    template,
} from '@/routes/treasury/offerings/import';

type OfferingImportDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type ImportData = { file: File | null; confirm_duplicates: boolean };

/**
 * Bulk import from the Excel template. First the server reads the workbook and says what it
 * would write (nothing is written); only a workbook without errors can be imported, all at
 * once. Exact duplicate rows must be confirmed.
 */
export function OfferingImportDialog({
    open,
    onOpenChange,
}: OfferingImportDialogProps) {
    const http = useHttp<{ file: File | null }>({ file: null });
    const form = useForm<ImportData>({ file: null, confirm_duplicates: false });
    const [result, setResult] = useState<OfferingImportPreview | null>(null);
    const [hasFailed, setHasFailed] = useState(false);

    function chooseFile(file: File | null) {
        http.setData('file', file);
        form.setData({ file, confirm_duplicates: false });
        form.clearErrors();
        setResult(null);
        setHasFailed(false);
    }

    function review() {
        setHasFailed(false);
        // A rejected file comes back as validation errors (shown on the field); anything else
        // (network, server) is said plainly instead of leaving the dialog silent.
        http.post(previewRoute().url, {
            onSuccess: (data) => setResult(data as OfferingImportPreview),
        }).catch(() => setHasFailed(true));
    }

    function importWorkbook() {
        form.submit(store(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => close(false),
        });
    }

    function close(next: boolean) {
        if (!next) {
            chooseFile(null);
        }
        onOpenChange(next);
    }

    const needsConfirmation = (result?.duplicate_lines.length ?? 0) > 0;
    const canImport =
        result?.can_import === true &&
        (!needsConfirmation || form.data.confirm_duplicates);

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="flex max-h-[90dvh] flex-col gap-5 sm:max-w-2xl">
                <div className="flex flex-col gap-2 pr-6">
                    <DialogTitle>Importar ofrendas desde Excel</DialogTitle>
                    <DialogDescription>
                        Use la plantilla. Primero se revisa el archivo; solo se
                        importa si ninguna fila tiene errores, y se importa todo
                        junto.
                    </DialogDescription>
                </div>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div className="min-w-0 flex-1">
                        <Field
                            label="Archivo (.xlsx)"
                            error={http.errors.file ?? form.errors.file}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="file"
                                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    onChange={(event) =>
                                        chooseFile(
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            )}
                        </Field>
                    </div>
                    <Button asChild variant="outline" className="shrink-0">
                        <a href={template().url}>
                            <Download aria-hidden />
                            Plantilla
                        </a>
                    </Button>
                </div>

                {hasFailed && !http.errors.file && (
                    <p
                        role="alert"
                        className="text-sm font-semibold text-destructive-foreground"
                    >
                        No se pudo revisar el archivo. Inténtelo de nuevo.
                    </p>
                )}

                {result && (
                    <div className="-mx-6 flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6">
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Summary
                                label="Ofrendas"
                                value={String(result.rows.length)}
                            />
                            <Summary
                                label="Bolívares"
                                value={formatAmount(result.totals.ves)}
                            />
                            <Summary
                                label="Dólares"
                                value={formatAmount(result.totals.usd)}
                            />
                            <Summary
                                label="Pesos"
                                value={formatAmount(result.totals.cop)}
                            />
                        </dl>

                        {result.rejected.length > 0 && (
                            <section
                                aria-labelledby="import-errors"
                                className="rounded-2xl border-2 border-destructive-foreground/30 p-4"
                            >
                                <h3
                                    id="import-errors"
                                    className="mb-2 flex items-center gap-2 font-semibold text-destructive-foreground"
                                >
                                    <CircleAlert
                                        aria-hidden
                                        className="size-4"
                                    />
                                    {result.rejected.length === 1
                                        ? 'Una fila tiene un error. Corríjala y vuelva a revisar.'
                                        : `${result.rejected.length} filas tienen errores. Corríjalas y vuelva a revisar.`}
                                </h3>
                                <ul className="flex flex-col gap-1 text-sm">
                                    {result.rejected.map((rejection) => (
                                        <li key={rejection.line}>
                                            <span className="font-semibold tabular-nums">
                                                Fila {rejection.line}:
                                            </span>{' '}
                                            {rejection.reason}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {result.can_import && needsConfirmation && (
                            <label className="flex items-start gap-3 rounded-2xl bg-warning/10 p-4 text-sm">
                                <Checkbox
                                    checked={form.data.confirm_duplicates}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'confirm_duplicates',
                                            checked === true,
                                        )
                                    }
                                />
                                <span>
                                    Las filas{' '}
                                    {result.duplicate_lines.join(', ')} repiten
                                    exactamente otra fila. Confirmo que son
                                    ofrendas distintas.
                                </span>
                            </label>
                        )}

                        {result.can_import && (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <FileSpreadsheet
                                    aria-hidden
                                    className="size-4"
                                />
                                El archivo está listo: se importarán{' '}
                                {result.rows.length} ofrendas.
                            </p>
                        )}
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancelar
                        </Button>
                    </DialogClose>
                    {result?.can_import ? (
                        <Button
                            type="button"
                            disabled={!canImport || form.processing}
                            onClick={importWorkbook}
                        >
                            {form.processing ? 'Importando…' : 'Importar'}
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={!http.data.file || http.processing}
                            onClick={review}
                        >
                            {http.processing ? 'Revisando…' : 'Revisar archivo'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5 rounded-2xl bg-muted/60 px-4 py-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-black tabular-nums">{value}</dd>
        </div>
    );
}
