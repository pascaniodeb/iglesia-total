import { formatMonth } from '@/lib/month';
import type { ReportMonth } from '@/features/accounting/types';

/**
 * Says that a report is a draft, and why: the months of its span that are still open can still
 * receive movements, so its figures can change until they close.
 */
export function DraftNotice({ months }: { months: ReportMonth[] }) {
    const open = months.filter((month) => !month.is_closed);

    if (open.length === 0) {
        return null;
    }

    return (
        <p
            role="status"
            className="rounded-2xl border-2 border-warning/40 bg-warning/10 px-4 py-3 text-sm"
        >
            <span className="font-black tracking-[0.15em] text-warning uppercase">
                Borrador
            </span>{' '}
            · Sin cerrar:{' '}
            {open.map((month) => formatMonth(month.month)).join(', ')}. Las
            cifras pueden cambiar hasta el cierre contable.
        </p>
    );
}
