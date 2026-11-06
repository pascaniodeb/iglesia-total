import { Breadcrumbs } from '@/components/breadcrumbs';
import { ThemeToggle } from '@/components/theme-toggle';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="sticky top-0 z-30 flex h-(--app-header) shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background/75 px-6 backdrop-blur-md transition-[width,height] ease-linear supports-backdrop-filter:bg-background/60 md:rounded-t-xl md:px-4">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <div className="min-w-0 flex-1 overflow-hidden [overflow-wrap:anywhere]">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-1">
                <ThemeToggle />
            </div>
        </header>
    );
}
