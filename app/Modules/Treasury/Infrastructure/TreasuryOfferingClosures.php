<?php

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Accounting\Application\OfferingClosures;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Closures\MonthlyOfferingClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;

/**
 * Treasury's answer to Accounting's OfferingClosures port.
 */
final class TreasuryOfferingClosures implements OfferingClosures
{
    public function isSettled(string $month): bool
    {
        if (MonthlyOfferingClosure::isClosed($month)) {
            return true;
        }

        $first = MonthlyOfferingClosure::query()->min('month') ?? MonthlyOfferingClosureSchedule::next()?->key();

        // Before the first month Treasury closes (or with no offerings at all) there is nothing to settle.
        return $first === null || $month < Month::fromKey((string) $first)->key();
    }

    public function totals(string $month): ?array
    {
        $closure = MonthlyOfferingClosure::query()->where('month', $month)->first();

        return $closure === null ? null : [
            'count' => $closure->offerings_count,
            'ves' => $closure->total_ves,
            'usd' => $closure->total_usd,
            'cop' => $closure->total_cop,
        ];
    }
}
