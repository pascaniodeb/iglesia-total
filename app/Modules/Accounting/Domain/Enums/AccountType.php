<?php

namespace App\Modules\Accounting\Domain\Enums;

use App\Modules\Shared\Concerns\HasOptions;
use App\Modules\Shared\Money\Money;

/**
 * Accounting nature of an account: it decides its normal side and how its balance is derived.
 */
enum AccountType: string
{
    use HasOptions;

    case Asset = 'asset';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function normalSide(): NormalSide
    {
        return match ($this) {
            self::Asset, self::Expense => NormalSide::Debit,
            self::Equity, self::Income => NormalSide::Credit,
        };
    }

    /**
     * Balance on the normal side: debit − credit for assets and expenses, credit − debit for
     * equity and income.
     */
    public function balance(Money $totalDebit, Money $totalCredit): Money
    {
        return match ($this->normalSide()) {
            NormalSide::Debit => $totalDebit->minus($totalCredit),
            NormalSide::Credit => $totalCredit->minus($totalDebit),
        };
    }

    /**
     * The type a code prefix stands for in the IPUV chart (only income and expense codes are
     * created by people; the other types belong to system accounts).
     */
    public static function forCode(string $code): ?self
    {
        return match (substr($code, 0, 2)) {
            'I-' => self::Income,
            'E-' => self::Expense,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }
}
