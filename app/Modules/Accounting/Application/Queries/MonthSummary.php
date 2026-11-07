<?php

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Shared\Time\Month;

/**
 * The cash statement of one month, as the IPUV report reads it (see CashStatement). Its shape is
 * part of the sealed monthly payload: changing it changes every monthly block's hash.
 */
final class MonthSummary
{
    public const array CURRENCIES = CashStatement::CURRENCIES;

    /**
     * @return array{
     *     month: string,
     *     currencies: array<string, array{opening: string, income: string, expense: string, capital: string, exchange: string, closing: string}>,
     *     by_code: list<array{code: string, name: string, type: string, entries: int, ves: string, usd: string, cop: string}>
     * }
     */
    public static function of(Month $month): array
    {
        return ['month' => $month->key(), ...CashStatement::between($month->firstDay, $month->lastDay())];
    }
}
