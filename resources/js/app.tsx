import { createInertiaApp } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import { resolveLayout, resolveTitle, withProviders } from '@/lib/inertia-app';

void createInertiaApp({
    title: resolveTitle,
    layout: resolveLayout,
    strictMode: true,
    withApp: withProviders,
    progress: {
        color: '#354898',
    },
});

// This will set light / dark mode on load...
initializeTheme();
