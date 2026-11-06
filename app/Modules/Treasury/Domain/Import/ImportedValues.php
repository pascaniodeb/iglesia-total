<?php

namespace App\Modules\Treasury\Domain\Import;

use DateTimeImmutable;

/**
 * Strict readers for the values of an offerings file (ported from the old system's import).
 *
 * A form can guess what "1.000" means; an import that writes real money must not. Amounts are
 * read as Venezuelan Spanish ("20.000,50"), dates as DD/MM/YYYY built from their parts, and
 * anything ambiguous is refused with the text as it was written.
 */
final class ImportedValues
{
    /**
     * "20000", "20000,50", "20.000", "20.000,50" or "20.5" → "20000.50"; empty → "0.00";
     * anything else (letters, signs, two decimal separators, three decimals) → null.
     */
    public static function amount(string $raw): ?string
    {
        $value = trim($raw);

        $normalized = match (true) {
            $value === '' => '0',
            preg_match('/^\d+$/', $value) === 1 => $value,
            preg_match('/^\d+,\d{1,2}$/', $value) === 1 => str_replace(',', '.', $value),
            preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value) === 1 => str_replace(',', '.', str_replace('.', '', $value)),
            preg_match('/^\d+\.\d{1,2}$/', $value) === 1 => $value,
            default => null,
        };

        if ($normalized === null || strlen(explode('.', $normalized)[0]) > 16) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $normalized), 2, '');
        $integer = ltrim($integer, '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad($fraction, 2, '0');
    }

    /**
     * An amount that came from a numeric cell (already written in decimal notation with a dot,
     * see SpreadsheetReader::text()): at most two decimals, never read as thousands.
     */
    public static function amountFromNumber(string $value): ?string
    {
        if (preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $value, $parts) !== 1) {
            return null;
        }

        $integer = ltrim($parts[1], '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * "1/11/2026" or "01/11/2026" → "2026-11-01"; an impossible date (31/02) → null.
     */
    public static function date(string $raw): ?string
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($raw), $parts) !== 1) {
            return null;
        }

        [, $day, $month, $year] = array_map(intval(...), $parts);

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return (new DateTimeImmutable)->setDate($year, $month, $day)->format('Y-m-d');
    }
}
