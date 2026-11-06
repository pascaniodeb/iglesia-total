<?php

namespace App\Modules\Treasury\Domain\Rates;

use App\Modules\Shared\Money\Decimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * An exact, positive exchange rate with its decimals (bolívares per dollar: 4; pesos per
 * bolívar: 6).
 */
final readonly class Rate
{
    private function __construct(public BigDecimal $value) {}

    /**
     * @param  int<0, max>  $scale
     */
    public static function of(string $value, int $scale): self
    {
        $decimal = Decimal::parse($value, $scale, 'rate');

        if (! $decimal->isPositive()) {
            throw new InvalidArgumentException("The rate must be greater than zero: {$value}.");
        }

        return new self($decimal);
    }

    public static function fromDecimal(BigDecimal $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return (string) $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->isEqualTo($other->value) && $this->value->getScale() === $other->value->getScale();
    }
}
