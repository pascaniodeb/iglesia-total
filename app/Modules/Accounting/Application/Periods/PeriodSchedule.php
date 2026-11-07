<?php

namespace App\Modules\Accounting\Application\Periods;

use App\Modules\Accounting\Application\OfferingClosures;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;

/**
 * Which accounting month closes next and why it may not close yet. Months close in order: after
 * the last closed month comes the next one; before any, the month of the oldest entry.
 */
final readonly class PeriodSchedule
{
    public function __construct(private OfferingClosures $offerings) {}

    public function next(): ?Month
    {
        $last = AccountingPeriod::lastClosed();

        if ($last !== null) {
            return Month::fromKey($last)->next();
        }

        $oldest = JournalEntry::query()->min('month');

        return $oldest === null ? null : Month::fromKey((string) $oldest);
    }

    /**
     * The months that can receive entries: the month after the last closed one and the
     * following one (at most two open months), never beyond the current month. Before any close,
     * every month up to the current one.
     *
     * @return array{from: string|null, to: string}
     */
    public function openWindow(): array
    {
        $current = Month::containing(ChurchCalendar::today())->key();
        $last = AccountingPeriod::lastClosed();

        if ($last === null) {
            return ['from' => null, 'to' => $current];
        }

        $from = Month::fromKey($last)->next();
        $to = $from->next()->key();

        return ['from' => $from->key(), 'to' => min($to, $current)];
    }

    /**
     * Why the month cannot be closed now, or null when it can.
     */
    public function blocker(Month $month): ?string
    {
        if (AccountingPeriod::isClosed($month->key())) {
            return __('The month :month is already closed.', ['month' => $month->key()]);
        }

        $next = $this->next();

        if ($next !== null && ! $next->equals($month)) {
            return __('Months close in order: the next one to close is :month.', ['month' => $next->key()]);
        }

        if ($month->lastDay()->format('Y-m-d') >= ChurchCalendar::today()->format('Y-m-d')) {
            return __(':month has not finished yet: it can be closed from :date.', [
                'month' => $month->key(),
                'date' => $month->next()->firstDay->format('d/m/Y'),
            ]);
        }

        if (! $this->offerings->isSettled($month->key())) {
            return __('The offerings of :month are not closed yet (step 2).', ['month' => $month->key()]);
        }

        return null;
    }
}
