<?php

namespace App\Console\Commands;

use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Treasury\Application\Actions\CloseWeek;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Application\Closures\WeekSnapshot;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;

/**
 * Closes, in the new system, the weeks the old system had closed, and seals each one in the new
 * chain. Closing goes through the same action as a person closing a week, so every rule applies:
 * weeks close in order without gaps, from the week of the oldest offering.
 *
 * Before closing anything it checks ALL the weeks: they must follow each other from where the
 * book stands, and the offerings of each week, as imported, must add up to the totals the old
 * system closed with (count and the three currencies, to the cent). One week that does not match
 * stops the import with nothing closed. Idempotent: a week already closed is checked against the
 * old one and left alone. Run treasury:import-legacy-offerings first and open the sealed ledger
 * (ledger:genesis --continue-legacy) so each week is sealed as it closes.
 */
#[Signature('treasury:import-legacy-closures {--connection=legacy : Database connection of the old system} {--dry-run : Check every week and report, without closing}')]
#[Description('Close the weeks the old system had closed, sealing each one')]
class ImportLegacyWeeklyClosures extends Command
{
    public function handle(CloseWeek $close, ActivityLog $activityLog): int
    {
        $rows = DB::connection((string) $this->option('connection'))->table('weekly_closures')->whereNull('deleted_at')->orderBy('week_start')->get();
        $closed = $rows->where('status', 'closed')->values();
        $drafts = $rows->where('status', '!=', 'closed');

        $this->components->info("Legacy source: {$closed->count()} closed weeks.");

        foreach ($drafts as $draft) {
            $this->components->warn("Skipped: the week {$draft->week_key} is not closed in the old system ({$draft->status}).");
        }

        if (! $this->option('dry-run') && ! LedgerBlock::query()->exists()) {
            $this->components->error('The sealed ledger is not open: run ledger:genesis --continue-legacy first, so each week is sealed as it closes.');

            return self::FAILURE;
        }

        [$weeks, $problems, $already] = $this->preflight($closed);

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        if ($problems !== []) {
            $this->components->error(count($problems).' problems: no week was closed.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Weeks to close', (string) count($weeks));
        $this->components->twoColumnDetail('Already closed (equal to the old system)', (string) $already);

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was closed.');

            return self::SUCCESS;
        }

        $sealed = 0;

        foreach ($weeks as $week) {
            try {
                $result = $close($week);
            } catch (ValidationException $exception) {
                $this->components->error("Week {$week->key()}: ".collect($exception->errors())->flatten()->first());

                return self::FAILURE;
            }

            if (! $result['sealed']) {
                $this->components->error("Week {$week->key()} was closed but not sealed: {$result['seal_error']}");

                return self::FAILURE;
            }

            $sealed++;
        }

        if ($weeks !== []) {
            $activityLog->system(
                'weekly_closures.imported_legacy',
                __('Closed and sealed the :count weeks the old system had closed.', ['count' => $sealed]),
                details: ['weeks' => $sealed, 'first' => $weeks[0]->key(), 'last' => $weeks[array_key_last($weeks)]->key()],
            );
        }

        $this->components->twoColumnDetail('Closed and sealed now', (string) $sealed);
        $this->components->info('Weekly closures imported: every week equals the old system to the cent.');

        return self::SUCCESS;
    }

    /**
     * Every week of the old system, in order: which ones are still to close, and everything that
     * stops the import.
     *
     * @param  Collection<int, stdClass>  $closed
     * @return array{0: list<IsoWeek>, 1: list<string>, 2: int}
     */
    private function preflight($closed): array
    {
        $toClose = [];
        $problems = [];
        $already = 0;
        $expected = WeeklyClosureSchedule::next();

        foreach ($closed as $row) {
            try {
                $week = IsoWeek::fromKey((string) $row->week_key);
            } catch (InvalidArgumentException) {
                $problems[] = "The week key \"{$row->week_key}\" is not an ISO week.";

                continue;
            }

            if ($week->start->format('Y-m-d') !== (string) $row->week_start || $week->end()->format('Y-m-d') !== (string) $row->week_end) {
                $problems[] = "The week {$row->week_key} spans {$row->week_start} to {$row->week_end} in the old system, not the ISO week.";
            }

            $existing = WeeklyClosure::query()->where('week_key', $week->key())->first();
            $figures = $existing === null ? WeekSnapshot::of($week) : [
                'count' => $existing->offerings_count, 'ves' => $existing->total_ves, 'usd' => $existing->total_usd, 'cop' => $existing->total_cop,
            ];

            foreach (['ves' => Currency::VES, 'usd' => Currency::USD, 'cop' => Currency::COP] as $column => $currency) {
                $old = Money::of((string) $row->{"total_{$column}"}, $currency)->amount();

                if ($old !== $figures[$column]) {
                    $problems[] = "The week {$week->key()}: the old system closed with {$currency->value} {$old}, the offerings imported add up to {$figures[$column]}.";
                }
            }

            if ((int) $row->offerings_count !== (int) $figures['count']) {
                $problems[] = "The week {$week->key()}: the old system closed with {$row->offerings_count} offerings, the imported ones are {$figures['count']}.";
            }

            if ($existing !== null) {
                $already++;

                continue;
            }

            if ($expected === null || ! $expected->equals($week)) {
                $problems[] = "The week {$week->key()} does not follow the book: the next to close is ".($expected?->key() ?? 'none').' (weeks close in order, without gaps).';

                continue;
            }

            $toClose[] = $week;
            $expected = $week->next();
        }

        return [$toClose, $problems, $already];
    }
}
