<?php

namespace App\Modules\Treasury\Application\Closures;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Shared\Time\IsoWeek;
use Illuminate\Support\Facades\DB;

/**
 * What a week brought, by offering category, with the accounting code each category points to: the
 * lines of the weekly income form. PostgreSQL adds on numeric; a category with no offerings that
 * week has no line. Read-only.
 */
final class WeekByCategory
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /**
     * @return list<array{code: string, concept: string, ves: string, usd: string, cop: string}>
     */
    public function of(IsoWeek $week): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.name AS concept, c.account_id::text AS account_id,
                   sum(o.amount_ves)::numeric(18,2)::text AS ves,
                   sum(o.amount_usd)::numeric(18,2)::text AS usd,
                   sum(o.amount_cop)::numeric(18,2)::text AS cop
            FROM offerings o
            JOIN offering_categories c ON c.id = o.offering_category_id
            WHERE o.week_key = ? AND o.deleted_at IS NULL
            GROUP BY c.id, c.name, c.account_id
            ORDER BY c.name, c.id
        SQL, [$week->key()]);

        $lines = [];

        foreach ($rows as $object) {
            $row = (array) $object;
            $lines[] = [
                'code' => $this->chart->find((string) $row['account_id'])->code,
                'concept' => (string) $row['concept'],
                'ves' => (string) $row['ves'],
                'usd' => (string) $row['usd'],
                'cop' => (string) $row['cop'],
            ];
        }

        return $lines;
    }
}
