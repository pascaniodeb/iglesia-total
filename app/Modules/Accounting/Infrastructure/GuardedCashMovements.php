<?php

namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Accounting\Application\Actions\PostJournalEntry;
use App\Modules\Accounting\Application\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Cash\MovementGuard;
use App\Modules\Accounting\Application\CashMovements;
use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Domain\Journal\CashMovement;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Domain\Journal\PostedEntry;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\Month;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The CashMovements contract backed by the PostgreSQL journal: the rules of a movement recorded
 * by hand (open month, no overdraft, voiding by reversal), for any origin and any number of
 * currencies in one entry.
 */
final readonly class GuardedCashMovements implements CashMovements
{
    public function __construct(
        private PostJournalEntry $post,
        private ReverseJournalEntry $reverse,
        private MovementGuard $guard,
        private AvailableCash $cash,
        private PeriodSchedule $schedule,
    ) {}

    public function record(CashMovement $movement): PostedEntry
    {
        $this->guard->assertOpenDate($movement->entryDate);
        $this->guard->usableAccount($movement->kind, $movement->accountCode);

        return DB::transaction(function () use ($movement): PostedEntry {
            // One lock per currency, always in the same order: two movements never wait on each other.
            foreach ($movement->currencies() as $currency) {
                AvailableCash::lock($currency);
            }

            // What each currency had before the write, in the order the locks were taken.
            $before = [];

            foreach ($movement->currencies() as $currency) {
                $before[] = [$currency, $this->guard->availableBefore($currency, $movement->entryDate)];
            }

            try {
                $entry = ($this->post)(new JournalEntryDraft(
                    entryDate: $movement->entryDate,
                    description: $movement->description,
                    originType: $movement->originType,
                    originId: $movement->originId,
                    originPurpose: $movement->originPurpose,
                    lines: $movement->lines(),
                ));
            } catch (LedgerConflict $conflict) {
                throw ValidationException::withMessages(['account_code' => __($conflict->getMessage(), $conflict->params)]);
            }

            if ($movement->kind === MovementKind::Expense) {
                foreach ($before as [$currency, $available]) {
                    $this->guard->assertNoOverdraft(
                        $currency,
                        $movement->entryDate,
                        $available,
                        $movement->amount($currency),
                        'amount_'.strtolower($currency->value),
                    );
                }
            }

            return self::posted($entry);
        });
    }

    public function reverse(string $entryId, string $reason): PostedEntry
    {
        $entry = JournalEntry::query()->find($entryId);

        if ($entry === null) {
            throw ValidationException::withMessages(['entry' => __('There is no journal entry :id.', ['id' => $entryId])]);
        }

        if ($entry->reversal()->exists()) {
            throw ValidationException::withMessages(['entry' => __('This movement was already voided.')]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Write why the movement is voided.')]);
        }

        // A closed month never changes (D7): its reversal is dated today, in an open month.
        $date = AccountingPeriod::isClosed($entry->month) ? ChurchCalendar::today()->format('Y-m-d') : $entry->entry_date->toDateString();
        $this->guard->assertOpenDate($date, 'entry');

        return DB::transaction(function () use ($entry, $reason, $date): PostedEntry {
            $leaving = $this->cashThatLeaves($entry);

            foreach (array_keys($leaving) as $code) {
                AvailableCash::lock(Currency::from($code));
            }

            $available = array_map(fn (Money $money) => $this->guard->availableBefore($money->currency(), $date), $leaving);

            try {
                $reversal = ($this->reverse)($entry, $reason, $date);
            } catch (LedgerConflict $conflict) {
                throw ValidationException::withMessages(['entry' => __($conflict->getMessage(), $conflict->params)]);
            }

            foreach ($leaving as $code => $amount) {
                $this->guard->assertNoOverdraft($amount->currency(), $date, $available[$code], $amount, 'entry');
            }

            return self::posted($reversal);
        });
    }

    public function available(string $day): array
    {
        $available = [];

        foreach (Currency::cases() as $currency) {
            $available[$currency->value] = $this->cash->on($currency, $day)->amount();
        }

        return $available;
    }

    public function window(): array
    {
        $from = $this->schedule->openWindow()['from'];

        return [
            'min' => $from === null ? null : Month::fromKey($from)->firstDay->format('Y-m-d'),
            'max' => ChurchCalendar::today()->format('Y-m-d'),
        ];
    }

    /**
     * Per currency (always in the order VES, USD, COP), what the reversal takes out of the cash:
     * the original's cash debits.
     *
     * @return array<string, Money>
     */
    private function cashThatLeaves(JournalEntry $entry): array
    {
        $entry->loadMissing('lines.account');
        $leaving = [];

        foreach ($entry->lines as $line) {
            if ($line->account->system_function === SystemFunction::Cash && $line->debitMoney()->isPositive()) {
                $code = $line->currency->value;
                $leaving[$code] = ($leaving[$code] ?? Money::zero($line->currency))->plus($line->debitMoney());
            }
        }

        $ordered = [];

        foreach (Currency::cases() as $currency) {
            if (isset($leaving[$currency->value])) {
                $ordered[$currency->value] = $leaving[$currency->value];
            }
        }

        return $ordered;
    }

    private static function posted(JournalEntry $entry): PostedEntry
    {
        return new PostedEntry($entry->id, $entry->entry_date->toDateString(), $entry->month, ! $entry->wasRecentlyCreated);
    }
}
