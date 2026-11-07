<?php

namespace App\Modules\Accounting\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The cash statement between two days, per currency, computed by PostgreSQL on numeric from the
 * journal:
 *
 *   closing = opening + income − expense + capital + exchange
 *
 * - opening: CAJA before the first day (D3: derived, never written). Over a quarter or a year it is
 *   the balance before the first month, never the sum of each month's opening (the old I-001 rule:
 *   February's opening already holds January's result);
 * - income / expense: the net movement of the income and expense accounts in the span;
 * - capital: the opening balance brought from the books before this system (I-001), only when it
 *   was recorded inside the span;
 * - exchange: what currency exchanges added to or took from CAJA in that currency;
 * - closing: CAJA at the last day.
 *
 * The equation holds by double entry (every entry balances in each currency); it is returned so
 * a reader (and the sealed ledger) can check it. MonthSummary is this statement for one month.
 */
final class CashStatement
{
    public const array CURRENCIES = ['VES', 'USD', 'COP'];

    /**
     * @return array{
     *     currencies: array<string, array{opening: string, income: string, expense: string, capital: string, exchange: string, closing: string}>,
     *     by_code: list<array{code: string, name: string, type: string, entries: int, ves: string, usd: string, cop: string}>
     * }
     */
    public static function between(DateTimeImmutable $firstDay, DateTimeImmutable $lastDay): array
    {
        $first = $firstDay->format('Y-m-d');
        $last = $lastDay->format('Y-m-d');

        $rows = DB::select(<<<'SQL'
            WITH lines AS (
                SELECT l.currency, l.debit, l.credit, e.entry_date, a.type, a.system_function
                FROM journal_entry_lines l
                JOIN journal_entries e ON e.id = l.journal_entry_id
                JOIN accounting_accounts a ON a.id = l.account_id
                WHERE e.entry_date <= :last
            )
            SELECT c.currency,
                   coalesce(sum(debit - credit) FILTER (WHERE system_function = 'cash' AND entry_date < :first), 0)::numeric(18,2)::text AS opening,
                   coalesce(sum(credit - debit) FILTER (WHERE type = 'income' AND entry_date >= :first), 0)::numeric(18,2)::text AS income,
                   coalesce(sum(debit - credit) FILTER (WHERE type = 'expense' AND entry_date >= :first), 0)::numeric(18,2)::text AS expense,
                   coalesce(sum(credit - debit) FILTER (WHERE system_function = 'opening_balance' AND entry_date >= :first), 0)::numeric(18,2)::text AS capital,
                   coalesce(sum(credit - debit) FILTER (WHERE system_function = 'currency_exchange' AND entry_date >= :first), 0)::numeric(18,2)::text AS exchange,
                   coalesce(sum(debit - credit) FILTER (WHERE system_function = 'cash'), 0)::numeric(18,2)::text AS closing
            FROM (VALUES ('VES'), ('USD'), ('COP')) AS c(currency)
            LEFT JOIN lines USING (currency)
            GROUP BY c.currency
        SQL, ['first' => $first, 'last' => $last]);

        $currencies = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $currencies[(string) $row['currency']] = [
                'opening' => (string) $row['opening'],
                'income' => (string) $row['income'],
                'expense' => (string) $row['expense'],
                'capital' => (string) $row['capital'],
                'exchange' => (string) $row['exchange'],
                'closing' => (string) $row['closing'],
            ];
        }

        $codes = DB::select(<<<'SQL'
            SELECT a.code, a.name, a.type,
                   count(DISTINCT e.id) AS entries,
                   coalesce(sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END) FILTER (WHERE l.currency = 'VES'), 0)::numeric(18,2)::text AS ves,
                   coalesce(sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END) FILTER (WHERE l.currency = 'USD'), 0)::numeric(18,2)::text AS usd,
                   coalesce(sum(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE l.debit - l.credit END) FILTER (WHERE l.currency = 'COP'), 0)::numeric(18,2)::text AS cop
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE e.entry_date BETWEEN :first AND :last AND a.type IN ('income', 'expense')
            GROUP BY a.code, a.name, a.type
            ORDER BY a.code
        SQL, ['first' => $first, 'last' => $last]);

        $byCode = [];

        foreach ($codes as $code) {
            $code = (array) $code;
            $byCode[] = [
                'code' => (string) $code['code'],
                'name' => (string) $code['name'],
                'type' => (string) $code['type'],
                'entries' => (int) $code['entries'],
                'ves' => (string) $code['ves'],
                'usd' => (string) $code['usd'],
                'cop' => (string) $code['cop'],
            ];
        }

        return ['currencies' => $currencies, 'by_code' => $byCode];
    }
}
