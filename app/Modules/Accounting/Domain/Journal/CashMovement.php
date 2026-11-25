<?php

namespace App\Modules\Accounting\Domain\Journal;

use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Money entering or leaving the church's cash against one income or expense account, in one or
 * more currencies, as one economic fact (a receipt, for instance). Currencies are never mixed or
 * converted: each one is its own pair of lines.
 *
 * An income debits CAJA and credits the account; an expense debits the account and credits CAJA.
 * The origin (type, id, purpose) names the fact, so the same one can never be posted twice.
 */
final readonly class CashMovement
{
    /** CAJA: the account every movement of cash goes through. */
    private const string CASH = 'A-001';

    /**
     * @param  array<string, Money>  $amounts  each currency's positive amount, keyed by its code
     */
    public function __construct(
        public MovementKind $kind,
        public string $accountCode,
        public array $amounts,
        public string $entryDate,
        public string $description,
        public string $originType,
        public string $originId,
        public string $originPurpose,
    ) {
        if ($kind === MovementKind::Exchange) {
            throw new InvalidArgumentException('A cash movement is an income or an expense, not an exchange.');
        }

        if ($amounts === []) {
            throw new InvalidArgumentException('A cash movement needs at least one amount.');
        }

        foreach ($amounts as $code => $amount) {
            if ($amount->currency()->value !== $code || ! $amount->isPositive()) {
                throw new InvalidArgumentException("The {$code} amount of a cash movement must be positive and in {$code}.");
            }
        }
    }

    /**
     * The currencies involved, in a fixed order (VES, USD, COP). Every caller that locks cash
     * takes the locks in this order, so two movements never wait for each other's.
     *
     * @return list<Currency>
     */
    public function currencies(): array
    {
        return array_values(array_filter(Currency::cases(), fn (Currency $currency) => isset($this->amounts[$currency->value])));
    }

    /**
     * The amount moved in a currency of this movement.
     */
    public function amount(Currency $currency): Money
    {
        return $this->amounts[$currency->value] ?? throw new InvalidArgumentException("The movement does not move {$currency->value}.");
    }

    /**
     * @return list<JournalLine>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->currencies() as $currency) {
            $amount = $this->amount($currency);

            array_push(
                $lines,
                ...($this->kind === MovementKind::Income
                    ? [JournalLine::debit(self::CASH, $amount), JournalLine::credit($this->accountCode, $amount)]
                    : [JournalLine::debit($this->accountCode, $amount), JournalLine::credit(self::CASH, $amount)]),
            );
        }

        return $lines;
    }
}
