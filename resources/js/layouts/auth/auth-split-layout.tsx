import { Link, usePage } from '@inertiajs/react';
import { AppFooter } from '@/components/app-footer';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
    hero,
    eyebrow,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="grid min-h-dvh grid-cols-1 lg:grid-cols-2">
            <aside className="relative hidden flex-col overflow-hidden bg-sidebar p-12 text-sidebar-foreground lg:flex">
                <Link
                    href={home()}
                    className="flex items-center gap-3 text-lg font-semibold"
                >
                    <AppLogoIcon className="size-9 text-sidebar-primary" />
                    {name}
                </Link>

                <div className="my-auto">
                    {hero ?? (
                        <p className="heading-hero">
                            {name}
                            <span className="text-sidebar-primary">.</span>
                        </p>
                    )}
                </div>
            </aside>

            <div className="flex min-w-0 flex-col">
                <main className="flex min-w-0 flex-1 items-center justify-center px-6 py-12 sm:px-12">
                    <div className="flex w-full max-w-md flex-col gap-8">
                        <Link href={home()} className="text-primary lg:hidden">
                            <AppLogoIcon className="size-12" />
                        </Link>

                        <header className="flex flex-col gap-4">
                            {eyebrow}
                            <h1 className="auth-display">{title}</h1>
                            {description && (
                                <p className="text-lg text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </header>

                        {children}
                    </div>
                </main>
                <AppFooter className="sm:px-12" />
            </div>
        </div>
    );
}
