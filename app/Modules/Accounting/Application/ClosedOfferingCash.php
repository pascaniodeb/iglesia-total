<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Shared\Money\Currency;

/**
 * Port declared by Accounting and implemented by Treasury: the money of offerings whose week is
 * closed, day by day. It is counted as available cash from the day it came in (D4), before and
 * after the month's offerings are posted (the monthly entry is dated the last day of the month;
 * these amounts take its place in the availability check, and the database guarantees both add
 * up to the same).
 */
interface ClosedOfferingCash
{
    /**
     * @return array<string, string> by date (YYYY-MM-DD), the day's total in that currency, up to $until inclusive
     */
    public function dailyTotals(Currency $currency, string $until): array;
}
