<?php

namespace App\Modules\Accounting\Http\Concerns;

use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads the period a report screen asks for (?granularity=quarter&period=2026-Q2) and lists the
 * periods there is something to report on. A malformed or missing period falls back to the
 * current month instead of failing: the URL is something people type and share.
 */
trait ReadsReportPeriod
{
    private function requestedPeriod(Request $request): ReportPeriod
    {
        $granularity = $request->query('granularity');
        $key = $request->query('period');

        if (is_string($granularity) && is_string($key)) {
            try {
                return ReportPeriod::fromKey($granularity, $key);
            } catch (InvalidArgumentException) {
                // Falls back to the current month below.
            }
        }

        return ReportPeriod::containing(ReportPeriod::MONTH, Month::containing(ChurchCalendar::today()));
    }

    /**
     * Every month from the first entry to the current month, and the quarters, semesters and years
     * they make up, newest first, each with its short label. The months of the period on screen are
     * always among them, so a shared link to any period shows its selection.
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function periodOptions(ReportPeriod $shown): array
    {
        $current = Month::containing(ChurchCalendar::today());
        $earliest = DB::table('journal_entries')->min('month');
        $keys = [$current->key(), $shown->first->key(), ...(is_string($earliest) ? [$earliest] : [])];
        $first = Month::fromKey(min($keys));
        $last = Month::fromKey(max($current->key(), $shown->last->key()));
        $options = [];

        foreach ([ReportPeriod::MONTH, ReportPeriod::QUARTER, ReportPeriod::SEMESTER, ReportPeriod::YEAR] as $granularity) {
            $periods = [];

            for ($month = $last; $month->key() >= $first->key(); $month = $month->previous()) {
                $period = ReportPeriod::containing($granularity, $month);
                $periods[$period->key()] ??= ['key' => $period->key(), 'label' => $period->shortLabel()];
            }

            $options[$granularity] = array_values($periods);
        }

        return $options;
    }
}
