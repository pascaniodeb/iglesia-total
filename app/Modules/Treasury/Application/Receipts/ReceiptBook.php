<?php

namespace App\Modules\Treasury\Application\Receipts;

use App\Modules\Treasury\Domain\Receipts\ReceiptNumber;
use App\Modules\Treasury\Infrastructure\Models\Receipt;
use App\Modules\Treasury\Infrastructure\Models\ReceiptSetting;

/**
 * Where the receipt book stands: the number the next receipt will get.
 */
final class ReceiptBook
{
    /**
     * The next number as a preview for the screen: it reserves nothing, the real one is assigned
     * by the database when the receipt is inserted (two people with the form open will both see
     * the same preview, and each gets the following one).
     */
    public static function nextNumber(): int
    {
        $highest = Receipt::query()->max('number');

        return ReceiptNumber::next($highest === null ? null : (int) $highest, ReceiptSetting::current()->start_number);
    }
}
