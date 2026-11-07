<?php

namespace App\Modules\Treasury\Application\Closures;

use App\Modules\Treasury\Domain\Month;
use Illuminate\Support\Facades\DB;

/**
 * A month's live offerings by category (with the income account each category points to now),
 * counted and totalled per currency by PostgreSQL on numeric. The same numbers the database
 * checks when the month is closed.
 */
final class MonthOfferingSnapshot
{
    /**
     * @return array{
     *     count: int, ves: string, usd: string, cop: string,
     *     categories: list<array{category_id: string, category: string, account_id: string, count: int, ves: string, usd: string, cop: string}>
     * }
     */
    public static function of(Month $month): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.id::text AS category_id, c.name AS category, c.account_id::text AS account_id,
                   count(*) AS n,
                   sum(o.amount_ves)::numeric(18,2)::text AS ves,
                   sum(o.amount_usd)::numeric(18,2)::text AS usd,
                   sum(o.amount_cop)::numeric(18,2)::text AS cop
            FROM offerings o
            JOIN offering_categories c ON c.id = o.offering_category_id
            WHERE o.month_key = ? AND o.deleted_at IS NULL
            GROUP BY c.id, c.name, c.account_id
            ORDER BY c.name, c.id
        SQL, [$month->key()]);

        $total = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n,
                   coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves,
                   coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd,
                   coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop
            FROM offerings
            WHERE month_key = ? AND deleted_at IS NULL
        SQL, [$month->key()]);

        $categories = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $categories[] = [
                'category_id' => (string) $row['category_id'],
                'category' => (string) $row['category'],
                'account_id' => (string) $row['account_id'],
                'count' => (int) $row['n'],
                'ves' => (string) $row['ves'],
                'usd' => (string) $row['usd'],
                'cop' => (string) $row['cop'],
            ];
        }

        return ['count' => (int) $total->n, 'ves' => $total->ves, 'usd' => $total->usd, 'cop' => $total->cop, 'categories' => $categories];
    }
}
