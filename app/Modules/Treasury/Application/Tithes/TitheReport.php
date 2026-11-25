<?php

namespace App\Modules\Treasury\Application\Tithes;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\Month;
use App\Modules\Shared\Time\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The tithes report of a period (month, quarter, semester, year or range): the total per
 * currency, how many members tithed and, per member in alphabetical order (not a ranking), what
 * they tithed and in how many weeks. Sums are done by PostgreSQL on numeric; trashed tithes do
 * not count. Members in the trash who tithed in the period still appear: what was tithed is
 * history.
 */
final class TitheReport
{
    /**
     * @return array{
     *     granularity: string,
     *     period: string,
     *     label: string,
     *     short_label: string,
     *     months: list<string>,
     *     totals: array{ves: string, usd: string, cop: string},
     *     members_who_tithed: int,
     *     members: list<array{id: string, name: string, ves: string, usd: string, cop: string, weeks: int}>
     * }
     */
    public static function of(ReportPeriod $period, MemberDirectory $directory): array
    {
        $months = array_map(fn (Month $month) => $month->key(), $period->months());

        $rows = DB::select(<<<'SQL'
            SELECT member_id::text AS member_id,
                   sum(amount_ves)::numeric(18,2)::text AS ves,
                   sum(amount_usd)::numeric(18,2)::text AS usd,
                   sum(amount_cop)::numeric(18,2)::text AS cop,
                   count(DISTINCT week_key)::int AS weeks
            FROM tithes
            WHERE deleted_at IS NULL AND month_key = ANY (string_to_array(?, ','))
            GROUP BY member_id
        SQL, [implode(',', $months)]);

        $totals = (array) DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves,
                   coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd,
                   coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop
            FROM tithes
            WHERE deleted_at IS NULL AND month_key = ANY (string_to_array(?, ','))
        SQL, [implode(',', $months)]);

        $rows = array_map(fn (object $row): array => (array) $row, $rows);
        $people = $directory->findMany(array_values(array_map(fn (array $row): string => (string) $row['member_id'], $rows)));

        $members = array_map(fn (array $row): array => [
            'id' => (string) $row['member_id'],
            'name' => $people[(string) $row['member_id']]->name ?? '',
            'ves' => (string) $row['ves'],
            'usd' => (string) $row['usd'],
            'cop' => (string) $row['cop'],
            'weeks' => (int) $row['weeks'],
        ], $rows);

        usort($members, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return [
            'granularity' => $period->granularity,
            'period' => $period->key(),
            'label' => $period->label(),
            'short_label' => $period->shortLabel(),
            'months' => $months,
            'totals' => ['ves' => (string) $totals['ves'], 'usd' => (string) $totals['usd'], 'cop' => (string) $totals['cop']],
            'members_who_tithed' => count($members),
            'members' => $members,
        ];
    }
}
