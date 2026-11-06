<?php

namespace App\Modules\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use InvalidArgumentException;

/**
 * Strict reading of a decimal written as text ("1234.5", "-7.65"): no scientific notation, no
 * thousands separators and no more decimals than allowed. Nothing is rounded silently.
 */
final class Decimal
{
    private const string PATTERN = '/^-?\d+(\.\d+)?$/';

    /**
     * @param  int<0, max>  $scale  decimals allowed
     */
    public static function parse(string $value, int $scale, string $what): BigDecimal
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("The {$what} is not a valid number: \"{$value}\".");
        }

        try {
            return BigDecimal::of($value)->toScale($scale);
        } catch (RoundingNecessaryException) {
            throw new InvalidArgumentException("The {$what} allows at most {$scale} decimals: \"{$value}\".");
        }
    }
}
