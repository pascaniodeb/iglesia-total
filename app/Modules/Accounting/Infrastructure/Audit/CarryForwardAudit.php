<?php

namespace App\Modules\Accounting\Infrastructure\Audit;

use App\Modules\Integrity\Domain\Audit\AuditCheck;
use App\Modules\Integrity\Domain\Audit\AuditFinding;
use App\Modules\Integrity\Domain\Audit\AuditSeverity;
use App\Modules\Shared\Pdf\Format;
use Illuminate\Support\Facades\DB;

/**
 * The cash a month closed with, as the closing snapshot stored it, against the cash the journal
 * lines add up to at the end of that month. Each month opens with the previous closing, so a closing
 * that does not match its lines breaks every month after it.
 */
final class CarryForwardAudit implements AuditCheck
{
    public function id(): string
    {
        return 'carry-forward-chain';
    }

    public function label(): string
    {
        return __('Cash carried from month to month');
    }

    public function description(): string
    {
        return __('The cash stored when each month was closed is compared with the sum of the cash lines of the journal up to the end of that month, per currency.');
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Critical;
    }

    public function findings(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT p.month, b.currency, b.balance::text AS stored, x.live::text AS live
            FROM accounting_period_balances b
            JOIN accounting_periods p ON p.id = b.accounting_period_id
            JOIN accounting_accounts a ON a.id = b.account_id AND a.system_function = 'cash'
            CROSS JOIN LATERAL (
                SELECT coalesce(sum(l.debit - l.credit), 0) AS live
                FROM journal_entry_lines l
                JOIN journal_entries e ON e.id = l.journal_entry_id
                JOIN accounting_accounts ca ON ca.id = l.account_id AND ca.system_function = 'cash'
                WHERE l.currency = b.currency
                  AND e.entry_date <= ((p.month || '-01')::date + interval '1 month' - interval '1 day')::date
            ) x
            WHERE b.balance <> x.live
            ORDER BY p.month, b.currency
        SQL);

        $findings = [];

        foreach ($rows as $object) {
            $row = (array) $object;
            $findings[] = new AuditFinding((string) $row['month'], __('The cash stored at the close is :stored :currency, but the journal lines up to that date add up to :live :currency.', [
                'stored' => Format::amount((string) $row['stored']),
                'live' => Format::amount((string) $row['live']),
                'currency' => trim((string) $row['currency']),
            ]));
        }

        return $findings;
    }
}
