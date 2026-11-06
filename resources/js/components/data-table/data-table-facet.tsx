import type { DataTableFacet as Facet } from '@/components/data-table/types';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ALL } from '@/hooks/use-table-query';

type DataTableFacetProps = {
    facet: Facet;
    value: string;
    onChange: (value: string) => void;
    /** To link it with its visible label. */
    id?: string;
};

export function DataTableFacet({
    facet,
    value,
    onChange,
    id,
}: DataTableFacetProps) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger
                id={id}
                aria-label={facet.label}
                className="w-full min-w-0 rounded-(--control-radius) border-2 px-(--control-px) text-(length:--control-font) data-[size=default]:h-(--control-height)"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{facet.allLabel}</SelectItem>
                {facet.options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
