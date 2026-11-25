<?php

namespace App\Modules\Treasury\Application\Tithes;

use App\Modules\Members\Application\MemberDirectory;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\ReportPeriod;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How constantly each member of the registry tithed over a period, and since when they have not.
 *
 * It lists every member of the registry, also those with no tithe in the period: they are exactly
 * who the analysis exists to show. Two averages, never confused:
 *
 * - given: the total over the weeks in which they tithed in any currency (what they give when
 *   they give; the same weeks for every currency, as in the old system);
 * - period: the total over the Sundays of the period (what they give overall).
 *
 * The denominator of the second is the Sundays, not the ISO weeks the period touches: a metric
 * whose only purpose is to judge someone's constancy cannot accuse them of missing a week they did
 * not miss (see ReportPeriod::sundays). Sums and averages are done by PostgreSQL on numeric and
 * rounded to cents; the last tithe is the last ever, not only inside the period, so a member who
 * stopped can be told apart from one who never tithed.
 */
final class TitheAnalysis
{
    private const array CURRENCIES = ['ves', 'usd', 'cop'];

    /**
     * @return array{
     *     granularity: string,
     *     period: string,
     *     label: string,
     *     short_label: string,
     *     sundays: int,
     *     rows: list<array{id: string, name: string, totals: array<string, string>, weeks: int, average_given: array<string, string|null>, average_period: array<string, string>, last_date: string|null, weeks_ago: int|null}>
     * }
     */
    public static function of(ReportPeriod $period, MemberDirectory $directory): array
    {
        $sundays = $period->sundays();
        $in = 'tithe_date BETWEEN :first AND :last';

        $select = [];

        foreach (self::CURRENCIES as $currency) {
            $select[] = "coalesce(sum(amount_{$currency}) FILTER (WHERE {$in}), 0)::numeric(18,2)::text AS total_{$currency}";
            $select[] = "round(sum(amount_{$currency}) FILTER (WHERE {$in}) / nullif(count(DISTINCT week_key) FILTER (WHERE {$in}), 0), 2)::text AS given_{$currency}";
            $select[] = "round(coalesce(sum(amount_{$currency}) FILTER (WHERE {$in}), 0) / greatest(CAST(:sundays AS integer), 1), 2)::text AS period_{$currency}";
        }

        $rows = DB::select(
            'SELECT member_id::text AS member_id, count(DISTINCT week_key) FILTER (WHERE '.$in.') AS weeks, max(tithe_date)::text AS last_date, '
            .implode(', ', $select)
            .' FROM tithes WHERE deleted_at IS NULL GROUP BY member_id',
            ['first' => $period->firstDay()->format('Y-m-d'), 'last' => $period->lastDay()->format('Y-m-d'), 'sundays' => $sundays],
        );

        $byMember = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $byMember[(string) $row['member_id']] = $row;
        }

        $today = ChurchCalendar::today();
        $analysed = [];

        foreach ($directory->registry() as $member) {
            $row = $byMember[$member->id] ?? null;
            $lastDate = $row === null ? null : (string) $row['last_date'];
            $totals = [];
            $given = [];
            $overall = [];

            foreach (self::CURRENCIES as $currency) {
                $totals[$currency] = $row === null ? '0.00' : (string) $row["total_{$currency}"];
                $given[$currency] = $row === null || $row["given_{$currency}"] === null ? null : (string) $row["given_{$currency}"];
                $overall[$currency] = $row === null ? '0.00' : (string) $row["period_{$currency}"];
            }

            $analysed[] = [
                'id' => $member->id,
                'name' => $member->name,
                'totals' => $totals,
                'weeks' => $row === null ? 0 : (int) $row['weeks'],
                'average_given' => $given,
                'average_period' => $overall,
                'last_date' => $lastDate,
                'weeks_ago' => $lastDate === null ? null : self::weeksBetween(new DateTimeImmutable($lastDate), $today),
            ];
        }

        return [
            'granularity' => $period->granularity,
            'period' => $period->key(),
            'label' => $period->label(),
            'short_label' => $period->shortLabel(),
            'sundays' => $sundays,
            'rows' => $analysed,
        ];
    }

    /**
     * Whole weeks from a day to another (the days between, over seven, rounded down).
     */
    private static function weeksBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, intdiv((int) $from->diff($to)->days, 7));
    }
}
