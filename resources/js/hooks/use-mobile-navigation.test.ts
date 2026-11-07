import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { useSidebar } from '@/components/ui/sidebar';
import { useMobileNavigation } from './use-mobile-navigation';
import type { CleanupFn } from './use-mobile-navigation';

vi.mock('@/components/ui/sidebar', () => ({
    useSidebar: vi.fn(),
}));

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('mobile navigation', () => {
    it.each([true, false])(
        'closes only the mobile sidebar when navigation starts (isMobile: %s)',
        (isMobile) => {
            const setOpen = vi.fn();
            const setOpenMobile = vi.fn();
            const removeProperty = vi.fn();
            vi.mocked(useSidebar).mockReturnValue({
                isMobile,
                state: 'expanded',
                open: true,
                setOpen,
                openMobile: true,
                setOpenMobile,
                toggleSidebar: vi.fn(),
            });
            vi.stubGlobal('document', {
                body: { style: { removeProperty } },
            });
            let navigate!: CleanupFn;

            function Navigation() {
                navigate = useMobileNavigation();

                return null;
            }

            renderToStaticMarkup(createElement(Navigation));
            expect(setOpenMobile).not.toHaveBeenCalled();

            navigate();

            if (isMobile) {
                expect(setOpenMobile).toHaveBeenCalledExactlyOnceWith(false);
            } else {
                expect(setOpenMobile).not.toHaveBeenCalled();
            }

            expect(setOpen).not.toHaveBeenCalled();
            expect(removeProperty).toHaveBeenCalledExactlyOnceWith(
                'pointer-events',
            );
        },
    );
});
