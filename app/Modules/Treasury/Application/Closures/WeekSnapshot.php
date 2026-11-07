<?php

namespace App\Modules\Treasury\Application\Closures;

use App\Modules\Shared\Time\IsoWeek;
use Illuminate\Support\Facades\DB;

/**
 * What a week holds right now: its live offerings counted and totalled per currency, computed
 * by PostgreSQL on numeric (never in floating point). The same numbers the database checks when
 * the week is closed.
 */
final class WeekSnapshot
{
    /**
     * @return array{count: int, ves: string, usd: string, cop: string}
     */
    public static function of(IsoWeek $week): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n,
                   coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves,
                   coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd,
                   coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop
            FROM offerings
            WHERE week_key = ? AND deleted_at IS NULL
        SQL, [$week->key()]);

        return ['count' => (int) $row->n, 'ves' => $row->ves, 'usd' => $row->usd, 'cop' => $row->cop];
    }
}
