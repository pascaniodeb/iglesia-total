<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Cash\MovementGuard;
use App\Modules\Accounting\Domain\Enums\MovementKind;
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
 * Corrects an income or expense recorded by hand: a reversal of the original (with the reason)
 * and the corrected movement, in one transaction. Nothing is edited: the original, the reversal,
 * the correction, who and when all stay.
 *
 * D4 is judged on the result, not step by step: correcting an income of 100 to 90 when 95 were
 * spent is refused, and to 96 is accepted. Only the currencies whose cash goes down are checked.
 */
final readonly class CorrectMovement
{
    public function __construct(
        private ReverseJournalEntry $reverse,
        private RecordManualMovement $record,
        private MovementGuard $guard,
        private AvailableCash $cash,
    ) {}

    /**
     * @param  array{account_code: string, currency: string, amount: string, entry_date: string, description: string, token: string, reason: string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(JournalEntry $original, array $data): JournalEntry
    {
        if ($original->origin_type !== 'manual_movement' || $original->reversal_of !== null || $original->reversal()->exists()) {
            throw ValidationException::withMessages(['entry' => __('Only an income or expense recorded by hand, not voided, can be corrected.')]);
        }

        if (trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => __('Write why the movement is corrected.')]);
        }

        $kind = MovementKind::from($original->origin_purpose);
        $reversalDate = AccountingPeriod::isClosed($original->month) ? ChurchCalendar::today()->format('Y-m-d') : $original->entry_date->toDateString();
        $this->guard->assertOpenDate($reversalDate, 'entry');
        $this->guard->assertOpenDate($data['entry_date']);

        return DB::transaction(function () use ($original, $data, $kind, $reversalDate): JournalEntry {
            $before = $this->cashByCurrency($original);
            $newCurrency = Currency::from($data['currency']);
            $currencies = array_unique([...array_keys($before), $newCurrency->value]);
            sort($currencies);

            foreach ($currencies as $code) {
                AvailableCash::lock(Currency::from($code));
            }

            $from = min($reversalDate, $data['entry_date']);
            $available = [];

            foreach ($currencies as $code) {
                $available[$code] = $this->cash->on(Currency::from($code), $from);
            }

            try {
                ($this->reverse)($original, $data['reason'], $reversalDate);
            } catch (LedgerConflict $conflict) {
                throw ValidationException::withMessages(['entry' => __($conflict->getMessage(), $conflict->params)]);
            }

            $corrected = ($this->record)([
                'kind' => $kind->value,
                'account_code' => $data['account_code'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'token' => $data['token'],
            ]);

            $after = $this->cashByCurrency($corrected);

            foreach ($currencies as $code) {
                $currency = Currency::from($code);
                $change = ($after[$code] ?? Money::zero($currency))->minus($before[$code] ?? Money::zero($currency));

                if ($change->isNegative()) {
                    $this->guard->assertNoOverdraft($currency, $from, $available[$code], $change->negated());
                }
            }

            return $corrected;
        });
    }

    /**
     * Net effect of an entry on the cash, per currency (debits minus credits on CAJA).
     *
     * @return array<string, Money>
     */
    private function cashByCurrency(JournalEntry $entry): array
    {
        $entry->loadMissing('lines.account');
        $effect = [];

        foreach ($entry->lines as $line) {
            if ($line->account->system_function === SystemFunction::Cash) {
                $code = $line->currency->value;
                $effect[$code] = ($effect[$code] ?? Money::zero($line->currency))->plus($line->debitMoney())->minus($line->creditMoney());
            }
        }

        return $effect;
    }
}
