<?php

namespace App\Modules\Treasury\Domain\Receipts;

use App\Modules\Shared\Concerns\HasOptions;

/**
 * How the money of a receipt changed hands.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case Deposit = 'deposit';
    case Transfer = 'transfer';

    /**
     * A deposit or a transfer leaves a trace at a bank; cash does not.
     */
    public function isBanked(): bool
    {
        return $this !== self::Cash;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Deposit => __('Deposit'),
            self::Transfer => __('Transfer'),
        };
    }
}
