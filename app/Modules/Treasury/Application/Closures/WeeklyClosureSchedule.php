<?php

namespace App\Modules\Treasury\Application\Closures;

use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Infrastructure\Models\Offering;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use DateTimeImmutable;

/**
 * Which week is next to close. Weeks close in order, without gaps (a week with no offerings is
 * closed too: closing nothing says it was reviewed): after the last closed week comes the next
 * one; before any closure, the week of the oldest offering.
 */
final class WeeklyClosureSchedule
{
    public static function next(): ?IsoWeek
    {
        $last = WeeklyClosure::query()->orderByDesc('week_start')->value('week_key');

        if (is_string($last)) {
            return IsoWeek::fromKey($last)->next();
        }

        $oldest = Offering::query()->min('offering_date');

        return $oldest === null ? null : IsoWeek::containing(new DateTimeImmutable((string) $oldest));
    }

    /**
     * Why the week cannot be closed now, or null when it can.
     */
    public static function blocker(IsoWeek $week, ?DateTimeImmutable $today = null): ?string
    {
        $next = self::next();

        if (WeeklyClosure::isClosed($week->key())) {
            return __('The week :week is already closed.', ['week' => $week->key()]);
        }

        if ($next !== null && ! $next->equals($week)) {
            return __('Weeks close in order: the next one to close is :week.', ['week' => $next->key()]);
        }

        if (! $week->hasEnded($today ?? ChurchCalendar::today())) {
            return __('The week :week ends on :date; it can be closed from that day.', [
                'week' => $week->key(),
                'date' => $week->end()->format('d/m/Y'),
            ]);
        }

        return null;
    }
}
