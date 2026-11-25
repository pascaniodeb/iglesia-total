import { SpreadsheetImportDialog } from '@/components/spreadsheet-import-dialog';
import {
    preview as previewRoute,
    store,
    template,
} from '@/routes/treasury/offerings/import';

/** Bulk import of offerings from the Excel template. */
export function OfferingImportDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <SpreadsheetImportDialog
            open={open}
            onOpenChange={onOpenChange}
            noun={{ plural: 'ofrendas', distinct: 'ofrendas distintas' }}
            routes={{
                preview: previewRoute().url,
                store,
                template: template().url,
            }}
        />
    );
}
