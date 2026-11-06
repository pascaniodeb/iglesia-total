import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Switches between light and dark mode from the header (the "System" mode stays in
 * Settings > Appearance).
 *
 * Both icons are always rendered and CSS shows the right one through the `dark` class, which the
 * HTML already carries before React loads: the server does not know the device theme, so this
 * avoids flicker and hydration mismatches.
 */
export function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="shrink-0 rounded-lg"
            aria-label="Cambiar entre modo claro y oscuro"
            title="Cambiar entre modo claro y oscuro"
            onClick={() =>
                updateAppearance(
                    resolvedAppearance === 'dark' ? 'light' : 'dark',
                )
            }
        >
            <Moon aria-hidden className="dark:hidden" />
            <Sun aria-hidden className="hidden dark:block" />
        </Button>
    );
}
