<?php

namespace App\Modules\Accounting\Domain\Enums;

/**
 * What a treasurer records by hand in the journal.
 */
enum MovementKind: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Exchange = 'exchange';

    /** The journal origin type of entries recorded by hand. */
    public function originType(): string
    {
        return $this === self::Exchange ? 'currency_exchange' : 'manual_movement';
    }
}
