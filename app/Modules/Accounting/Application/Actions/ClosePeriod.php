<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Application\Actions\SealPeriod;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Shared\Time\Month;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes an accounting month (step 4 of the month circuit): snapshots the balance of every
 * account and currency at the end of the month, locks the month forever and seals it in the
 * sealed ledger. The next month's opening balance is that closing (D3).
 *
 * The close commits first (under a lock, every rule re-checked, PostgreSQL checking the snapshot
 * against the journal to the cent); the seal comes after and a failure leaves the month "seal
 * pending", never undoes the close.
 */
final readonly class ClosePeriod
{
    public function __construct(
        private PeriodSchedule $schedule,
        private ActivityLog $activityLog,
        private SealPeriod $seal,
        private AuthFactory $auth,
    ) {}

    /**
     * @return array{period: AccountingPeriod, sealed: bool, seal_error: string|null}
     *
     * @throws ValidationException
     */
    public function __invoke(Month $month): array
    {
        $period = DB::transaction(function () use ($month): AccountingPeriod {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('accounting_periods'))");

            $blocker = $this->schedule->blocker($month);

            if ($blocker !== null) {
                throw ValidationException::withMessages(['month' => $blocker]);
            }

            $actorId = $this->auth->guard()->id();
            $period = new AccountingPeriod;
            $period->forceFill(['month' => $month->key(), 'closed_by' => $actorId === null ? null : (string) $actorId])->save();

            DB::statement(<<<'SQL'
                INSERT INTO accounting_period_balances (accounting_period_id, account_id, currency, total_debit, total_credit, balance)
                SELECT ?, l.account_id, l.currency, sum(l.debit), sum(l.credit),
                       CASE WHEN a.type IN ('asset', 'expense') THEN sum(l.debit) - sum(l.credit) ELSE sum(l.credit) - sum(l.debit) END
                FROM journal_entry_lines l
                JOIN journal_entries e ON e.id = l.journal_entry_id
                JOIN accounting_accounts a ON a.id = l.account_id
                WHERE e.entry_date <= ?
                GROUP BY l.account_id, l.currency, a.type
            SQL, [$period->id, $month->lastDay()->format('Y-m-d')]);

            // The snapshot is checked against the journal at commit; ask for it now.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');

            $summary = MonthSummary::of($month);

            $this->activityLog->record(
                'accounting_period.closed',
                __('Closed the accounting month :month.', ['month' => $month->key()]),
                $period,
                ['currencies' => $summary['currencies']],
            );

            return $period->refresh();
        });

        try {
            ($this->seal)('monthly', $month->key());

            return ['period' => $period, 'sealed' => true, 'seal_error' => null];
        } catch (LedgerException $exception) {
            return ['period' => $period, 'sealed' => false, 'seal_error' => __($exception->getMessage(), $exception->params)];
        }
    }
}
