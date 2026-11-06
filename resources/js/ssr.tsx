import { createInertiaApp } from '@inertiajs/react';
import { resolveLayout, resolveTitle, withProviders } from '@/lib/inertia-app';

// The Inertia Vite plugin wraps this call with the SSR server bootstrap
// (dev endpoint with HMR, standalone Node server in production).
void createInertiaApp({
    title: resolveTitle,
    layout: resolveLayout,
    withApp: withProviders,
});
