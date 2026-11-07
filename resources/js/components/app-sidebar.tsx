import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BookCheck,
    BookOpen,
    BookText,
    CalendarCheck,
    ChartColumn,
    HandCoins,
    History,
    LayoutGrid,
    Library,
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
import { index as journalIndex } from '@/routes/accounting/journal';
import { index as ledgerIndex } from '@/routes/accounting/ledger';
import { index as periodsIndex } from '@/routes/accounting/periods';
import { index as reportsIndex } from '@/routes/accounting/reports';
import { index as activityLogIndex } from '@/routes/activity-log';
import { index as exchangeRatesIndex } from '@/routes/treasury/exchange-rates';
import { index as offeringCategoriesIndex } from '@/routes/treasury/offering-categories';
import { index as monthlyOfferingClosuresIndex } from '@/routes/treasury/monthly-offering-closures';
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
        title: 'Cierre de ofrendas del mes',
        href: monthlyOfferingClosuresIndex(),
        icon: BookCheck,
        permission: 'closures.view',
    },
    {
        title: 'Libro Diario',
        href: journalIndex(),
        icon: BookOpen,
        permission: 'accounting.view',
    },
    {
        title: 'Cierre contable',
        href: periodsIndex(),
        icon: CalendarCheck,
        permission: 'accounting.view',
    },
    {
        title: 'Libro Mayor',
        href: ledgerIndex(),
        icon: Library,
        permission: 'accounting.view',
    },
    {
        title: 'Reporte contable',
        href: reportsIndex(),
        icon: ChartColumn,
        permission: 'accounting.view',
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
