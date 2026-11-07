import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BookText,
    HandCoins,
    History,
    LayoutGrid,
    Lock,
    Users,
    Wallet,
} from 'lucide-react';
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
import { index as exchangeRatesIndex } from '@/routes/treasury/exchange-rates';
import { index as offeringCategoriesIndex } from '@/routes/treasury/offering-categories';
import { index as offeringsIndex } from '@/routes/treasury/offerings';
import { index as weeklyClosuresIndex } from '@/routes/treasury/weekly-closures';
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
        title: 'Ofrendas',
        href: offeringsIndex(),
        icon: Wallet,
        permission: 'offerings.view',
    },
    {
        title: 'Cierres semanales',
        href: weeklyClosuresIndex(),
        icon: Lock,
        permission: 'closures.view',
    },
    {
        title: 'Plan de cuentas',
        href: accountsIndex(),
        icon: BookText,
        permission: 'catalogs.view',
    },
    {
        title: 'Categorías de ofrenda',
        href: offeringCategoriesIndex(),
        icon: HandCoins,
        permission: 'catalogs.view',
    },
    {
        title: 'Tasas de cambio',
        href: exchangeRatesIndex(),
        icon: ArrowLeftRight,
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
