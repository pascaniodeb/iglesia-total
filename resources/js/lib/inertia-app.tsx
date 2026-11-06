import type { ComponentType, ReactElement, ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

type PageLayout = ComponentType<{ children: ReactNode }>;

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/** Document title shared by the browser and SSR entries. */
export function resolveTitle(title: string): string {
    return title ? `${title} - ${appName}` : appName;
}

/** Persistent layout for each page, resolved from its component name. */
export function resolveLayout(name: string): PageLayout | PageLayout[] | null {
    if (name === 'welcome') {
        return null;
    }

    if (name.startsWith('auth/')) {
        return AuthLayout;
    }

    if (name.startsWith('settings/')) {
        return [AppLayout, SettingsLayout];
    }

    return AppLayout;
}

/** Global providers wrapped around the Inertia app, on the server and in the browser. */
export function withProviders(app: ReactElement): ReactElement {
    return (
        <TooltipProvider delayDuration={0}>
            {app}
            <Toaster />
        </TooltipProvider>
    );
}
