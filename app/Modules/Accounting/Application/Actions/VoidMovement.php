<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Cash\MovementGuard;
use App\Modules\Accounting\Domain\Enums\SystemFunction;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\ChurchCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a movement recorded by hand with a reversal and its reason (nothing is edited or
 * deleted). The reversal is dated like the original while its month is open; once that month
 * is closed, today (D7: a closed month never changes). Reversing an income takes money out of
 * the cash, so D4 applies.
 *
 * Automatic entries (offerings, receipts) are corrected where they came from, not here.
 */
final readonly class VoidMovement
{
    public const array MANUAL_ORIGINS = ['manual_movement', 'currency_exchange'];

    public function __construct(private ReverseJournalEntry $reverse, private MovementGuard $guard) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(JournalEntry $entry, string $reason): JournalEntry
    {
        if (! in_array($entry->origin_type, self::MANUAL_ORIGINS, true) || $entry->reversal_of !== null) {
            throw ValidationException::withMessages(['entry' => __('Only movements recorded by hand can be voided here.')]);
        }

        if ($entry->reversal()->exists()) {
            throw ValidationException::withMessages(['entry' => __('This movement was already voided.')]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Write why the movement is voided.')]);
        }

        $date = AccountingPeriod::isClosed($entry->month) ? ChurchCalendar::today()->format('Y-m-d') : $entry->entry_date->toDateString();
        $this->guard->assertOpenDate($date, 'entry');

        return DB::transaction(function () use ($entry, $reason, $date): JournalEntry {
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

            return $reversal;
        });
    }

    /**
     * Per currency, what the reversal takes out of the cash (the original's cash debits).
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

        return $leaving;
    }
}
