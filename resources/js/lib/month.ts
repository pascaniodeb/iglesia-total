const monthName = new Intl.DateTimeFormat('es-VE', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/** "2026-11" → "Noviembre de 2026". */
export function formatMonth(month: string): string {
    const label = monthName.format(new Date(`${month}-01T00:00:00Z`));

    return label.charAt(0).toUpperCase() + label.slice(1);
}
