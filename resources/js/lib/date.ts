/** Today as YYYY-MM-DD in the local zone (UTC would give Venezuela tomorrow after 8 p.m.). */
export function todayIso(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * The church's clock. Instants are stored in UTC and always shown in Venezuelan time, the same
 * on the server (SSR) and in the browser, so the rendered HTML and the hydrated page agree.
 */
export const CHURCH_TIME_ZONE = 'America/Caracas';

const dateTimeFormat = new Intl.DateTimeFormat('es-VE', {
    timeZone: CHURCH_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
});

/** "06/11/2026, 01:05 p. m." from an ISO 8601 instant, in the church's time zone. */
export function formatDateTime(iso: string): string {
    return dateTimeFormat.format(new Date(iso));
}
