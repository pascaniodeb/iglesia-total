<?php

namespace App\Modules\Treasury\Domain;

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/**
 * What one offering brought, in each currency. Currencies are never mixed or converted; none is
 * negative and at least one is positive.
 */
final readonly class OfferingAmounts
{
    public function __construct(public Money $ves, public Money $usd, public Money $cop)
    {
        if ($ves->currency() !== Currency::VES || $usd->currency() !== Currency::USD || $cop->currency() !== Currency::COP) {
            throw new InvalidArgumentException('Each offering amount must be in its own currency.');
        }

        if ($ves->isNegative() || $usd->isNegative() || $cop->isNegative()) {
            throw new InvalidArgumentException('An offering amount cannot be negative.');
        }

        if ($ves->isZero() && $usd->isZero() && $cop->isZero()) {
            throw new InvalidArgumentException('An offering needs at least one amount greater than zero.');
        }
    }

    /**
     * From the decimal text of a form or a file: empty means zero; a decimal comma is accepted;
     * thousands separators are not (they are ambiguous between "1.000" and "1,000").
     */
    public static function fromInput(?string $ves, ?string $usd, ?string $cop): self
    {
        return new self(self::money($ves, Currency::VES), self::money($usd, Currency::USD), self::money($cop, Currency::COP));
    }

    /**
     * @return array{amount_ves: string, amount_usd: string, amount_cop: string}
     */
    public function toColumns(): array
    {
        return [
            'amount_ves' => $this->ves->amount(),
            'amount_usd' => $this->usd->amount(),
            'amount_cop' => $this->cop->amount(),
        ];
    }

    private static function money(?string $value, Currency $currency): Money
    {
        $value = trim((string) $value);

        if ($value === '') {
            return Money::zero($currency);
        }

        if (preg_match('/^\d{1,16}([.,]\d{1,2})?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a valid {$currency->value} amount: \"{$value}\".");
        }

        return Money::of(str_replace(',', '.', $value), $currency);
    }
}
