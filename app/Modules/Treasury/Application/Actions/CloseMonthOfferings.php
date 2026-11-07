<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Treasury\Application\Closures\MonthlyOfferingClosureSchedule;
use App\Modules\Treasury\Application\Closures\MonthOfferingSnapshot;
use App\Modules\Treasury\Domain\Month;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosureLine;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use IntlDateFormatter;

/**
 * Closes a month's offerings (step 2 of the month circuit) and posts them to the journal.
 *
 * One transaction, under a lock, with every rule re-checked inside it: the month is the next in
 * order and all its weeks are closed; the offerings are snapshotted by category; one income
 * entry is posted (debit CAJA, credit each category's income account, per currency, dated the
 * last day of the month, origin monthly_offering_closure:{month} so it can never be posted
 * twice); the closure is written and PostgreSQL checks, to the cent, that it matches the
 * offerings, that the entry posts exactly those totals to CAJA and that the category lines add
 * up. Any difference rolls everything back.
 */
final readonly class CloseMonthOfferings
{
    private const array COLUMNS = ['VES' => 'ves', 'USD' => 'usd', 'COP' => 'cop'];

    public function __construct(
        private Ledger $ledger,
        private ChartOfAccounts $chart,
        private ActivityLog $activityLog,
        private AuthFactory $auth,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Month $month): MonthlyOfferingClosure
    {
        return DB::transaction(function () use ($month): MonthlyOfferingClosure {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('monthly_offering_closures'))");

            $blocker = MonthlyOfferingClosureSchedule::blocker($month);

            if ($blocker !== null) {
                throw ValidationException::withMessages(['month' => $blocker]);
            }

            $snapshot = MonthOfferingSnapshot::of($month);
            $entryId = $snapshot['count'] === 0 ? null : $this->post($month, $snapshot['categories']);
            $actorId = $this->auth->guard()->id();

            $closure = new MonthlyOfferingClosure;
            $closure->forceFill([
                'month' => $month->key(),
                'offerings_count' => $snapshot['count'],
                'total_ves' => $snapshot['ves'],
                'total_usd' => $snapshot['usd'],
                'total_cop' => $snapshot['cop'],
                'journal_entry_id' => $entryId,
                'closed_by' => $actorId === null ? null : (string) $actorId,
            ])->save();

            foreach ($snapshot['categories'] as $category) {
                (new MonthlyOfferingClosureLine)->forceFill([
                    'monthly_offering_closure_id' => $closure->id,
                    'offering_category_id' => $category['category_id'],
                    'account_id' => $category['account_id'],
                    'offerings_count' => $category['count'],
                    'total_ves' => $category['ves'],
                    'total_usd' => $category['usd'],
                    'total_cop' => $category['cop'],
                ])->save();
            }

            // The lines are checked against the header at commit; ask for it now.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $this->activityLog->record(
                'monthly_offering_closure.closed',
                __('Closed the offerings of :month: :count offerings posted to the journal.', ['month' => $month->key(), 'count' => $snapshot['count']]),
                $closure,
                [
                    'count' => $snapshot['count'],
                    'totals' => ['ves' => $snapshot['ves'], 'usd' => $snapshot['usd'], 'cop' => $snapshot['cop']],
                    'journal_entry_id' => $entryId,
                ],
            );

            return $closure->refresh();
        });
    }

    /**
     * Posts the month's income entry and returns its id.
     *
     * @param  list<array{category_id: string, category: string, account_id: string, count: int, ves: string, usd: string, cop: string}>  $categories
     *
     * @throws ValidationException
     */
    private function post(Month $month, array $categories): string
    {
        $accounts = $this->chart->findMany(array_values(array_unique(array_column($categories, 'account_id'))));
        $cash = $this->chart->cashAccount();
        $lines = [];

        foreach (self::COLUMNS as $code => $column) {
            $currency = Currency::from($code);
            $byAccount = [];

            foreach ($categories as $category) {
                $byAccount[$category['account_id']] = ($byAccount[$category['account_id']] ?? Money::zero($currency))
                    ->plus(Money::of($category[$column], $currency));
            }

            $total = Money::sum($currency, ...array_values($byAccount));

            if ($total->isZero()) {
                continue;
            }

            $lines[] = JournalLine::debit($cash->code, $total);

            foreach ($byAccount as $accountId => $amount) {
                if ($amount->isPositive()) {
                    $lines[] = JournalLine::credit($accounts[$accountId]->code, $amount);
                }
            }
        }

        // "noviembre de 2026": the books are kept in Spanish.
        $name = (string) (new IntlDateFormatter('es', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, "LLLL 'de' y"))->format($month->firstDay);

        try {
            return $this->ledger->post(new JournalEntryDraft(
                entryDate: $month->lastDay()->format('Y-m-d'),
                description: "Ofrendas de {$name}",
                originType: 'monthly_offering_closure',
                originId: $month->key(),
                originPurpose: 'income',
                lines: $lines,
            ))->id;
        } catch (LedgerConflict $conflict) {
            throw ValidationException::withMessages(['month' => __($conflict->getMessage(), $conflict->params)]);
        }
    }
}
