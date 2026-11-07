<?php

namespace App\Modules\Treasury\Infrastructure;

use App\Modules\Accounting\Application\ClosedOfferingCash;
use App\Modules\Shared\Money\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Treasury's answer to Accounting's ClosedOfferingCash port: live offerings of closed weeks,
 * totalled per day by PostgreSQL on numeric.
 */
final class TreasuryClosedOfferingCash implements ClosedOfferingCash
{
    public function dailyTotals(Currency $currency, string $until): array
    {
        $column = match ($currency) {
            Currency::VES => 'amount_ves',
            Currency::USD => 'amount_usd',
            Currency::COP => 'amount_cop',
        };

        $rows = DB::select(<<<SQL
            SELECT o.offering_date::text AS day, sum(o.{$column})::numeric(18,2)::text AS total
            FROM offerings o
            JOIN weekly_closures w ON w.week_key = o.week_key
            WHERE o.deleted_at IS NULL AND o.offering_date <= ? AND o.{$column} > 0
            GROUP BY o.offering_date
            ORDER BY o.offering_date
        SQL, [$until]);

        $totals = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $totals[(string) $row['day']] = (string) $row['total'];
        }

        return $totals;
    }
}
