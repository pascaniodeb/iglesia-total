<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Application\Actions\ClosePeriod;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Accounting\Domain\Journal\CashMovement;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Application\ActivityLog;
use App\Modules\Integrity\Infrastructure\Models\LedgerBlock;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Actions\CloseMonthOfferings;
use App\Modules\Treasury\Application\Closures\MonthOfferingSnapshot;
use App\Modules\Treasury\Infrastructure\Models\MonthlyOfferingClosure;
use App\Modules\Treasury\Infrastructure\Models\WeeklyClosure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Brings the books of the old system into the journal, month by month, and closes the months the
 * old system had closed (and every month before the last of them: months close in order).
 *
 * What each legacy entry becomes:
 * - the entries of the offering consolidation (linked to a monthly closure) are NOT imported: the
 *   new monthly offering closure posts the same income from the offerings, and the two must agree
 *   by account code to the cent;
 * - the first month's I-001 ("saldo anterior") becomes the opening entry (capital of the books
 *   before this system); every later I-001 is NOT imported, it is compared with the opening
 *   balance the new books derive (the previous month's closing): any difference stops the import;
 * - every other live entry becomes one journal entry (debit CAJA / credit the income account, or
 *   debit the expense account / credit CAJA, one pair of lines per currency it has), origin
 *   legacy_entry. History goes in as it is, negative balances included, so it does not go through
 *   the overdraft rule that applies from the cut-over on;
 * - the entries in the old trash are listed and not imported.
 *
 * Before writing anything, everything that can be checked is checked. Then, month by month, the
 * irreversible step (the accounting close, which seals the month) only happens after the month's
 * opening balance and its income and expense per account code equal the old system's, to the
 * cent. A run that stops can be run again: what is done is left alone.
 */
#[Signature('accounting:import-legacy-books {--connection=legacy : Database connection of the old system} {--dry-run : Check everything and report, without writing or closing}')]
#[Description('Import the old system\'s journal entries and close its closed months')]
class ImportLegacyBooks extends Command
{
    private const array CURRENCIES = ['ves' => Currency::VES, 'usd' => Currency::USD, 'cop' => Currency::COP];

    private const string OPENING = 'I-001';

    /** @var list<string> */
    private array $problems = [];

    public function handle(Ledger $ledger, CloseMonthOfferings $closeOfferings, ClosePeriod $closePeriod, ActivityLog $activityLog): int
    {
        $legacy = DB::connection((string) $this->option('connection'));
        $codes = $legacy->table('account_codes')->get()->keyBy('id');
        $entries = $legacy->table('accounting_entries')->orderBy('entry_date')->orderBy('id')->get()
            ->each(function (stdClass $entry) use ($codes): void {
                $entry->code = (string) $codes[$entry->account_code_id]->code;
                $entry->account_name = (string) $codes[$entry->account_code_id]->name;
            });
        $closures = $legacy->table('monthly_closures')->whereNull('deleted_at')->get()->keyBy('month_key');

        $live = $entries->whereNull('deleted_at');
        $trashed = $entries->whereNotNull('deleted_at');
        $consolidated = $live->whereNotNull('monthly_closure_id');
        $openings = $live->whereNull('monthly_closure_id')->where('code', self::OPENING);
        $manual = $live->whereNull('monthly_closure_id')->where('code', '!=', self::OPENING);

        $this->components->info("Legacy source: {$live->count()} live entries ({$manual->count()} manual, {$consolidated->count()} offering consolidations, {$openings->count()} opening balances), {$trashed->count()} in the trash (not imported).");

        $closedMonths = $closures->where('status', 'closed')->keys()->sort()->values();
        $first = $live->isEmpty() ? null : (string) $live->min('month_key');
        $lastToClose = $closedMonths->isEmpty() ? null : (string) $closedMonths->last();

        if ($first === null || $lastToClose === null) {
            $this->components->error('The old system has no closed month to bring.');

            return self::FAILURE;
        }

        $months = $this->monthsBetween($first, $lastToClose);
        $afterMonths = $live->pluck('month_key')->unique()->filter(fn ($key): bool => $key > $lastToClose)->sort()->values();
        $draft = collect($months)->filter(fn (string $key): bool => ($closures[$key]->status ?? null) !== 'closed')->values();

        $accounts = AccountingAccount::query()->get()->keyBy('code');

        $this->preflight($manual, $openings, $consolidated, $closures, $months, $first, $accounts);

        foreach ($this->problems as $problem) {
            $this->components->error($problem);
        }

        if ($this->problems !== []) {
            $this->components->error(count($this->problems).' problems: nothing was written.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Months to close', count($months).' ('.$first.' to '.$lastToClose.')');

        if ($draft->isNotEmpty()) {
            $this->components->warn('Closed retroactively, because months close in order: '.$draft->implode(', ').' (never closed in the old system).');
        }

        foreach ($trashed as $entry) {
            $this->components->twoColumnDetail("Skipped (in the old trash): #{$entry->id} of {$entry->entry_date} {$entry->code}", "VES {$entry->amount_ves} · USD {$entry->amount_usd} · COP {$entry->amount_cop}");
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if (! LedgerBlock::query()->exists()) {
            $this->components->error('The sealed ledger is not open: run ledger:genesis --continue-legacy first, so each month is sealed as it closes.');

            return self::FAILURE;
        }

        $imported = 0;
        $closedNow = 0;

        foreach ([...$months, ...$afterMonths->all()] as $key) {
            $month = Month::fromKey($key);
            $willClose = in_array($key, $months, true);

            if ($willClose && AccountingPeriod::isClosed($key)) {
                $this->components->twoColumnDetail($key, 'already closed');

                continue;
            }

            $row = $this->month($month, $key === $first, $manual, $openings, $consolidated, $accounts, $ledger, $closeOfferings, $willClose);

            if ($row === null) {
                return self::FAILURE;
            }

            $imported += $row['imported'];

            if ($willClose) {
                $result = $closePeriod($month);

                if (! $result['sealed']) {
                    $this->components->error("The month {$key} was closed but not sealed: {$result['seal_error']}");

                    return self::FAILURE;
                }

                $closedNow++;
            }

            $this->components->twoColumnDetail($key, $row['detail'].($willClose ? ' · closed and sealed' : ' · left open'));
        }

        $activityLog->system(
            'journal.imported_legacy',
            __('Imported the books of the old system: :entries entries and :months months closed.', ['entries' => $imported, 'months' => $closedNow]),
            details: [
                'entries' => $imported,
                'months_closed' => $closedNow,
                'closed_without_being_closed_in_the_old_system' => $draft->all(),
                'skipped_in_trash' => $trashed->pluck('id')->all(),
            ],
        );

        $this->components->twoColumnDetail('Entries imported now', (string) $imported);
        $this->components->twoColumnDetail('Months closed and sealed now', (string) $closedNow);
        $this->components->info('Books imported: every opening balance and every month by account code equals the old system to the cent.');

        return self::SUCCESS;
    }

    /**
     * Everything that can stop the import and does not need a write to be seen.
     *
     * @param  Collection<int, stdClass>  $manual
     * @param  Collection<int, stdClass>  $openings
     * @param  Collection<int, stdClass>  $consolidated
     * @param  Collection<string, stdClass>  $closures
     * @param  list<string>  $months
     * @param  Collection<string, AccountingAccount>  $accounts
     */
    private function preflight(Collection $manual, Collection $openings, Collection $consolidated, Collection $closures, array $months, string $first, Collection $accounts): void
    {
        foreach (['A-001', self::OPENING] as $system) {
            if (! $accounts->has($system)) {
                $this->problems[] = "The account {$system} does not exist: run migrations first.";
            }
        }

        foreach ($manual->pluck('code')->merge($consolidated->pluck('code'))->unique() as $code) {
            $account = $accounts->get($code);

            if ($account === null || ! $account->is_active) {
                $this->problems[] = "The account {$code} used by the old entries is missing or inactive in the new chart: run accounting:import-legacy-accounts first.";
            }
        }

        foreach ($manual as $entry) {
            if ($this->amountsOf($entry)->contains(fn (Money $money): bool => $money->isNegative()) || $this->amountsOf($entry)->every(fn (Money $money): bool => $money->isZero())) {
                $this->problems[] = "The entry #{$entry->id} of {$entry->entry_date} ({$entry->code}) has no positive amount or has a negative one.";
            }

            if ($entry->type === 'income' ? $accounts->get($entry->code)?->type->value !== 'income' : $accounts->get($entry->code)?->type->value !== 'expense') {
                $this->problems[] = "The entry #{$entry->id} is {$entry->type} but the account {$entry->code} is not.";
            }
        }

        foreach ($openings->groupBy('month_key') as $key => $rows) {
            if ($rows->count() > 1) {
                $this->problems[] = "The month {$key} has {$rows->count()} live opening balances in the old system: expected one.";
            }
        }

        $firstOpening = $openings->firstWhere('month_key', $first);

        if ($firstOpening !== null && $this->amountsOf($firstOpening)->contains(fn (Money $money): bool => $money->isNegative())) {
            $this->problems[] = 'The opening balance of the first month is negative: it cannot be brought as capital.';
        }

        foreach ($months as $key) {
            $month = Month::fromKey($key);

            foreach ($month->weeks() as $week) {
                if (! WeeklyClosure::isClosed($week->key()) && $week->start >= $this->firstWeekStart()) {
                    $this->problems[] = "The week {$week->key()} (of {$key}) is not closed yet: run treasury:import-legacy-closures first.";
                }
            }

            $this->checkConsolidation($month, $consolidated, $closures->get($key), $accounts);
        }
    }

    /**
     * What the offerings add up to, by account code, must be what the old system consolidated.
     *
     * @param  Collection<int, stdClass>  $consolidated
     * @param  Collection<string, AccountingAccount>  $accounts
     */
    private function checkConsolidation(Month $month, Collection $consolidated, ?stdClass $closure, Collection $accounts): void
    {
        $snapshot = MonthOfferingSnapshot::of($month);
        $byId = $accounts->keyBy('id');

        $expected = [];

        foreach ($snapshot['categories'] as $category) {
            $code = $byId[$category['account_id']]->code ?? $category['account_id'];

            foreach (self::CURRENCIES as $column => $currency) {
                $expected[$code][$column] = ($expected[$code][$column] ?? Money::zero($currency))->plus(Money::of($category[$column], $currency));
            }
        }

        $legacy = [];

        foreach ($consolidated->where('month_key', $month->key()) as $entry) {
            foreach (self::CURRENCIES as $column => $currency) {
                $legacy[$entry->code][$column] = ($legacy[$entry->code][$column] ?? Money::zero($currency))->plus(Money::of((string) $entry->{"amount_{$column}"}, $currency));
            }
        }

        foreach (array_unique([...array_keys($expected), ...array_keys($legacy)]) as $code) {
            foreach (self::CURRENCIES as $column => $currency) {
                $new = ($expected[$code][$column] ?? Money::zero($currency))->amount();
                $old = ($legacy[$code][$column] ?? Money::zero($currency))->amount();

                if ($new !== $old) {
                    $this->problems[] = "The month {$month->key()}, {$code}, {$currency->value}: the old system consolidated {$old}, the offerings imported add up to {$new}.";
                }
            }
        }

        if ($closure !== null && ((int) $closure->offerings_count !== $snapshot['count'])) {
            $this->problems[] = "The month {$month->key()}: the old system closed with {$closure->offerings_count} offerings, the imported ones are {$snapshot['count']}.";
        }
    }

    /**
     * One month: its opening balance, its offerings closure, its entries, and the checks that
     * must hold before it can be closed. Returns null (after saying why) when one fails.
     *
     * @param  Collection<int, stdClass>  $manual
     * @param  Collection<int, stdClass>  $openings
     * @param  Collection<int, stdClass>  $consolidated
     * @param  Collection<string, AccountingAccount>  $accounts
     * @return array{imported: int, detail: string}|null
     */
    private function month(Month $month, bool $isFirst, Collection $manual, Collection $openings, Collection $consolidated, Collection $accounts, Ledger $ledger, CloseMonthOfferings $closeOfferings, bool $closesOfferings): ?array
    {
        $key = $month->key();
        $opening = $openings->firstWhere('month_key', $key);
        $imported = 0;

        if ($isFirst) {
            if ($opening !== null) {
                $imported += $this->post($ledger, $opening, 'opening', 'I-001', $key) ? 1 : 0;
            }
        } else {
            $derived = MonthSummary::of($month)['currencies'];

            foreach (self::CURRENCIES as $column => $currency) {
                $old = $opening === null ? '0.00' : Money::of((string) $opening->{"amount_{$column}"}, $currency)->amount();

                if ($derived[$currency->value]['opening'] !== $old) {
                    $this->components->error("The month {$key}: the old system opens with {$currency->value} {$old}, the new books derive {$derived[$currency->value]['opening']} from the previous closing. Nothing more was written.");

                    return null;
                }
            }
        }

        if ($closesOfferings && ! MonthlyOfferingClosure::query()->where('month', $key)->exists()) {
            try {
                $closeOfferings($month);
            } catch (ValidationException $exception) {
                $this->components->error("The offerings of {$key} could not be closed: ".collect($exception->errors())->flatten()->first());

                return null;
            }
        }

        foreach ($manual->where('month_key', $key) as $entry) {
            try {
                $imported += $this->post($ledger, $entry, $entry->type, $entry->code, $key) ? 1 : 0;
            } catch (LedgerConflict $conflict) {
                $this->components->error("The entry #{$entry->id} of {$key} cannot be posted: ".__($conflict->getMessage(), $conflict->params));

                return null;
            }
        }

        $difference = $this->monthDifference($month, $manual->merge($consolidated)->where('month_key', $key));

        if ($difference !== null) {
            $this->components->error("The month {$key}, {$difference} Nothing more was written.");

            return null;
        }

        $summary = MonthSummary::of($month)['currencies'];

        $count = $manual->where('month_key', $key)->count();

        return [
            'imported' => $imported,
            'detail' => "{$count} ".($count === 1 ? 'entry' : 'entries')." · opening ✓ · by code ✓ · closing COP {$summary['COP']['closing']}",
        ];
    }

    /**
     * Income and expense per account code and currency: the new books against the old system's.
     *
     * @param  Collection<int, stdClass>  $legacyRows  live entries of the month, openings excluded
     */
    private function monthDifference(Month $month, Collection $legacyRows): ?string
    {
        $legacy = [];

        foreach ($legacyRows as $entry) {
            foreach (self::CURRENCIES as $column => $currency) {
                $legacy[$entry->code][$column] = ($legacy[$entry->code][$column] ?? Money::zero($currency))->plus(Money::of((string) $entry->{"amount_{$column}"}, $currency));
            }
        }

        $new = [];

        foreach (MonthSummary::of($month)['by_code'] as $row) {
            foreach (self::CURRENCIES as $column => $currency) {
                $new[$row['code']][$column] = Money::of($row[$column], $currency);
            }
        }

        foreach (array_unique([...array_keys($legacy), ...array_keys($new)]) as $code) {
            foreach (self::CURRENCIES as $column => $currency) {
                $old = ($legacy[$code][$column] ?? Money::zero($currency))->amount();
                $now = ($new[$code][$column] ?? Money::zero($currency))->amount();

                if ($old !== $now) {
                    return "{$code} in {$currency->value}: the old system has {$old}, the new books {$now}.";
                }
            }
        }

        return null;
    }

    /**
     * Posts one legacy entry (idempotent by origin). Returns whether it was written now.
     *
     * @throws LedgerConflict
     */
    private function post(Ledger $ledger, stdClass $entry, string $purpose, string $code, string $month): bool
    {
        $amounts = [];

        foreach (self::CURRENCIES as $column => $currency) {
            $money = Money::of((string) $entry->{"amount_{$column}"}, $currency);

            if ($money->isPositive()) {
                $amounts[$currency->value] = $money;
            }
        }

        $description = trim((string) ($entry->concept ?: $entry->description ?: $entry->account_name));
        $movement = new CashMovement(
            kind: $purpose === 'expense' ? MovementKind::Expense : MovementKind::Income,
            accountCode: $code,
            amounts: $amounts,
            entryDate: (string) $entry->entry_date,
            description: mb_substr($description, 0, 255),
            originType: 'legacy_entry',
            originId: (string) $entry->id,
            originPurpose: $purpose,
        );

        return ! $ledger->post(new JournalEntryDraft(
            entryDate: $movement->entryDate,
            description: $movement->description,
            originType: $movement->originType,
            originId: $movement->originId,
            originPurpose: $movement->originPurpose,
            lines: $movement->lines(),
        ))->wasAlreadyPosted;
    }

    /**
     * @return Collection<string, Money>
     */
    private function amountsOf(stdClass $entry): Collection
    {
        return collect(self::CURRENCIES)->map(fn (Currency $currency, string $column): Money => Money::of((string) $entry->{"amount_{$column}"}, $currency));
    }

    /**
     * @return list<string>
     */
    private function monthsBetween(string $first, string $last): array
    {
        $months = [];

        for ($month = Month::fromKey($first); $month->key() <= $last; $month = $month->next()) {
            $months[] = $month->key();
        }

        return $months;
    }

    /**
     * The first day a weekly closure covers: weeks before the first closure were never part of
     * the circuit.
     */
    private function firstWeekStart(): \DateTimeImmutable
    {
        $first = WeeklyClosure::query()->min('week_start');

        return new \DateTimeImmutable((string) ($first ?? '9999-12-31'));
    }
}
