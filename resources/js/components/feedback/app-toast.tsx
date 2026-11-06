import {
    CircleCheck,
    CircleX,
    Info,
    TriangleAlert,
    X,
    type LucideIcon,
} from 'lucide-react';
import { toast } from 'sonner';
import type { FlashToast, ToastType } from '@/types/ui';

const DEFAULT_DURATION = 5000;

/** Icon of each meaning; the color comes from the type token (app.css). */
const ICONS = {
    success: CircleCheck,
    info: Info,
    warning: TriangleAlert,
    error: CircleX,
} satisfies Record<ToastType, LucideIcon>;

type AppToastProps = FlashToast & { id: string | number };

/**
 * A toast: accent and icon in the color of its type, optional title, message, close button and a
 * bar showing the time left. What demands attention (warning, error) is announced as an alert.
 */
function AppToast({
    id,
    type,
    title,
    message,
    duration = DEFAULT_DURATION,
}: AppToastProps) {
    const Icon = ICONS[type] ?? Info;
    const urgent = type === 'warning' || type === 'error';

    return (
        <div
            data-toast-type={type}
            role={urgent ? 'alert' : 'status'}
            className="app-toast relative flex w-full items-start gap-3 overflow-hidden rounded-2xl border border-s-4 border-border border-s-(--toast-accent) bg-popover p-4 pr-11 text-popover-foreground shadow-xl shadow-black/10 sm:w-[var(--width)]"
        >
            <span className="app-toast-icon grid size-9 shrink-0 place-items-center rounded-xl">
                <Icon className="size-5" aria-hidden />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5 pt-0.5">
                {title ? (
                    <>
                        <p className="leading-tight font-bold">{title}</p>
                        <p className="text-sm break-words text-muted-foreground">
                            {message}
                        </p>
                    </>
                ) : (
                    <p className="leading-tight font-bold break-words">
                        {message}
                    </p>
                )}
            </div>
            <button
                type="button"
                onClick={() => toast.dismiss(id)}
                aria-label="Dismiss notification"
                className="absolute top-2.5 right-2.5 grid size-8 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <X className="size-4" aria-hidden />
            </button>
            <span
                aria-hidden
                className="app-toast-progress absolute inset-x-0 bottom-0 h-1"
                style={{ animationDuration: `${duration}ms` }}
            />
        </div>
    );
}

/** Shows a toast with the house look (used for server-flashed toasts). */
export function showToast(data: FlashToast): void {
    toast.custom((id) => <AppToast id={id} {...data} />, {
        duration: data.duration ?? DEFAULT_DURATION,
    });
}
