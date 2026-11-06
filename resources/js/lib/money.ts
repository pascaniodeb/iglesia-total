/**
 * Amounts formatted from the server's decimal text, never through binary numbers:
 * "141000.00" → "141.000,00". What is shown is exactly what is stored.
 */
export function formatAmount(value: string): string {
    const negative = value.startsWith('-');
    const [integer = '0', fraction = '00'] = value.replace('-', '').split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${negative ? '-' : ''}${grouped},${fraction.padEnd(2, '0')}`;
}

/** Whether a decimal text is zero ("0", "0.00"). */
export function isZeroAmount(value: string): boolean {
    return /^-?0+(\.0+)?$/.test(value);
}

/** "141000.00" → "141000,00", to edit it in a form. Zero becomes empty. */
export function editableAmount(value: string): string {
    return isZeroAmount(value) ? '' : value.replace('.', ',');
}
