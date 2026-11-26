<?php

namespace App\Modules\Shared\Pdf;

/**
 * How printed documents write numbers and dates: thousands with a dot and decimals with a comma,
 * as it is written here, from the server's exact decimal text and never through a float.
 */
final class Format
{
    /** "141000.5" → "141.000,50"; "-1234.5" → "-1.234,50". */
    public static function amount(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer === '' ? '0' : $integer);

        return ($negative ? '-' : '').$grouped.','.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** An amount in a table cell: a dash for nothing, the number for everything else, negatives included. */
    public static function cell(string $value): string
    {
        return preg_match('/^-?0*(\.0*)?$/', $value) === 1 ? '-' : self::amount($value);
    }

    /** "2026-11-25" → "25/11/2026". */
    public static function date(string $iso): string
    {
        [$year, $month, $day] = array_pad(explode('-', substr($iso, 0, 10)), 3, '');

        return "{$day}/{$month}/{$year}";
    }
}
