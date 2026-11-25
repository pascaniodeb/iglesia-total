<?php

namespace App\Modules\Treasury\Application\Queries;

use App\Modules\Shared\Time\IsoWeek;
use App\Modules\Shared\Time\Month;
use App\Modules\Treasury\Application\Closures\MonthlyOfferingClosureSchedule;
use App\Modules\Treasury\Application\Closures\WeeklyClosureSchedule;
use App\Modules\Treasury\Infrastructure\Models\ExchangeRate;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the dashboard shows of the treasury: the offerings of the month against the one before, the
 * weekly trend, the month by category, the tithes of the month, what is waiting to be closed and
 * the exchange rates in force. Sums are done by PostgreSQL on numeric and travel as decimal text,
 * never as floats. Trashed rows do not count. Read-only.
 */
final class TreasuryDashboard
{
    /** Weeks drawn in the trend, the current one included. */
    public const int TREND_WEEKS = 12;

    /** Weeks listed as pending to close before saying "and more". */
    private const int PENDING_WEEKS_LISTED = 8;

    private const string TOTALS = 'count(*)::int AS count, coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves, coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd, coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop';

    /**
     * @return array{
     *     month: string,
     *     previous_month: string,
     *     current: array{count: int, ves: string, usd: string, cop: string},
     *     previous: array{count: int, ves: string, usd: string, cop: string},
     *     weeks: list<array{key: string, start: string, end: string, closed: bool, count: int, ves: string, usd: string, cop: string}>,
     *     categories: list<array{id: string, name: string, count: int, ves: string, usd: string, cop: string}>
     * }
     */
    public static function offerings(DateTimeImmutable $today): array
    {
        $month = Month::containing($today);
        $previous = $month->previous();

        return [
            'month' => $month->key(),
            'previous_month' => $previous->key(),
            'current' => self::monthTotals('offerings', $month),
            'previous' => self::monthTotals('offerings', $previous),
            'weeks' => self::weeks($today),
            'categories' => self::categories($month),
        ];
    }

    /**
     * The tithes of the month: how much and how many members tithed.
     *
     * @return array{month: string, members: int, count: int, ves: string, usd: string, cop: string}
     */
    public static function tithes(DateTimeImmutable $today): array
    {
        $month = Month::containing($today);
        $row = DB::selectOne(
            'SELECT count(DISTINCT member_id)::int AS members, '.self::TOTALS.' FROM tithes WHERE deleted_at IS NULL AND month_key = ?',
            [$month->key()],
        );

        return [
            'month' => $month->key(),
            'members' => (int) ($row->members ?? 0),
            'count' => (int) ($row->count ?? 0),
            'ves' => (string) ($row->ves ?? '0.00'),
            'usd' => (string) ($row->usd ?? '0.00'),
            'cop' => (string) ($row->cop ?? '0.00'),
        ];
    }

    /**
     * What is waiting in the offering circuit: the weeks that have ended and are not closed, and
     * the month whose offerings can be closed.
     *
     * @return array{
     *     weeks: list<array{key: string, end: string}>,
     *     weeks_pending: int,
     *     week_can_close: bool,
     *     month: array{key: string, blocker: string|null}|null
     * }
     */
    public static function pending(DateTimeImmutable $today): array
    {
        $weeks = [];
        $count = 0;
        $next = WeeklyClosureSchedule::next();

        for ($week = $next; $week !== null && $week->hasEnded($today); $week = $week->next()) {
            $count++;

            if (count($weeks) < self::PENDING_WEEKS_LISTED) {
                $weeks[] = ['key' => $week->key(), 'end' => $week->end()->format('Y-m-d')];
            }
        }

        $month = MonthlyOfferingClosureSchedule::next();

        return [
            'weeks' => $weeks,
            'weeks_pending' => $count,
            'week_can_close' => $next !== null && WeeklyClosureSchedule::blocker($next, $today) === null,
            'month' => $month === null || $month->lastDay() >= $today ? null : ['key' => $month->key(), 'blocker' => MonthlyOfferingClosureSchedule::blocker($month)],
        ];
    }

    /**
     * The exchange rates in force today (or the latest before), or null when none were ever recorded.
     *
     * @return array{date: string, usd: string, cop: string, eur: string|null}|null
     */
    public static function rates(DateTimeImmutable $today): ?array
    {
        $rate = ExchangeRate::latestOn($today);

        return $rate === null ? null : [
            'date' => $rate->rate_date->toDateString(),
            'usd' => (string) $rate->usd_rate,
            'cop' => (string) $rate->cop_rate,
            'eur' => $rate->eur_rate === null ? null : (string) $rate->eur_rate,
        ];
    }

    /**
     * @return array{count: int, ves: string, usd: string, cop: string}
     */
    private static function monthTotals(string $table, Month $month): array
    {
        $row = DB::selectOne('SELECT '.self::TOTALS." FROM {$table} WHERE deleted_at IS NULL AND month_key = ?", [$month->key()]);

        return ['count' => (int) ($row->count ?? 0), 'ves' => (string) ($row->ves ?? '0.00'), 'usd' => (string) ($row->usd ?? '0.00'), 'cop' => (string) ($row->cop ?? '0.00')];
    }

    /**
     * @return list<array{key: string, start: string, end: string, closed: bool, count: int, ves: string, usd: string, cop: string}>
     */
    private static function weeks(DateTimeImmutable $today): array
    {
        $weeks = [];

        for ($week = IsoWeek::containing($today), $i = 0; $i < self::TREND_WEEKS; $week = $week->previous(), $i++) {
            array_unshift($weeks, $week);
        }

        $keys = array_map(fn (IsoWeek $week): string => $week->key(), $weeks);
        $rows = collect(DB::select(
            'SELECT week_key, '.self::TOTALS.' FROM offerings WHERE deleted_at IS NULL AND week_key = ANY(?::text[]) GROUP BY week_key',
            ['{'.implode(',', $keys).'}'],
        ))->keyBy('week_key');
        $closed = DB::table('weekly_closures')->whereIn('week_key', $keys)->pluck('week_key')->all();

        return array_map(fn (IsoWeek $week): array => [
            'key' => $week->key(),
            'start' => $week->start->format('Y-m-d'),
            'end' => $week->end()->format('Y-m-d'),
            'closed' => in_array($week->key(), $closed, true),
            'count' => (int) ($rows[$week->key()]->count ?? 0),
            'ves' => (string) ($rows[$week->key()]->ves ?? '0.00'),
            'usd' => (string) ($rows[$week->key()]->usd ?? '0.00'),
            'cop' => (string) ($rows[$week->key()]->cop ?? '0.00'),
        ], $weeks);
    }

    /**
     * @return list<array{id: string, name: string, count: int, ves: string, usd: string, cop: string}>
     */
    private static function categories(Month $month): array
    {
        $categories = [];

        foreach (DB::select(
            'SELECT c.id, c.name, count(*)::int AS count, sum(o.amount_ves)::numeric(18,2)::text AS ves, sum(o.amount_usd)::numeric(18,2)::text AS usd, sum(o.amount_cop)::numeric(18,2)::text AS cop
             FROM offerings o JOIN offering_categories c ON c.id = o.offering_category_id
             WHERE o.deleted_at IS NULL AND o.month_key = ? GROUP BY c.id, c.name ORDER BY sum(o.amount_cop) DESC, c.name',
            [$month->key()],
        ) as $object) {
            $row = (array) $object;
            $categories[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'count' => (int) $row['count'],
                'ves' => (string) $row['ves'],
                'usd' => (string) $row['usd'],
                'cop' => (string) $row['cop'],
            ];
        }

        return $categories;
    }
}
