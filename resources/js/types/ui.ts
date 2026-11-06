import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

/** Meaning of a toast; it picks the color token (see `.app-toast` in app.css). */
export type ToastType = 'success' | 'info' | 'warning' | 'error';

/** Toast flashed by the server after an operation. `title` and `duration` are optional. */
export type FlashToast = {
    type: ToastType;
    message: string;
    title?: string;
    /** Milliseconds on screen. */
    duration?: number;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
    /** Replaces the app name in the side panel (e.g. the code of an error). */
    hero?: ReactNode;
    /** Short line above the title. */
    eyebrow?: ReactNode;
};
