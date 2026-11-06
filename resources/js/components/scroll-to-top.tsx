import { ArrowUp } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { cn } from '@/lib/utils';

/** Shown once the header and the first screen are behind the reader. */
const VISIBLE_AFTER_PX = 480;

function subscribe(onChange: () => void): () => void {
    window.addEventListener('scroll', onChange, { passive: true });

    return () => window.removeEventListener('scroll', onChange);
}

const isScrolled = () => window.scrollY > VISIBLE_AFTER_PX;

function scrollToTop(): void {
    const reduced = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'instant' : 'smooth' });
}

export function ScrollToTop() {
    const visible = useSyncExternalStore(subscribe, isScrolled, () => false);

    return (
        <button
            type="button"
            data-scroll-top
            aria-label="Back to top"
            title="Back to top"
            // inert, not aria-hidden: after the click it hides while focused, and inert drops focus cleanly.
            inert={!visible}
            onClick={scrollToTop}
            className={cn(
                'fixed right-4 bottom-[max(1rem,env(safe-area-inset-bottom))] z-30 inline-flex size-9 items-center justify-center rounded-full border border-border/70 bg-background/70 text-muted-foreground shadow-sm backdrop-blur-md transition-[opacity,transform,color] duration-200 hover:text-foreground focus-visible:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none sm:right-6 sm:bottom-6',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'pointer-events-none translate-y-2 opacity-0',
            )}
        >
            <ArrowUp aria-hidden="true" className="size-4" />
        </button>
    );
}
