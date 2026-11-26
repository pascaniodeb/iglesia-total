<?php

namespace App\Modules\Treasury\Application\Receipts;

use App\Modules\Shared\Text\SpanishNumbers;

/**
 * The block "LA CANTIDAD DE (En Letras)" of a receipt: one sentence per currency that has an
 * amount, in capitals and separated by semicolons. Currencies are never converted or added
 * together; the cents go as "CON 50/100", the convention of the paper book.
 */
final class ReceiptAmountInWords
{
    private const array NAMES = [
        'ves' => ['BOLÍVAR', 'BOLÍVARES'],
        'usd' => ['DÓLAR', 'DÓLARES'],
        'cop' => ['PESO COLOMBIANO', 'PESOS COLOMBIANOS'],
    ];

    /**
     * @param  array{ves: string, usd: string, cop: string}  $amounts  decimal text, "100.50"
     */
    public static function of(array $amounts): string
    {
        $sentences = [];

        foreach (self::NAMES as $currency => $names) {
            [$whole, $cents] = self::split($amounts[$currency]);

            if ($whole === 0 && $cents === '00') {
                continue;
            }

            $words = mb_strtoupper(SpanishNumbers::apocope(SpanishNumbers::words($whole)));
            $sentences[] = "{$words} ".($whole === 1 ? $names[0] : $names[1])." CON {$cents}/100";
        }

        return implode('; ', $sentences);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function split(string $amount): array
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return [(int) $whole, substr($fraction.'00', 0, 2)];
    }
}
