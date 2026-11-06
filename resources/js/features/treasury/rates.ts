/**
 * Rates formatted from the server's decimal text, never through binary numbers:
 * "1234.5678" → "1.234,5678". What is shown is exactly what is stored.
 */
export function formatRate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const [integer = '', fraction] = value.split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return fraction ? `${grouped},${fraction}` : grouped;
}

/** "1234.5678" (from the server) → "1234,5678" to edit it. */
export function editableRate(value: string): string {
    return value.replace('.', ',');
}

const longDate = new Intl.DateTimeFormat('es-VE', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const shortDate = new Intl.DateTimeFormat('es-VE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

/** "2026-10-17" → "sábado, 17 de octubre de 2026" (a date is a day, without time or zone). */
export function formatLongDate(date: string): string {
    return longDate.format(new Date(`${date}T00:00:00Z`));
}

/** "2026-10-17" → "17/10/2026". */
export function formatShortDate(date: string): string {
    return shortDate.format(new Date(`${date}T00:00:00Z`));
}
