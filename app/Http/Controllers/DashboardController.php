<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Queries\TreasuryDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The staff home. Accounts without access to the dashboard (regular members) land on
     * their own profile instead of an error page, which is also where login sends them.
     *
     * Every widget is sent only to who may open the page behind it: a widget the account cannot
     * see is not hidden in the browser, its data never leaves the server.
     */
    public function __invoke(Request $request, PeriodSchedule $periods): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->cannot(Permission::DashboardView->value)) {
            return redirect()->route('profile.edit');
        }

        $today = ChurchCalendar::today();
        $month = Month::containing($today);
        $can = fn (Permission $permission): bool => $user?->can($permission->value) ?? false;

        return Inertia::render('dashboard', [
            'today' => $today->format('Y-m-d'),
            'month' => $month->key(),
            'offerings' => $can(Permission::OfferingsView) ? TreasuryDashboard::offerings($today) : null,
            'tithes' => $can(Permission::TithesView) ? TreasuryDashboard::tithes($today) : null,
            'pending' => $can(Permission::ClosuresView) ? [
                ...TreasuryDashboard::pending($today),
                'accounting' => $can(Permission::AccountingView) ? $this->pendingAccountingMonth($periods, $today) : null,
            ] : null,
            'cash' => $can(Permission::AccountingView) ? MonthSummary::of($month)['currencies'] : null,
            'rates' => $can(Permission::CatalogsView) ? TreasuryDashboard::rates($today) : null,
        ]);
    }

    /**
     * The accounting month waiting to be closed (once it has ended), and what blocks it.
     *
     * @return array{key: string, blocker: string|null}|null
     */
    private function pendingAccountingMonth(PeriodSchedule $periods, \DateTimeImmutable $today): ?array
    {
        $next = $periods->next();

        return $next === null || $next->lastDay() >= $today ? null : ['key' => $next->key(), 'blocker' => $periods->blocker($next)];
    }
}
