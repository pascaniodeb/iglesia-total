import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            {/* Wrapped: SidebarMenuButton forces every direct child <svg> to 16 px. */}
            <span className="flex size-8 shrink-0 items-center justify-center">
                <AppLogoIcon className="size-8 text-sidebar-primary" />
            </span>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    {name}
                </span>
            </div>
        </>
    );
}
