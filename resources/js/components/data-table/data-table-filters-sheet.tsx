import { SlidersHorizontal } from 'lucide-react';
import { DataTableFacet } from '@/components/data-table/data-table-facet';
import type { DataTableFacet as Facet } from '@/components/data-table/types';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { ALL } from '@/hooks/use-table-query';
import { cn } from '@/lib/utils';

type DataTableFiltersSheetProps = {
    facets: Facet[];
    values: Record<string, string>;
    activeCount: number;
    onChange: (key: string, value: string) => void;
    onReset: () => void;
};

/**
 * Filters in a panel (side sheet on desktop, bottom sheet on mobile): fixed header and footer with
 * the filters scrolling on their own, so none gets squashed however many there are. Results
 * update as you choose; the footer clears the filters or closes the panel.
 */
export function DataTableFiltersSheet({
    facets,
    values,
    activeCount,
    onChange,
    onReset,
}: DataTableFiltersSheetProps) {
    const isMobile = useIsMobile();

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    variant="outline"
                    className="relative size-14 shrink-0 rounded-(--control-radius) border-2 px-0"
                    aria-label={
                        activeCount > 0
                            ? `Filtros (${activeCount} activos)`
                            : 'Filtros'
                    }
                >
                    <SlidersHorizontal aria-hidden className="size-5" />
                    {activeCount > 0 && (
                        <span
                            aria-hidden
                            className="absolute -top-1 -right-1 flex size-6 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground"
                        >
                            {activeCount}
                        </span>
                    )}
                </Button>
            </SheetTrigger>
            <SheetContent
                side={isMobile ? 'bottom' : 'right'}
                className={cn(
                    'gap-0',
                    isMobile
                        ? 'max-h-[92dvh] rounded-t-3xl'
                        : 'w-full sm:max-w-md',
                )}
            >
                <SheetHeader className="shrink-0 border-b border-border px-6 py-5">
                    <SheetTitle className="text-2xl leading-tight font-black tracking-tight">
                        Filtros
                    </SheetTitle>
                    <SheetDescription>
                        Los resultados se actualizan al elegir.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-6 py-5">
                    {facets.map((facet) => {
                        const id = `filter-${facet.key}`;

                        return (
                            <div
                                key={facet.key}
                                className="flex min-w-0 flex-col gap-2"
                            >
                                <Label htmlFor={id}>{facet.label}</Label>
                                <DataTableFacet
                                    id={id}
                                    facet={facet}
                                    value={values[facet.key] ?? ALL}
                                    onChange={(value) =>
                                        onChange(facet.key, value)
                                    }
                                />
                            </div>
                        );
                    })}
                </div>
                <SheetFooter className="shrink-0 flex-row gap-3 border-t border-border px-6 py-4">
                    <Button
                        variant="outline"
                        className="flex-1"
                        onClick={onReset}
                        disabled={activeCount === 0}
                    >
                        Limpiar filtros
                    </Button>
                    <SheetClose asChild>
                        <Button className="flex-1">Ver resultados</Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
