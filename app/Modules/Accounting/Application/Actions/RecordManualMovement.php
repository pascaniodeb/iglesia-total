<?php

namespace App\Modules\Accounting\Application\Actions;

use App\Modules\Accounting\Application\Cash\AvailableCash;
use App\Modules\Accounting\Application\Cash\MovementGuard;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Accounting\Domain\Journal\JournalEntryDraft;
use App\Modules\Accounting\Domain\Journal\JournalLine;
use App\Modules\Accounting\Domain\Journal\LedgerConflict;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Accounting\Infrastructure\Models\JournalEntry;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records by hand an income (debit CAJA, credit I-xxx) or an expense (debit E-xxx, credit CAJA)
 * in one currency, in an open month (step 3 of the month circuit).
 *
 * An expense never takes more than the cash has (D4): the check and the write happen under the
 * currency's lock, so two expenses at the same time cannot spend the same money. The form's
 * token is the origin id: sending the same form twice records it once.
 */
final readonly class RecordManualMovement
{
    public function __construct(private PostJournalEntry $post, private MovementGuard $guard) {}

    /**
     * @param  array{kind: string, account_code: string, currency: string, amount: string, entry_date: string, description: string, token: string}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): JournalEntry
    {
        $kind = MovementKind::from($data['kind']);
        $currency = Currency::from($data['currency']);
        $amount = self::amount($data['amount'], $currency);
        $account = self::account($kind, $data['account_code']);

        $this->guard->assertOpenDate($data['entry_date']);

        return DB::transaction(function () use ($kind, $currency, $amount, $account, $data): JournalEntry {
            AvailableCash::lock($currency);
            $available = $this->guard->availableBefore($currency, $data['entry_date']);

            $lines = $kind === MovementKind::Income
                ? [JournalLine::debit('A-001', $amount), JournalLine::credit($account->code, $amount)]
                : [JournalLine::debit($account->code, $amount), JournalLine::credit('A-001', $amount)];

            try {
                $entry = ($this->post)(new JournalEntryDraft(
                    entryDate: $data['entry_date'],
                    description: trim($data['description']),
                    originType: $kind->originType(),
                    originId: $data['token'],
                    originPurpose: $kind->value,
                    lines: $lines,
                ));
            } catch (LedgerConflict $conflict) {
                throw ValidationException::withMessages(['account_code' => __($conflict->getMessage(), $conflict->params)]);
            }

            if ($kind === MovementKind::Expense) {
                $this->guard->assertNoOverdraft($currency, $data['entry_date'], $available, $amount);
            }

            return $entry;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function amount(string $raw, Currency $currency, string $field = 'amount'): Money
    {
        $value = str_replace(',', '.', trim($raw));

        try {
            $money = preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value) === 1 ? Money::of($value, $currency) : null;
        } catch (InvalidArgumentException) {
            $money = null;
        }

        if ($money === null || ! $money->isPositive()) {
            throw ValidationException::withMessages([$field => __('Write an amount greater than zero, with up to two decimals and no thousands separators.')]);
        }

        return $money;
    }

    /**
     * An active income account people can use (not the opening balance) or an active expense
     * account, matching the kind.
     *
     * @throws ValidationException
     */
    private static function account(MovementKind $kind, string $code): AccountingAccount
    {
        $type = $kind === MovementKind::Income ? AccountType::Income : AccountType::Expense;
        $account = AccountingAccount::query()->where('code', $code)->first();

        if ($account === null || $account->type !== $type || $account->isSystem() || ! $account->is_active) {
            throw ValidationException::withMessages(['account_code' => $kind === MovementKind::Income
                ? __('Choose an active income account.')
                : __('Choose an active expense account.')]);
        }

        return $account;
    }
}
