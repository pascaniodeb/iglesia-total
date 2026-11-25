<?php

namespace App\Modules\Treasury\Domain\Receipts;

use App\Modules\Accounting\Domain\Enums\MovementKind;
use App\Modules\Shared\Concerns\HasOptions;

/**
 * Whether the receipt acknowledges money the church received or money it paid out.
 */
enum ReceiptType: string
{
    use HasOptions;

    case Income = 'income';
    case Expense = 'expense';

    public function movementKind(): MovementKind
    {
        return match ($this) {
            self::Income => MovementKind::Income,
            self::Expense => MovementKind::Expense,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }
}
