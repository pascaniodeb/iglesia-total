<?php

namespace App\Modules\Treasury\Infrastructure\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Domain\Audit\AuditSeverity;
use App\Modules\Shared\Time\ChurchCalendar;
use App\Modules\Shared\Time\IsoWeek;
use Illuminate\Support\Facades\DB;

/**
 * Weeks that already ended, have offerings and were never closed. Weeks close in order, so one left
 * open holds back every month that touches it.
 */
final class WeeksWithoutClosureAudit implements AuditCheck
{
    public function id(): string
    {
        return 'week-without-closure';
    }

    public function label(): string
    {
        return __('Weeks without a closure');
    }

    public function description(): string
    {
        return __('Weeks that already ended and have offerings but were never closed. A week left open holds back the closure of every month it touches.');
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Warning;
    }

    public function findings(): array
    {
        $today = ChurchCalendar::today();
        $rows = DB::select(<<<'SQL'
            SELECT o.week_key, count(*) AS n
            FROM offerings o
            WHERE o.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM weekly_closures c WHERE c.week_key = o.week_key)
            GROUP BY o.week_key ORDER BY o.week_key
        SQL);

        $findings = [];

        foreach ($rows as $object) {
            $row = (array) $object;

            if (! IsoWeek::fromKey((string) $row['week_key'])->hasEnded($today)) {
                continue;
            }

            $n = (int) $row['n'];
            $findings[] = new AuditFinding((string) $row['week_key'], trans_choice('{1} :count offering without a weekly closure.|[2,*] :count offerings without a weekly closure.', $n, ['count' => $n]));
        }

        return $findings;
    }
}
