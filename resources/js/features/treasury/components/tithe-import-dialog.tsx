import { SpreadsheetImportDialog } from '@/components/spreadsheet-import-dialog';
import {
    preview as previewRoute,
    store,
    template,
} from '@/routes/treasury/tithes/import';

/** Bulk import of tithes from the Excel template: one currency per row. */
export function TitheImportDialog({
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
            noun={{ plural: 'diezmos', distinct: 'diezmos distintos' }}
            routes={{
                preview: previewRoute().url,
                store,
                template: template().url,
            }}
        />
    );
}
