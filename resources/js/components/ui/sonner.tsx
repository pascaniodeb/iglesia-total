import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/** Distance to the screen edges: matches the page margin. */
const OFFSET = 16;

/**
 * Toast container: top right; on phones, full width with the same margin. The look of each
 * toast comes from AppToast.
 */
function Toaster(props: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            position="top-right"
            offset={OFFSET}
            mobileOffset={OFFSET}
            gap={12}
            visibleToasts={4}
            containerAriaLabel="Notificaciones"
            {...props}
        />
    );
}

export { Toaster };
