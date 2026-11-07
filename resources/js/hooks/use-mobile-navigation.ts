import { useCallback } from 'react';
import { useSidebar } from '@/components/ui/sidebar';

export type CleanupFn = () => void;

export function useMobileNavigation(): CleanupFn {
    const { isMobile, setOpenMobile } = useSidebar();

    return useCallback(() => {
        if (isMobile) {
            setOpenMobile(false);
        }

        // Remove pointer-events style from body...
        document.body.style.removeProperty('pointer-events');
    }, [isMobile, setOpenMobile]);
}
