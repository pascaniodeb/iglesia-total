<?php

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The accounting report of a period (month, quarter, semester, year or range): the cash statement
 * of the whole span (CashStatement: one opening, the income and expense of every month, one
 * closing) and the net of each income and expense code.
 *
 * It is a draft while any month of the span is still open: its figures can still change, so the
 * screen and the printed report say so ("BORRADOR").
 */
final class PeriodReport
{
    /**
     * @return array{
     *     granularity: string,
     *     period: string,
     *     label: string,
     *     short_label: string,
     *     first_day: string,
     *     last_day: string,
     *     months: list<array{month: string, is_closed: bool}>,
     *     is_draft: bool,
     *     currencies: array<string, array{opening: string, income: string, expense: string, capital: string, exchange: string, closing: string}>,
     *     by_code: list<array{code: string, name: string, type: string, entries: int, ves: string, usd: string, cop: string}>
     * }
     */
    public static function of(ReportPeriod $period): array
    {
        $keys = array_map(fn (Month $month) => $month->key(), $period->months());
        $closed = array_map(strval(...), DB::table('accounting_periods')->whereIn('month', $keys)->pluck('month')->all());
        $months = array_map(fn (string $key) => ['month' => $key, 'is_closed' => in_array($key, $closed, true)], $keys);

        return [
            'granularity' => $period->granularity,
            'period' => $period->key(),
            'label' => $period->label(),
            'short_label' => $period->shortLabel(),
            'first_day' => $period->firstDay()->format('Y-m-d'),
            'last_day' => $period->lastDay()->format('Y-m-d'),
            'months' => $months,
            'is_draft' => count($closed) < count($keys),
            ...CashStatement::between($period->firstDay(), $period->lastDay()),
        ];
    }
}
