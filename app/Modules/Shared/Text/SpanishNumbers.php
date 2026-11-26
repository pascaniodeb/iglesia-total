<?php

namespace App\Modules\Shared\Text;

use InvalidArgumentException;

/**
 * Whole numbers in Spanish words, for the amounts a receipt says "in letters".
 *
 * The irregular cases are the point: "y" only between tens and units ("treinta y uno" but "ciento
 * uno"), "cien" alone and "ciento" with company, quinientos / setecientos / novecientos, the
 * apocope of "uno" before a noun ("treinta y un mil", "ciento un millones", never "uno mil"), and
 * the long scale (millón, billón, trillón) without an "un mil" nobody says.
 */
final class SpanishNumbers
{
    private const array ONES = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince'];

    private const array TEENS = ['dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];

    private const array TWENTIES = ['', 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const array TENS = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];

    private const array HUNDREDS = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    private const array SCALES = [['millón', 'millones'], ['billón', 'billones'], ['trillón', 'trillones']];

    /**
     * 1 gives "un" (apocopated), 1000 "mil", 1000000 "un millón".
     */
    public static function words(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException("Cannot write {$number} in words.");
        }

        if ($number === 0) {
            return 'cero';
        }

        if ($number === 1) {
            return 'un';
        }

        $chunks = [];

        for ($rest = $number; $rest > 0; $rest = intdiv($rest, 1_000_000)) {
            $chunks[] = $rest % 1_000_000;
        }

        $parts = [];

        for ($i = count($chunks) - 1; $i >= 0; $i--) {
            $chunk = $chunks[$i];

            if ($chunk === 0) {
                continue;
            }

            if ($i === 0) {
                $parts[] = self::chunk($chunk);

                continue;
            }

            $scale = self::SCALES[$i - 1] ?? throw new InvalidArgumentException("{$number} is too big to write in words.");
            $parts[] = ($chunk === 1 ? 'un' : self::apocope(self::chunk($chunk))).' '.($chunk === 1 ? $scale[0] : $scale[1]);
        }

        return implode(' ', $parts);
    }

    /**
     * "uno" before a noun (also inside a compound numeral) is "un"; alone it stays "uno".
     */
    public static function apocope(string $words): string
    {
        if ($words === 'uno') {
            return 'un';
        }

        return str_ends_with($words, ' uno') ? substr($words, 0, -4).' un' : $words;
    }

    private static function twoDigits(int $n): string
    {
        $n = max(0, min(99, $n));

        return match (true) {
            $n === 0 => '',
            $n < 16 => self::ONES[$n],
            $n < 20 => self::TEENS[$n - 16],
            $n < 30 => self::TWENTIES[$n - 20],
            default => $n % 10 === 0 ? self::TENS[intdiv($n, 10)] : self::TENS[intdiv($n, 10)].' y '.self::ONES[$n % 10],
        };
    }

    private static function threeDigits(int $n): string
    {
        $n = max(0, min(999, $n));

        if ($n === 0) {
            return '';
        }

        if ($n === 100) {
            return 'cien';
        }

        $hundreds = intdiv($n, 100);
        $rest = $n % 100;

        if ($hundreds === 0) {
            return self::twoDigits($rest);
        }

        $head = self::HUNDREDS[$hundreds] ?? '';

        return $rest === 0 ? $head : $head.' '.self::twoDigits($rest);
    }

    /** 1..999999: the "thousands and units" part of one million-scale group. */
    private static function chunk(int $n): string
    {
        $thousands = intdiv($n, 1000);
        $units = $n % 1000;
        $parts = [];

        if ($thousands > 0) {
            $parts[] = $thousands === 1 ? 'mil' : self::apocope(self::threeDigits($thousands)).' mil';
        }

        if ($units > 0) {
            $parts[] = self::threeDigits($units);
        }

        return implode(' ', $parts);
    }
}
