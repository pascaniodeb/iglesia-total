import { Link } from '@inertiajs/react';
import { analysis, index, reports } from '@/routes/treasury/tithes';
import { cn } from '@/lib/utils';

const TABS = [
    { key: 'registry', label: 'Registro', href: () => index() },
    { key: 'reports', label: 'Reportes', href: () => reports() },
    { key: 'analysis', label: 'Análisis', href: () => analysis() },
] as const;

/** Moves between the three views of the tithes: the book, the report of a period and the analysis. */
export function TitheNav({
    current,
}: {
    current: (typeof TABS)[number]['key'];
}) {
    return (
        <nav
            aria-label="Vistas de los diezmos"
            className="flex w-fit max-w-full gap-1 overflow-x-auto rounded-full border-2 border-border bg-card p-1"
        >
            {TABS.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href()}
                    aria-current={tab.key === current ? 'page' : undefined}
                    className={cn(
                        'rounded-full px-4 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors',
                        tab.key === current
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
