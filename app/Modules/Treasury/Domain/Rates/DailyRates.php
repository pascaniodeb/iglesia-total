<?php

namespace App\Modules\Treasury\Domain\Rates;

use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

/**
 * The rates of a day: bolívares per dollar and per euro (BCV), pesos per dollar (Colombia's
 * TRM) and pesos per bolívar, the one treasury uses (TRM ÷ BCV, six decimals, as the old
 * systems). Ported from safipuv-v4.
 *
 * The bolívar equivalent of an amount only serves to sort and compare: money is posted and
 * totalled always in its own currency.
 */
final readonly class DailyRates
{
    public const int USD_SCALE = 4;

    public const int COP_SCALE = 6;

    public function __construct(
        public DateTimeImmutable $date,
        public Rate $usdRate,
        public Rate $copRate,
        public ?Rate $eurRate = null,
        public ?Rate $trmCopUsd = null,
    ) {}

    /**
     * From the official sources: BCV (dollar and euro in bolívares) and TRM (dollar in pesos).
     */
    public static function fromMarket(DateTimeImmutable $date, Rate $usdRate, Rate $eurRate, Rate $trmCopUsd): self
    {
        $copRate = $trmCopUsd->value->dividedBy($usdRate->value, self::COP_SCALE, RoundingMode::HalfUp);

        return new self($date, $usdRate, Rate::fromDecimal($copRate), $eurRate, $trmCopUsd);
    }

    /**
     * Equivalent in bolívares (two decimals): the dollar is multiplied, the peso divided.
     */
    public function toBolivares(Money $amount): Money
    {
        $value = BigDecimal::of($amount->amount());

        $bolivares = match ($amount->currency()) {
            Currency::VES => $value,
            Currency::USD => $value->multipliedBy($this->usdRate->value)->toScale(2, RoundingMode::HalfUp),
            Currency::COP => $value->dividedBy($this->copRate->value, 2, RoundingMode::HalfUp),
        };

        return Money::of((string) $bolivares, Currency::VES);
    }
}
