import type { DataTableColumn } from '@/components/data-table/types';
import type { BlockRow, BlockSortKey } from '@/features/integrity/types';
import { formatDateTime } from '@/lib/date';

const KIND_LABEL = {
    genesis: 'Génesis',
    weekly: 'Semana',
    monthly: 'Mes',
} as const;

/** The first and last characters of a hash: enough to recognise it, the whole one is a click away. */
export function shortHash(hash: string): string {
    return `${hash.slice(0, 10)}…${hash.slice(-6)}`;
}

/** Columns of the sealed blocks. On narrow containers each block is a card titled by its height. */
export const blockColumns: DataTableColumn<BlockRow, BlockSortKey>[] = [
    {
        key: 'height',
        header: 'Bloque',
        sortKey: 'height',
        mobile: 'primary',
        cell: (block) => (
            <span className="font-semibold tabular-nums">#{block.height}</span>
        ),
    },
    {
        key: 'kind',
        header: 'Tipo',
        mobile: 'badge',
        cell: (block) => (
            <span className="inline-flex rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold whitespace-nowrap text-primary">
                {KIND_LABEL[block.kind]}
            </span>
        ),
    },
    {
        key: 'period',
        header: 'Período',
        cell: (block) => (
            <span className="tabular-nums">{block.period_key ?? '—'}</span>
        ),
    },
    {
        key: 'leaves',
        header: 'Registros sellados',
        align: 'end',
        priority: 'secondary',
        cell: (block) => (
            <span className="tabular-nums">{block.leaves_count}</span>
        ),
    },
    {
        key: 'hash',
        header: 'Huella',
        priority: 'secondary',
        cell: (block) => (
            <span className="font-mono text-xs" title={block.hash}>
                {shortHash(block.hash)}
            </span>
        ),
    },
    {
        key: 'sealed',
        header: 'Sellado',
        priority: 'tertiary',
        cell: (block) => (
            <span className="whitespace-nowrap">
                {formatDateTime(block.sealed_at)}
            </span>
        ),
    },
];
