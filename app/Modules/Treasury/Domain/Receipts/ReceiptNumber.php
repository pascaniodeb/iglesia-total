<?php

namespace App\Modules\Treasury\Domain\Receipts;

use InvalidArgumentException;

/**
 * The number of a receipt as the printed book shows it.
 *
 * It is an integer in the database, in the receipt's hash and in its entry; the zeros are only
 * presentation, so changing how it is shown can never move a sealed hash. Six digits like the
 * printed book ("000001", "000100"); past a million it grows instead of being cut: a receipt is
 * never printed with fewer digits than it has.
 */
final class ReceiptNumber
{
    public const int DIGITS = 6;

    public static function format(int $number): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException("A receipt number is positive: {$number}.");
        }

        return str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The number the next receipt gets: the one after the highest issued, and never below the
     * book's start number. The database applies the same rule under a lock when it inserts.
     */
    public static function next(?int $highest, int $startNumber): int
    {
        return $highest === null ? $startNumber : max($startNumber, $highest + 1);
    }
}
