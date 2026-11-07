<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Application\Actions\SealPeriod;
use App\Modules\Integrity\Application\SealedPeriods;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Shared\Tables\TableQuery;
use App\Modules\Shared\Time\Month;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The accounting close (accounting.view to read; accounting.manage to close and to retry a
 * seal). Step 4 of the month circuit.
 */
class AccountingPeriodController extends Controller
{
    public function index(Request $request, PeriodSchedule $schedule, SealedPeriods $sealed): Response
    {
        $table = TableQuery::fromRequest($request, ['month' => 'accounting_periods.month'], 'month', defaultDirection: 'desc', withStatus: false);
        $page = $table->paginate(AccountingPeriod::query(), ['accounting_periods.month']);
        $seals = $sealed->of('monthly', array_values(array_map(fn (AccountingPeriod $period): string => $period->month, $page->items())));
        $next = $schedule->next();

        return Inertia::render('accounting/periods/index', [
            'periods' => $page->through(fn (AccountingPeriod $period) => [
                'id' => $period->id,
                'month' => $period->month,
                'closed_at' => $period->closed_at->toIso8601String(),
                'currencies' => MonthSummary::of(Month::fromKey($period->month))['currencies'],
                'seal' => $seals[$period->month] ?? null,
            ]),
            'filters' => $table->filters(),
            'next' => $next === null ? null : [
                'month' => $next->key(),
                'summary' => MonthSummary::of($next),
                'blocker' => $schedule->blocker($next),
            ],
        ]);
    }

    public function store(Request $request, ClosePeriod $close): RedirectResponse
    {
        $month = $this->month($request);
        $result = $close($month);

        Inertia::flash('toast', $result['sealed']
            ? ['type' => 'success', 'message' => __('The month :month was closed and sealed. The next month opens with its closing balance.', ['month' => $month->key()])]
            : ['type' => 'warning', 'message' => __('The month :month was closed; its seal is pending: :reason', ['month' => $month->key(), 'reason' => (string) $result['seal_error']])]);

        return back();
    }

    public function seal(Request $request, SealPeriod $seal): RedirectResponse
    {
        $month = $this->month($request);

        try {
            $block = $seal('monthly', $month->key());
        } catch (LedgerException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage(), $exception->params)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The month :month is sealed (block :height).', ['month' => $month->key(), 'height' => $block->height])]);

        return back();
    }

    private function month(Request $request): Month
    {
        $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        try {
            return Month::fromKey($request->string('month')->toString());
        } catch (InvalidArgumentException) {
            abort(422);
        }
    }
}
