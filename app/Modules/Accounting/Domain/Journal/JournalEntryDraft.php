<?php

namespace App\Modules\Accounting\Domain\Journal;

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A validated proposal of a journal entry, before it is posted.
 *
 * It enforces in plain PHP the same rules the database enforces at commit: at least two lines
 * and, for every currency present, debits = credits > 0. The origin (type, id, purpose) names
 * the economic fact the entry records, so the same fact can never be posted twice.
 */
final readonly class JournalEntryDraft
{
    public string $month;

    /**
     * @param  list<JournalLine>  $lines
     */
    public function __construct(
        public string $entryDate,
        public string $description,
        public string $originType,
        public string $originId,
        public string $originPurpose,
        public array $lines,
    ) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->entryDate);

        if ($date === false || $date->format('Y-m-d') !== $this->entryDate) {
            throw new InvalidArgumentException("Not a valid entry date: \"{$this->entryDate}\".");
        }

        if (trim($this->description) === '' || mb_strlen($this->description) > 255) {
            throw new InvalidArgumentException('A journal entry needs a description of at most 255 characters.');
        }

        foreach ([$this->originType, $this->originPurpose] as $identifier) {
            if (preg_match('/^[a-z0-9]+(_[a-z0-9]+)*$/', $identifier) !== 1) {
                throw new InvalidArgumentException("Origin type and purpose are lowercase identifiers: \"{$identifier}\".");
            }
        }

        if (trim($this->originId) === '') {
            throw new InvalidArgumentException('A journal entry needs the id of its origin.');
        }

        if (count($this->lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $this->assertBalancedByCurrency();
        $this->month = substr($this->entryDate, 0, 7);
    }

    /**
     * Debit total per currency present in the entry (equal to its credit total).
     *
     * @return array<string, Money>
     */
    public function totalsByCurrency(): array
    {
        $totals = [];

        foreach ($this->lines as $line) {
            $key = $line->currency->value;
            $totals[$key] = ($totals[$key] ?? Money::zero($line->currency))->plus($line->debit);
        }

        ksort($totals);

        return $totals;
    }

    private function assertBalancedByCurrency(): void
    {
        $debits = [];
        $credits = [];

        foreach ($this->lines as $line) {
            $key = $line->currency->value;
            $debits[$key] = ($debits[$key] ?? Money::zero($line->currency))->plus($line->debit);
            $credits[$key] = ($credits[$key] ?? Money::zero($line->currency))->plus($line->credit);
        }

        foreach ($debits as $code => $debit) {
            $credit = $credits[$code] ?? Money::zero(Currency::from($code));

            if (! $debit->equals($credit)) {
                throw new InvalidArgumentException("The entry does not balance in {$code}: debit {$debit->amount()} ≠ credit {$credit->amount()}.");
            }

            if ($debit->isZero()) {
                throw new InvalidArgumentException("The entry has no positive amount in {$code}.");
            }
        }
    }
}
