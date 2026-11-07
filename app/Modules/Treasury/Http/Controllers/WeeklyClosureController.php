<?php

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrity\Application\Actions\SealPeriod;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Application\Closures\WeekSnapshot;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Weekly closures (closures.view to read; closures.manage to close and to retry a seal). Step
 * 1 of the month circuit.
 */
class WeeklyClosureController extends Controller
{
    public function index(Request $request, SealedPeriods $sealed): Response
    {
        $table = TableQuery::fromRequest($request, ['week' => 'weekly_closures.week_start'], 'week', defaultDirection: 'desc', withStatus: false);
        $page = $table->paginate(WeeklyClosure::query(), ['weekly_closures.week_key']);
        $seals = $sealed->of('weekly', array_values(array_map(fn (WeeklyClosure $closure): string => $closure->week_key, $page->items())));
        $next = WeeklyClosureSchedule::next();

        return Inertia::render('treasury/weekly-closures/index', [
            'closures' => $page->through(fn (WeeklyClosure $closure) => [
                'id' => $closure->id,
                'week_key' => $closure->week_key,
                'week_start' => $closure->week_start->toDateString(),
                'week_end' => $closure->week_end->toDateString(),
                'offerings_count' => $closure->offerings_count,
                'total_ves' => $closure->total_ves,
                'total_usd' => $closure->total_usd,
                'total_cop' => $closure->total_cop,
                'closed_at' => $closure->closed_at->toIso8601String(),
                'seal' => $seals[$closure->week_key] ?? null,
            ]),
            'filters' => $table->filters(),
            'next' => $next === null ? null : [
                'week_key' => $next->key(),
                'week_start' => $next->start->format('Y-m-d'),
                'week_end' => $next->end()->format('Y-m-d'),
                'months' => $next->months(),
                'snapshot' => WeekSnapshot::of($next),
                'blocker' => WeeklyClosureSchedule::blocker($next),
            ],
        ]);
    }

    public function store(Request $request, CloseWeek $close): RedirectResponse
    {
        $week = $this->week($request);
        $result = $close($week);

        Inertia::flash('toast', $result['sealed']
            ? ['type' => 'success', 'message' => __('The week :week was closed and sealed.', ['week' => $week->key()])]
            : ['type' => 'warning', 'message' => __('The week :week was closed; its seal is pending: :reason', ['week' => $week->key(), 'reason' => (string) $result['seal_error']])]);

        return back();
    }

    public function seal(Request $request, SealPeriod $seal): RedirectResponse
    {
        $week = $this->week($request);

        try {
            $block = $seal('weekly', $week->key());
        } catch (LedgerException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage(), $exception->params)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The week :week is sealed (block :height).', ['week' => $week->key(), 'height' => $block->height])]);

        return back();
    }

    private function week(Request $request): IsoWeek
    {
        $request->validate(['week_key' => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/']]);

        try {
            return IsoWeek::fromKey($request->string('week_key')->toString());
        } catch (InvalidArgumentException) {
            abort(422, __('That is not a valid week.'));
        }
    }
}
