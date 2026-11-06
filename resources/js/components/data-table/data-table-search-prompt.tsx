import { Search } from 'lucide-react';

/** Initial state of a search tool: nothing to show yet. */
export function DataTableSearchPrompt({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="flex flex-col items-center gap-4 px-6 py-16 text-center">
            <span className="flex size-14 items-center justify-center rounded-full bg-primary/10">
                <Search aria-hidden className="size-6 text-primary" />
            </span>
            <div className="flex flex-col gap-1">
                <p className="heading-title">{title}</p>
                <p className="text-muted-foreground">{description}</p>
            </div>
        </div>
    );
}
