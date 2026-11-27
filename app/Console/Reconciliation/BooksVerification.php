<?php

namespace App\Console\Reconciliation;

use Illuminate\Support\Facades\DB;

/**
 * The permanent cross-checks between the treasury and the accounting books (section 4.4 of the
 * plan), recomputed from the stored rows with set-difference queries, independently of every
 * trigger and every screen. Each check returns the rows that break it; an empty list passes.
 *
 * It lives with the console tools, not in a module: it reads Treasury and Accounting tables
 * side by side, which neither module may do for the other.
 */
final class BooksVerification
{
    /** Findings listed per check at most; the count says how many there are. */
    private const int LIMIT = 25;

    /** The keys of the checks, in the order they are listed. */
    public const array KEYS = ['weeks', 'months', 'posting', 'snapshots', 'receipts'];

    /**
     * @return array<string, array{title: string, findings: list<string>}>
     */
    public function __invoke(): array
    {
        $results = [];

        foreach (self::KEYS as $key) {
            $results[$key] = ['title' => self::title($key), 'findings' => $this->findingsOf($key)];
        }

        return $results;
    }

    public static function title(string $key): string
    {
        return match ($key) {
            'weeks' => __('Each closed week holds exactly its offerings (count and totals)'),
            'months' => __('Each monthly offering closure holds exactly the offerings of its month, by category'),
            'posting' => __('Each monthly closure posted exactly its income to the journal'),
            'snapshots' => __("Each closed month's balance snapshot equals the sum of the journal lines up to its end"),
            'receipts' => __('Each receipt moved the cash by exactly its amounts'),
            default => throw new \InvalidArgumentException("Unknown books check: {$key}."),
        };
    }

    /**
     * What breaks one check; empty when it holds.
     *
     * @return list<string>
     */
    public function findingsOf(string $key): array
    {
        return match ($key) {
            'weeks' => $this->weeks(),
            'months' => $this->months(),
            'posting' => $this->posting(),
            'snapshots' => $this->snapshots(),
            'receipts' => $this->receipts(),
            default => throw new \InvalidArgumentException("Unknown books check: {$key}."),
        };
    }

    /**
     * @return list<string>
     */
    private function weeks(): array
    {
        return $this->findings(<<<'SQL'
            SELECT c.week_key || ': closure ' || c.offerings_count || ' offerings VES ' || c.total_ves || ' USD ' || c.total_usd || ' COP ' || c.total_cop
                   || ' · offerings ' || coalesce(o.n, 0) || ' VES ' || coalesce(o.ves, 0) || ' USD ' || coalesce(o.usd, 0) || ' COP ' || coalesce(o.cop, 0) AS finding
            FROM weekly_closures c
            LEFT JOIN (
                SELECT week_key, count(*) AS n, sum(amount_ves) AS ves, sum(amount_usd) AS usd, sum(amount_cop) AS cop
                FROM offerings WHERE deleted_at IS NULL GROUP BY week_key
            ) o ON o.week_key = c.week_key
            WHERE c.offerings_count <> coalesce(o.n, 0) OR c.total_ves <> coalesce(o.ves, 0) OR c.total_usd <> coalesce(o.usd, 0) OR c.total_cop <> coalesce(o.cop, 0)
            ORDER BY c.week_key
        SQL);
    }

    /**
     * @return list<string>
     */
    private function months(): array
    {
        return $this->findings(<<<'SQL'
            SELECT m.month || ' ' || coalesce(l.offering_category_id::text, o.offering_category_id::text) || ': closure ' || coalesce(l.n, 0) || ' VES ' || coalesce(l.ves, 0)
                   || ' USD ' || coalesce(l.usd, 0) || ' COP ' || coalesce(l.cop, 0) || ' · offerings ' || coalesce(o.n, 0) || ' VES ' || coalesce(o.ves, 0)
                   || ' USD ' || coalesce(o.usd, 0) || ' COP ' || coalesce(o.cop, 0) AS finding
            FROM monthly_offering_closures m
            JOIN (
                SELECT x.monthly_offering_closure_id, x.offering_category_id, x.offerings_count AS n, x.total_ves AS ves, x.total_usd AS usd, x.total_cop AS cop
                FROM monthly_offering_closure_lines x
            ) l ON l.monthly_offering_closure_id = m.id
            FULL OUTER JOIN (
                SELECT month_key, offering_category_id, count(*) AS n, sum(amount_ves) AS ves, sum(amount_usd) AS usd, sum(amount_cop) AS cop
                FROM offerings WHERE deleted_at IS NULL GROUP BY month_key, offering_category_id
            ) o ON o.month_key = m.month AND o.offering_category_id = l.offering_category_id
            WHERE (m.id IS NOT NULL OR o.month_key IN (SELECT month FROM monthly_offering_closures))
              AND (l.n IS DISTINCT FROM o.n OR l.ves IS DISTINCT FROM o.ves OR l.usd IS DISTINCT FROM o.usd OR l.cop IS DISTINCT FROM o.cop)
            ORDER BY 1
        SQL);
    }

