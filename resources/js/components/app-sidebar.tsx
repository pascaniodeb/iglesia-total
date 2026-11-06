import { Link } from '@inertiajs/react';
import { BookText, History, LayoutGrid, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { dashboard } from '@/routes';
import { index as accountsIndex } from '@/routes/accounting/accounts';
import { index as activityLogIndex } from '@/routes/activity-log';
import { index as membersIndex } from '@/routes/members';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Panel',
        href: dashboard(),
        icon: LayoutGrid,
        permission: 'dashboard.view',
    },
    {
        title: 'Miembros',
        href: membersIndex(),
        icon: Users,
        permission: 'members.view',
    },
    {
        title: 'Plan de cuentas',
        href: accountsIndex(),
        icon: BookText,
        permission: 'catalogs.view',
    },
    {
        title: 'Bitácora',
        href: activityLogIndex(),
        icon: History,
        permission: 'activity_log.view',
    },
];

export function AppSidebar() {
    const closeMobileNavigation = useMobileNavigation();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={dashboard()}
                                prefetch
                                onBefore={closeMobileNavigation}
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
