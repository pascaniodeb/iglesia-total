import { router } from '@inertiajs/react';
import { Ban, Eye } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import type { RowAction } from '@/components/data-table/data-table-row-actions';
import { ReceiptVoidDialog } from '@/features/treasury/components/receipt-void-dialog';
import type { ReceiptRow } from '@/features/treasury/types';
import { show } from '@/routes/treasury/receipts';

type ReceiptRowActionsProps = {
    receipt: ReceiptRow;
    canAdminister: boolean;
};

/** Open a receipt; void it (only who administers receipts, and only while it is issued). */
export function ReceiptRowActions({
    receipt,
    canAdminister,
}: ReceiptRowActionsProps) {
    const [isVoiding, setIsVoiding] = useState(false);

    const actions: RowAction[] = [
        {
            label: 'Ver comprobante',
            icon: Eye,
            onSelect: () => router.visit(show(receipt.number)),
        },
    ];

    if (canAdminister && !receipt.is_void) {
        actions.push({
            label: 'Anular',
            icon: Ban,
            destructive: true,
            onSelect: () => setIsVoiding(true),
        });
    }

    return (
        <>
            <DataTableRowActions
                label={`Comprobante ${receipt.number_label}`}
                actions={actions}
            />
            {canAdminister && !receipt.is_void && (
                <ReceiptVoidDialog
                    number={receipt.number}
                    numberLabel={receipt.number_label}
                    open={isVoiding}
                    onOpenChange={setIsVoiding}
                />
            )}
        </>
    );
}
