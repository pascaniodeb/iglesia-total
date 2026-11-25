<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use App\Modules\Treasury\Application\Tithes\TitheAnalysis;
use App\Modules\Treasury\Application\Tithes\TitheReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the tithes book says, by period (tithes.view, the pastor only): the report (who tithed
 * and how much) and the analysis (how constantly, and who stopped).
 */
class TitheInsightsController extends Controller
{
    public function report(Request $request, MemberDirectory $directory): Response
    {
        $period = $this->period($request);

        return Inertia::render('treasury/tithes/reports', [
            'report' => TitheReport::of($period, $directory),
            'options' => $this->options($period),
        ]);
    }

    public function analysis(Request $request, MemberDirectory $directory): Response
    {
        $period = $this->period($request);

        return Inertia::render('treasury/tithes/analysis', [
            'analysis' => TitheAnalysis::of($period, $directory),
            'options' => $this->options($period),
            'today' => ChurchCalendar::today()->format('Y-m-d'),
        ]);
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromQuery($request->query('granularity'), $request->query('period'), Month::containing(ChurchCalendar::today()));
    }

    /**
     * Every month from the first tithe to the current one (and the months of the period on screen,
     * so a shared link shows its selection), with the periods they make up.
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function options(ReportPeriod $shown): array
    {
        $current = Month::containing(ChurchCalendar::today());
        $earliest = DB::table('tithes')->whereNull('deleted_at')->min('month_key');
        $keys = [$current->key(), $shown->first->key(), ...(is_string($earliest) ? [$earliest] : [])];

        return ReportPeriod::options(Month::fromKey(min($keys)), Month::fromKey(max($current->key(), $shown->last->key())));
    }
}
