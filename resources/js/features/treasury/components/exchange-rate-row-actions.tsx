import { PenLine } from 'lucide-react';
import { useState } from 'react';
import { DataTableRowActions } from '@/components/data-table/data-table-row-actions';
import { ManualRateDialog } from '@/features/treasury/components/manual-rate-dialog';
import { formatShortDate } from '@/features/treasury/rates';
import type { ExchangeRateRow } from '@/features/treasury/types';

/** Correct by hand the rates of a day (from then on the sync does not change them). */
export function ExchangeRateRowActions({
    rate,
    today,
}: {
    rate: ExchangeRateRow;
    today: string;
}) {
    const [isCorrecting, setIsCorrecting] = useState(false);

    return (
        <>
            <DataTableRowActions
                label={formatShortDate(rate.date)}
                actions={[
                    {
                        label: 'Corregir',
                        icon: PenLine,
                        onSelect: () => setIsCorrecting(true),
                    },
                ]}
            />
            <ManualRateDialog
                key={`${rate.id}-${rate.usd_rate}-${rate.cop_rate}`}
                rate={rate}
                today={today}
                open={isCorrecting}
                onOpenChange={setIsCorrecting}
            />
        </>
    );
}
