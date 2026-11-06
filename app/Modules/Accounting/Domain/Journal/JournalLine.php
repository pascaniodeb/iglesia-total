<?php

namespace App\Modules\Accounting\Domain\Journal;

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/**
 * One line of a journal entry: a debit or a credit to an account, in one currency. Exactly one
 * side is positive. The account is named by its code: codes never change, so a line means the
 * same thing forever.
 */
final readonly class JournalLine
{
    public Currency $currency;

    public function __construct(
        public string $accountCode,
        public Money $debit,
        public Money $credit,
        public ?string $description = null,
    ) {
        if (preg_match('/^[A-Z]-[0-9]{3}$/', $this->accountCode) !== 1) {
            throw new InvalidArgumentException("Not an account code: \"{$this->accountCode}\".");
        }

        if ($this->debit->currency() !== $this->credit->currency()) {
            throw new InvalidArgumentException('Debit and credit of a line must be in the same currency.');
        }

        if ($this->debit->isNegative() || $this->credit->isNegative()) {
            throw new InvalidArgumentException('A journal line does not accept negative amounts.');
        }

        if ($this->debit->isZero() === $this->credit->isZero()) {
            throw new InvalidArgumentException('Each line has a positive amount on the debit or on the credit side, never both or neither.');
        }

        if ($this->description !== null && trim($this->description) === '') {
            throw new InvalidArgumentException('The description of a line cannot be blank.');
        }

        $this->currency = $this->debit->currency();
    }

    public static function debit(string $accountCode, Money $amount, ?string $description = null): self
    {
        return new self($accountCode, $amount, Money::zero($amount->currency()), $description);
    }

    public static function credit(string $accountCode, Money $amount, ?string $description = null): self
    {
        return new self($accountCode, Money::zero($amount->currency()), $amount, $description);
    }

    /** The same line on the opposite side: what a reversal posts. */
    public function inverted(): self
    {
        return new self($this->accountCode, $this->credit, $this->debit, $this->description);
    }
}
