<?php

namespace App\Modules\Treasury\Infrastructure\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Domain\Audit\AuditSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ISO weeks that are split between two months. Not an error: it is the reason adding up the weekly
 * closures NEVER gives the total of a calendar month, and why the monthly closure is computed apart.
 * Its text is the old system's word for word, so an acknowledgement given there still applies.
 */
final class MonthStraddlingWeeksAudit implements AuditCheck
{
    public function id(): string
    {
        return 'month-straddling-week';
    }

    public function label(): string
    {
        return __('Weeks that cross a month');
    }

    public function description(): string
    {
        return __('ISO weeks split between two months. It is not an error: it is why adding up the weekly closures never gives the total of a calendar month, and why the monthly closure is computed apart.');
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Info;
    }

    public function findings(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT week_key, week_start::text AS week_start, week_end::text AS week_end
            FROM weekly_closures
            WHERE date_trunc('month', week_start) <> date_trunc('month', week_end)
            ORDER BY week_key
        SQL);

        $findings = [];

        foreach ($rows as $object) {
            $row = (array) $object;
            $span = Carbon::parse((string) $row['week_start'])->format('d/m').' – '.Carbon::parse((string) $row['week_end'])->format('d/m/Y');
            $findings[] = new AuditFinding((string) $row['week_key'], "Va del {$span}, así que sus ofrendas caen en dos meses distintos.");
        }

        return $findings;
    }
}
