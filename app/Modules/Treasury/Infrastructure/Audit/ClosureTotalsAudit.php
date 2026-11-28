<?php

namespace App\Modules\Treasury\Infrastructure\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Domain\Audit\AuditSeverity;
use App\Modules\Shared\Pdf\Format;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The totals a closure stored against the live offerings of its week or month, summed again by
 * PostgreSQL on numeric. A difference means an offering was loaded, edited or removed after the
 * close: the database refuses it, so this finds what got in behind its guards.
 */
final class ClosureTotalsAudit implements AuditCheck
{
    public function __construct(private readonly string $period)
    {
        if (! in_array($period, ['weekly', 'monthly'], true)) {
            throw new InvalidArgumentException("Unknown closure period: {$period}.");
        }
    }

    public function id(): string
    {
        return "{$this->period}-closure-total";
    }

    public function label(): string
    {
        return $this->period === 'weekly' ? __('Weekly closure totals') : __('Monthly closure totals');
    }

    public function description(): string
    {
        return $this->period === 'weekly'
            ? __('The total stored in each weekly closure is recomputed from the live offerings of that week.')
            : __('The total stored in each monthly offering closure is recomputed from the live offerings of that month.');
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Critical;
    }

    public function findings(): array
    {
        [$table, $key, $link, $count] = $this->period === 'weekly'
            ? ['weekly_closures', 'week_key', 'week_key', 'offerings_count']
            : ['monthly_offering_closures', 'month', 'month_key', 'offerings_count'];

        $rows = DB::select(<<<SQL
            SELECT c.{$key} AS subject, c.{$count} AS n, c.total_ves AS ves, c.total_usd AS usd, c.total_cop AS cop,
                   coalesce(o.n, 0) AS live_n, coalesce(o.ves, 0) AS live_ves, coalesce(o.usd, 0) AS live_usd, coalesce(o.cop, 0) AS live_cop
            FROM {$table} c
            LEFT JOIN (
                SELECT {$link}, count(*) AS n, sum(amount_ves) AS ves, sum(amount_usd) AS usd, sum(amount_cop) AS cop
                FROM offerings WHERE deleted_at IS NULL GROUP BY {$link}
            ) o ON o.{$link} = c.{$key}
            WHERE c.{$count} <> coalesce(o.n, 0) OR c.total_ves <> coalesce(o.ves, 0) OR c.total_usd <> coalesce(o.usd, 0) OR c.total_cop <> coalesce(o.cop, 0)
            ORDER BY c.{$key}
        SQL);

        $findings = [];

        foreach ($rows as $object) {
            $row = (array) $object;
            $findings[] = new AuditFinding((string) $row['subject'], __('The closure holds :stored and the live offerings are :live.', [
                'stored' => $this->describe((int) $row['n'], (string) $row['ves'], (string) $row['usd'], (string) $row['cop']),
                'live' => $this->describe((int) $row['live_n'], (string) $row['live_ves'], (string) $row['live_usd'], (string) $row['live_cop']),
            ]));
        }

        return $findings;
    }

    private function describe(int $count, string $ves, string $usd, string $cop): string
    {
        return $count.' '.($count === 1 ? __('offering') : __('offerings')).' (Bs '.Format::amount($ves).', USD '.Format::amount($usd).', COP '.Format::amount($cop).')';
    }
}
