<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Closures\MonthlyOfferingClosureSchedule;
use App\Modules\Treasury\Application\Closures\MonthOfferingSnapshot;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Monthly offering closures (closures.view to read; closures.manage to close). Step 2 of the
 * month circuit: the month's offerings are posted to the journal.
 */
class MonthlyOfferingClosureController extends Controller
{
    public function index(Request $request, ChartOfAccounts $chart): Response
    {
        $table = TableQuery::fromRequest($request, ['month' => 'monthly_offering_closures.month'], 'month', defaultDirection: 'desc', withStatus: false);
        $page = $table->paginate(MonthlyOfferingClosure::query(), ['monthly_offering_closures.month']);
        $next = MonthlyOfferingClosureSchedule::next();

        return Inertia::render('treasury/monthly-offering-closures/index', [
            'closures' => $page->through(fn (MonthlyOfferingClosure $closure) => [
                'id' => $closure->id,
                'month' => $closure->month,
                'offerings_count' => $closure->offerings_count,
                'total_ves' => $closure->total_ves,
                'total_usd' => $closure->total_usd,
                'total_cop' => $closure->total_cop,
                'journal_entry_id' => $closure->journal_entry_id,
                'closed_at' => $closure->closed_at->toIso8601String(),
            ]),
            'filters' => $table->filters(),
            'next' => $next === null ? null : $this->nextMonth($next, $chart),
        ]);
    }

    public function store(Request $request, CloseMonthOfferings $close): RedirectResponse
    {
        $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        try {
            $month = Month::fromKey($request->string('month')->toString());
        } catch (InvalidArgumentException) {
            abort(422);
        }

        $close($month);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The offerings of :month were closed and posted to the journal.', ['month' => $month->key()])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function nextMonth(Month $month, ChartOfAccounts $chart): array
    {
        $snapshot = MonthOfferingSnapshot::of($month);
        $accounts = $chart->findMany(array_values(array_unique(array_column($snapshot['categories'], 'account_id'))));
        $weekKeys = array_map(fn (IsoWeek $week) => $week->key(), $month->weeks());
        $closed = WeeklyClosure::query()->whereIn('week_key', $weekKeys)->pluck('week_key')->all();

        return [
            'month' => $month->key(),
            'last_day' => $month->lastDay()->format('Y-m-d'),
            'weeks' => array_map(fn (IsoWeek $week) => [
                'week_key' => $week->key(),
                'week_start' => $week->start->format('Y-m-d'),
                'week_end' => $week->end()->format('Y-m-d'),
                'is_closed' => in_array($week->key(), $closed, true),
            ], $month->weeks()),
            'snapshot' => [
                'count' => $snapshot['count'],
                'ves' => $snapshot['ves'],
                'usd' => $snapshot['usd'],
                'cop' => $snapshot['cop'],
                'categories' => array_map(fn (array $category) => [
                    ...$category,
                    'account_code' => $accounts[$category['account_id']]->code ?? null,
                    'account_name' => $accounts[$category['account_id']]->name ?? null,
                ], $snapshot['categories']),
            ],
            'blocker' => MonthlyOfferingClosureSchedule::blocker($month),
        ];
    }
}
