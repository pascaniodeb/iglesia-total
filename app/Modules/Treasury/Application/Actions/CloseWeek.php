<?php

namespace App\Modules\Treasury\Application\Actions;

use App\Modules\Integrity\Application\Actions\SealPeriod;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Domain\Ledger\LedgerException;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Application\Closures\WeekSnapshot;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a week (step 1 of the month circuit): snapshots its offerings, locks them forever and
 * then seals the week in the sealed ledger.
 *
 * The close and the seal are separate on purpose: the close commits first (under a lock, with
 * every rule re-checked inside the transaction, and the database checking the snapshot to the
 * cent); a seal that fails afterwards never undoes it, the week stays "seal pending" and is
 * sealed later (ledger:seal-pending, every night).
 */
final readonly class CloseWeek
{
    public function __construct(
        private ActivityLog $activityLog,
        private SealPeriod $seal,
        private AuthFactory $auth,
    ) {}

    /**
     * @return array{closure: WeeklyClosure, sealed: bool, seal_error: string|null}
     *
     * @throws ValidationException
     */
    public function __invoke(IsoWeek $week): array
    {
        $closure = DB::transaction(function () use ($week): WeeklyClosure {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('weekly_closures'))");

            $blocker = WeeklyClosureSchedule::blocker($week);

            if ($blocker !== null) {
                throw ValidationException::withMessages(['week' => $blocker]);
            }

            $snapshot = WeekSnapshot::of($week);
            $actorId = $this->auth->guard()->id();

            $closure = new WeeklyClosure;
            $closure->forceFill([
                'week_key' => $week->key(),
                'week_start' => $week->start->format('Y-m-d'),
                'week_end' => $week->end()->format('Y-m-d'),
                'offerings_count' => $snapshot['count'],
                'total_ves' => $snapshot['ves'],
                'total_usd' => $snapshot['usd'],
                'total_cop' => $snapshot['cop'],
                'closed_by' => $actorId === null ? null : (string) $actorId,
            ])->save();

            $this->activityLog->record(
                'weekly_closure.closed',
                __('Closed the week :week (:from to :to): :count offerings.', [
                    'week' => $week->key(),
                    'from' => $week->start->format('d/m/Y'),
                    'to' => $week->end()->format('d/m/Y'),
                    'count' => $snapshot['count'],
                ]),
                $closure,
                ['totals' => ['ves' => $snapshot['ves'], 'usd' => $snapshot['usd'], 'cop' => $snapshot['cop']], 'count' => $snapshot['count']],
            );

            return $closure->refresh();
        });

        try {
            ($this->seal)('weekly', $week->key());

            return ['closure' => $closure, 'sealed' => true, 'seal_error' => null];
        } catch (LedgerException $exception) {
            return ['closure' => $closure, 'sealed' => false, 'seal_error' => __($exception->getMessage(), $exception->params)];
        }
    }
}
