<?php

namespace App\Modules\Accounting\Application;

/**
 * Port declared by Accounting and implemented by Treasury: what the accounting close needs to
 * know about a month's offerings without reading Treasury's tables.
 */
interface OfferingClosures
{
    /**
     * Whether the month's offerings are settled: closed and posted (step 2), or the month is
     * before Treasury's first month (there were no offerings to close).
     */
    public function isSettled(string $month): bool;

    /**
     * The closed offerings of the month, or null when they are not closed.
     *
     * @return array{count: int, ves: string, usd: string, cop: string}|null
     */
    public function totals(string $month): ?array;
}
