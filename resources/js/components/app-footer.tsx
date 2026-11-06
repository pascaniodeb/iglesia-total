import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export function AppFooter({ className }: { className?: string }) {
    const { name } = usePage().props;

    return (
        <footer
            data-app-footer
            className={cn(
                'flex flex-col items-center gap-1 px-4 py-5 text-center text-xs text-muted-foreground sm:flex-row sm:justify-between sm:gap-4 sm:text-left',
                className,
            )}
        >
            <p>
                © {new Date().getFullYear()} {name}. Todos los derechos
                reservados.
            </p>
        </footer>
    );
}
