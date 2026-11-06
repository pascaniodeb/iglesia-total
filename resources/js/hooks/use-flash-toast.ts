import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { showToast } from '@/components/feedback/app-toast';
import type { FlashToast } from '@/types/ui';

/** Shows the toasts the server leaves in the flash data of each visit. */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            showToast(data);
        });
    }, []);
}