    /**
     * @return list<string>
     */
    private function posting(): array
    {
        return $this->findings(<<<'SQL'
            SELECT m.month || ' ' || a.code || ' ' || c.currency || ': closure ' || c.total || ' · journal credit ' || coalesce(j.credit, 0) AS finding
            FROM monthly_offering_closures m
            JOIN LATERAL (
                SELECT l.account_id, 'VES' AS currency, l.total_ves AS total FROM monthly_offering_closure_lines l WHERE l.monthly_offering_closure_id = m.id
                UNION ALL SELECT l.account_id, 'USD', l.total_usd FROM monthly_offering_closure_lines l WHERE l.monthly_offering_closure_id = m.id
                UNION ALL SELECT l.account_id, 'COP', l.total_cop FROM monthly_offering_closure_lines l WHERE l.monthly_offering_closure_id = m.id
            ) c ON TRUE
            JOIN accounting_accounts a ON a.id = c.account_id
            LEFT JOIN LATERAL (
                SELECT sum(jl.credit - jl.debit) AS credit FROM journal_entry_lines jl
                WHERE jl.journal_entry_id = m.journal_entry_id AND jl.account_id = c.account_id AND jl.currency = c.currency
            ) j ON TRUE
            WHERE c.total <> coalesce(j.credit, 0) AND (c.total <> 0 OR coalesce(j.credit, 0) <> 0)
            ORDER BY m.month, a.code
        SQL);
    }

    /**
     * @return list<string>
     */
    private function snapshots(): array
    {
        return $this->findings(<<<'SQL'
            WITH snap AS (
                SELECT p.month, b.account_id, b.currency, b.total_debit, b.total_credit, b.balance, a.type
                FROM accounting_period_balances b
                JOIN accounting_periods p ON p.id = b.accounting_period_id
                JOIN accounting_accounts a ON a.id = b.account_id
            ), live AS (
                SELECT p.month, l.account_id, l.currency, sum(l.debit) AS d, sum(l.credit) AS c
                FROM accounting_periods p
                JOIN journal_entries e ON e.entry_date <= ((p.month || '-01')::date + interval '1 month' - interval '1 day')::date
                JOIN journal_entry_lines l ON l.journal_entry_id = e.id
                GROUP BY p.month, l.account_id, l.currency
            )
            SELECT coalesce(s.month, v.month) || ' ' || coalesce(s.account_id, v.account_id)::text || ' ' || coalesce(s.currency, v.currency)
                   || ': snapshot debit ' || coalesce(s.total_debit, 0) || ' credit ' || coalesce(s.total_credit, 0)
                   || ' · lines debit ' || coalesce(v.d, 0) || ' credit ' || coalesce(v.c, 0) AS finding
            FROM snap s
            FULL OUTER JOIN live v ON v.month = s.month AND v.account_id = s.account_id AND v.currency = s.currency
            WHERE s.total_debit IS DISTINCT FROM v.d OR s.total_credit IS DISTINCT FROM v.c
               OR s.balance IS DISTINCT FROM CASE WHEN s.type IN ('asset', 'expense') THEN v.d - v.c ELSE v.c - v.d END
            ORDER BY 1
        SQL);
    }

    /**
     * @return list<string>
     */
    private function receipts(): array
    {
        return $this->findings(<<<'SQL'
            SELECT '#' || r.number || ' ' || r.type || ' ' || c.currency || ': receipt ' || c.amount || ' · cash ' || coalesce(j.moved, 0) AS finding
            FROM receipts r
            JOIN LATERAL (
                SELECT 'VES' AS currency, r.amount_ves AS amount UNION ALL SELECT 'USD', r.amount_usd UNION ALL SELECT 'COP', r.amount_cop
            ) c ON TRUE
            LEFT JOIN LATERAL (
                SELECT sum(CASE WHEN r.type = 'income' THEN jl.debit - jl.credit ELSE jl.credit - jl.debit END) AS moved
                FROM journal_entry_lines jl JOIN accounting_accounts a ON a.id = jl.account_id
                WHERE jl.journal_entry_id = r.journal_entry_id AND a.system_function = 'cash' AND jl.currency = c.currency
            ) j ON TRUE
            WHERE c.amount <> coalesce(j.moved, 0)
            ORDER BY r.number
        SQL);
    }

    /**
     * @return list<string>
     */
    private function findings(string $sql): array
    {
        return array_values(array_map(fn (object $row): string => (string) ((array) $row)['finding'], array_slice(DB::select($sql), 0, self::LIMIT)));
    }
}
