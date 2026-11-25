<?php

namespace App\Modules\Accounting\Http\Concerns;

use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reads the period a report screen asks for and lists the periods there is something to report on.
 */
trait ReadsReportPeriod
{
    private function requestedPeriod(Request $request): ReportPeriod
    {
        return ReportPeriod::fromQuery($request->query('granularity'), $request->query('period'), Month::containing(ChurchCalendar::today()));
    }

    /**
     * Every month from the first entry to the current month (and the months of the period on screen,
     * so a shared link to any period shows its selection), with the quarters, semesters and years
     * they make up.
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function periodOptions(ReportPeriod $shown): array
    {
        $current = Month::containing(ChurchCalendar::today());
        $earliest = DB::table('journal_entries')->min('month');
        $keys = [$current->key(), $shown->first->key(), ...(is_string($earliest) ? [$earliest] : [])];

        return ReportPeriod::options(Month::fromKey(min($keys)), Month::fromKey(max($current->key(), $shown->last->key())));
    }
}
