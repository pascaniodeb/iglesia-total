import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Paginated } from '@/types';
import { PER_PAGE_OPTIONS } from '@/types/table';

type DataTablePaginationProps = {
    meta: Omit<Paginated<unknown>, 'data'>;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
};

const number = new Intl.NumberFormat('es-VE');

export function DataTablePagination({
    meta,
    onPage,
    onPerPage,
}: DataTablePaginationProps) {
    const { current_page: page, from, to, total } = meta;
    const lastPage = Math.max(meta.last_page, 1);

    return (
        <nav
            aria-label="Paginación"
            className="flex flex-col gap-3 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <p aria-live="polite">
                {total === 0
                    ? 'Sin resultados'
                    : `${number.format(from ?? 0)}–${number.format(to ?? 0)} de ${number.format(total)}`}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={String(meta.per_page)}
                    onValueChange={(value) => onPerPage(Number(value))}
                >
                    <SelectTrigger
                        aria-label="Filas por página"
                        className="h-9 w-28 rounded-lg"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PER_PAGE_OPTIONS.map((option) => (
                            <SelectItem key={option} value={String(option)}>
                                {option} filas
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="flex items-center gap-1">
                    <PageButton
                        icon={ChevronsLeft}
                        label="Primera página"
                        disabled={page <= 1}
                        onClick={() => onPage(1)}
                    />
                    <PageButton
                        icon={ChevronLeft}
                        label="Página anterior"
                        disabled={page <= 1}
                        onClick={() => onPage(page - 1)}
                    />
                    <span className="min-w-16 text-center tabular-nums">
                        {page} / {lastPage}
                    </span>
                    <PageButton
                        icon={ChevronRight}
                        label="Página siguiente"
                        disabled={page >= lastPage}
                        onClick={() => onPage(page + 1)}
                    />
                    <PageButton
                        icon={ChevronsRight}
                        label="Última página"
                        disabled={page >= lastPage}
                        onClick={() => onPage(lastPage)}
                    />
                </div>
            </div>
        </nav>
    );
}

function PageButton({
    icon: Icon,
    label,
    disabled,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            variant="outline"
            size="icon"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="rounded-lg"
        >
            <Icon />
        </Button>
    );
}
