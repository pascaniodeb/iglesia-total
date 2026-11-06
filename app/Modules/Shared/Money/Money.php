<?php

namespace App\Modules\Shared\Money;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * An exact amount in one currency, with two decimals. Never a float: it operates on exact
 * decimals and only within the same currency (adding pesos to dollars is a programming error).
 *
 * Ported from safipuv-v4 (App\Modules\Shared\Money\Money).
 */
final readonly class Money
{
    private const int SCALE = 2;

    private function __construct(private BigDecimal $amount, private Currency $currency) {}

    public static function of(string $amount, Currency $currency): self
    {
        return new self(Decimal::parse($amount, self::SCALE, 'amount'), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE), $currency);
    }

    public static function sum(Currency $currency, self ...$amounts): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /** Two decimals with a dot: "1234.50". */
    public function amount(): string
    {
        return (string) $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot operate {$this->currency->value} with {$other->currency->value}.");
        }
    }
}
