<?php

namespace App\Modules\Treasury\Application\Closures;

use App\Modules\Treasury\Domain\IsoWeek;
use App\Modules\Treasury\Domain\Month;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use DateTimeImmutable;

/**
 * Which month's offerings close next. Months close in order, without gaps: after the last
 * closed month comes the next one; before any, the month of the oldest offering. A month can
 * close only when every week with a day in it is closed (so the month has necessarily ended).
 */
final class MonthlyOfferingClosureSchedule
{
    public static function next(): ?Month
    {
        $last = MonthlyOfferingClosure::query()->orderByDesc('month')->value('month');

        if (is_string($last)) {
            return Month::fromKey($last)->next();
        }

        $oldest = Offering::query()->min('offering_date');

        return $oldest === null ? null : Month::containing(new DateTimeImmutable((string) $oldest));
    }

    /**
     * The weeks of the month that are still open, by key. Weeks that start before the first
     * weekly closure are not part of the circuit (the system did not exist yet) and are not
     * required: otherwise the first month could never close. Same rule as the database.
     *
     * @return list<string>
     */
    public static function openWeeks(Month $month): array
    {
        $firstClosed = WeeklyClosure::query()->min('week_start');
        $weeks = array_filter(
            $month->weeks(),
            fn (IsoWeek $week) => $firstClosed === null || $week->start->format('Y-m-d') >= substr((string) $firstClosed, 0, 10),
        );
        $keys = array_values(array_map(fn (IsoWeek $week) => $week->key(), $weeks));
        $closed = WeeklyClosure::query()->whereIn('week_key', $keys)->pluck('week_key')->all();

        return array_values(array_diff($keys, $closed));
    }

    /**
     * Why the month's offerings cannot be closed now, or null when they can.
     */
    public static function blocker(Month $month): ?string
    {
        if (MonthlyOfferingClosure::isClosed($month->key())) {
            return __('The offerings of :month are already closed.', ['month' => $month->key()]);
        }

        $next = self::next();

        if ($next !== null && ! $next->equals($month)) {
            return __('Months close in order: the next one to close is :month.', ['month' => $next->key()]);
        }

        $open = self::openWeeks($month);

        if ($open !== []) {
            return __('Close first the weeks of the month that are still open: :weeks.', ['weeks' => implode(', ', $open)]);
        }

        return null;
    }
}
