<?php

namespace App\Modules\Shared\Money;

/**
 * The currencies money is collected and spent in. Each one is totalled on its own and they are
 * never mixed in the books (an exchange rate only informs and compares).
 */
enum Currency: string
{
    case VES = 'VES';
    case USD = 'USD';
    case COP = 'COP';

    public function label(): string
    {
        return match ($this) {
            self::VES => __('Bolívares'),
            self::USD => __('Dollars'),
            self::COP => __('Pesos'),
        };
    }
}
