<?php

namespace App\Modules\Accounting\Domain\Enums;

/**
 * The side on which an account grows in double entry.
 */
enum NormalSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
