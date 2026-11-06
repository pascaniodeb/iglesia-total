<?php

namespace App\Modules\Treasury\Domain\Rates;

use DateTimeImmutable;

/**
 * Port: the official rates of a day (BCV and TRM). The production adapter queries the public
 * sources; tests use fake HTTP responses or an in-memory source.
 */
interface MarketRateSource
{
    /**
     * @throws RatesUnavailable when a source does not answer or answers something out of range
     */
    public function fetch(DateTimeImmutable $date): DailyRates;
}
