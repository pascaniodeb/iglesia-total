<?php

namespace App\Modules\Accounting\Application\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Recomputes the journal's arithmetic from the stored lines, independently of the triggers
 * that guarded every insert (a trigger can be disabled by the table owner; this check cannot
 * be skipped by whoever reads its result):
 *
 * 1. Global balance: sum(debit) = sum(credit) for each currency across the whole journal.
 * 2. Every entry: at least two lines, as many as lines_count, balanced and positive in every
 *    currency it uses.
 * 3. Every reversal inverts its original exactly.
 */
final class VerifyJournal
{
    /**
     * @return array{
     *     entries: int,
     *     currencies: array<string, array{debit: string, credit: string, balanced: bool}>,
     *     unbalanced_entries: list<string>,
     *     mismatched_reversals: list<string>,
     *     passed: bool
     * }
     */
    public function __invoke(int $limit = 50): array
    {
        $currencies = [];

        foreach (DB::select('SELECT currency, sum(debit)::text AS debit, sum(credit)::text AS credit FROM journal_entry_lines GROUP BY currency ORDER BY currency') as $row) {
            $currencies[trim($row->currency)] = [
                'debit' => $row->debit,
                'credit' => $row->credit,
                'balanced' => $row->debit === $row->credit,
            ];
        }

        $unbalanced = DB::select(<<<'SQL'
            SELECT e.id::text AS id FROM journal_entries e
            WHERE (SELECT count(*) FROM journal_entry_lines l WHERE l.journal_entry_id = e.id) NOT IN (e.lines_count)
               OR e.lines_count < 2
               OR EXISTS (
                   SELECT 1 FROM journal_entry_lines l WHERE l.journal_entry_id = e.id
                   GROUP BY l.currency HAVING sum(l.debit) <> sum(l.credit) OR sum(l.debit) <= 0
               )
            ORDER BY e.id
            LIMIT ?
        SQL, [$limit]);

        $mismatched = DB::select(<<<'SQL'
            SELECT r.id::text AS id FROM journal_entries r
            WHERE r.reversal_of IS NOT NULL AND EXISTS (
                SELECT 1 FROM (
                    SELECT account_id, currency, sum(debit) AS d, sum(credit) AS c
                    FROM journal_entry_lines WHERE journal_entry_id = r.reversal_of GROUP BY account_id, currency
                ) o
                FULL OUTER JOIN (
                    SELECT account_id, currency, sum(debit) AS d, sum(credit) AS c
                    FROM journal_entry_lines WHERE journal_entry_id = r.id GROUP BY account_id, currency
                ) x ON x.account_id = o.account_id AND x.currency = o.currency
                WHERE o.account_id IS NULL OR x.account_id IS NULL OR o.d <> x.c OR o.c <> x.d
            )
            ORDER BY r.id
            LIMIT ?
        SQL, [$limit]);

        $unbalancedIds = array_column($unbalanced, 'id');
        $mismatchedIds = array_column($mismatched, 'id');

        return [
            'entries' => DB::table('journal_entries')->count(),
            'currencies' => $currencies,
            'unbalanced_entries' => $unbalancedIds,
            'mismatched_reversals' => $mismatchedIds,
            'passed' => $unbalancedIds === [] && $mismatchedIds === [] && collect($currencies)->every(fn (array $totals) => $totals['balanced']),
        ];
    }
}
