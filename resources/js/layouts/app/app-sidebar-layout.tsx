import { AppContent } from '@/components/app-content';
import { AppFooter } from '@/components/app-footer';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ScrollToTop } from '@/components/scroll-to-top';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/* --app-header: height of the fixed header, so anything pinned below is not covered. */}
            <AppContent
                variant="sidebar"
                className="min-w-0 overflow-x-clip [--app-header:4rem] group-has-data-[collapsible=icon]/sidebar-wrapper:[--app-header:3rem]"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
                {/* Room for the floating button: it never covers the footer text. */}
                <AppFooter className="mt-auto shrink-0 border-t border-sidebar-border/50 pb-16 sm:pr-20 sm:pb-5" />
            </AppContent>
            <ScrollToTop />
        </AppShell>
    );
}
