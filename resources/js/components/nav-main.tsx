import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { usePermissions } from '@/hooks/use-permissions';
import type { NavItem } from '@/types';

export function NavMain({
    label = 'Menú',
    items,
}: {
    label?: string;
    items: NavItem[];
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const closeMobileNavigation = useMobileNavigation();
    const { can } = usePermissions();

    const visibleItems = items.filter(
        (item) => item.permission === undefined || can(item.permission),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {visibleItems.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link
                                href={item.href}
                                prefetch
                                onBefore={closeMobileNavigation}
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
