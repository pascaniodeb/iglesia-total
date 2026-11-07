<?php

namespace App\Modules\Accounting\Application\Queries;

use App\Modules\Accounting\Domain\Enums\NormalSide;
use App\Modules\Accounting\Infrastructure\Models\AccountingAccount;
use App\Modules\Shared\Time\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The general ledger (Libro Mayor) of one account over a period: its balance before the first day,
 * every entry that moved it with the running balance after each one, and its balance at the last
 * day. Each currency runs apart and nothing is converted.
 *
 * Amounts are on the account's normal side (debit − credit for assets and expenses, credit − debit
 * for equity and income), so a positive figure is what the account "holds": money in CAJA, income
 * earned, expense spent. All sums, the running ones included, are done by PostgreSQL on numeric.
 *
 * The movement of an income or expense account over a period equals its row in PeriodReport's
 * by_code for the same period: both add the same journal lines.
 */
final class GeneralLedger
{
    /**
     * @return array{
     *     account: array{code: string, name: string, type: string},
     *     opening: array<string, string>,
     *     movement: array<string, string>,
     *     closing: array<string, string>,
     *     entries: list<array{id: string, entry_date: string, description: string, origin: string, is_reversal: bool, is_reversed: bool, amounts: array<string, string>, balances: array<string, string>}>
     * }
     */
    public static function of(AccountingAccount $account, ReportPeriod $period): array
    {
        $bindings = [
            'account' => $account->id,
            'sign' => $account->type->normalSide() === NormalSide::Debit ? 1 : -1,
            'first' => $period->firstDay()->format('Y-m-d'),
            'last' => $period->lastDay()->format('Y-m-d'),
        ];

        $totals = (array) DB::selectOne(<<<'SQL'
            SELECT
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'VES' AND e.entry_date < :first), 0)::numeric(18,2)::text AS opening_ves,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'USD' AND e.entry_date < :first), 0)::numeric(18,2)::text AS opening_usd,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'COP' AND e.entry_date < :first), 0)::numeric(18,2)::text AS opening_cop,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'VES' AND e.entry_date >= :first), 0)::numeric(18,2)::text AS movement_ves,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'USD' AND e.entry_date >= :first), 0)::numeric(18,2)::text AS movement_usd,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'COP' AND e.entry_date >= :first), 0)::numeric(18,2)::text AS movement_cop,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'VES'), 0)::numeric(18,2)::text AS closing_ves,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'USD'), 0)::numeric(18,2)::text AS closing_usd,
                coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'COP'), 0)::numeric(18,2)::text AS closing_cop
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.account_id = :account AND e.entry_date <= :last
        SQL, $bindings);

        $rows = DB::select(<<<'SQL'
            WITH moves AS (
                SELECT e.id, e.entry_date, e.description, e.origin_type, e.reversal_of,
                       EXISTS (SELECT 1 FROM journal_entries r WHERE r.reversal_of = e.id) AS is_reversed,
                       coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'VES'), 0) AS ves,
                       coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'USD'), 0) AS usd,
                       coalesce(sum((l.debit - l.credit) * :sign) FILTER (WHERE l.currency = 'COP'), 0) AS cop
                FROM journal_entry_lines l
                JOIN journal_entries e ON e.id = l.journal_entry_id
                WHERE l.account_id = :account AND e.entry_date BETWEEN :first AND :last
                GROUP BY e.id
            )
            SELECT id::text, entry_date::text, description, origin_type, reversal_of IS NOT NULL AS is_reversal, is_reversed,
                   ves::numeric(18,2)::text AS ves, usd::numeric(18,2)::text AS usd, cop::numeric(18,2)::text AS cop,
                   (CAST(:opening_ves AS numeric) + sum(ves) OVER running)::numeric(18,2)::text AS balance_ves,
                   (CAST(:opening_usd AS numeric) + sum(usd) OVER running)::numeric(18,2)::text AS balance_usd,
                   (CAST(:opening_cop AS numeric) + sum(cop) OVER running)::numeric(18,2)::text AS balance_cop
            FROM moves
            WINDOW running AS (ORDER BY entry_date, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
            ORDER BY entry_date, id
        SQL, [
            ...$bindings,
            'opening_ves' => $totals['opening_ves'],
            'opening_usd' => $totals['opening_usd'],
            'opening_cop' => $totals['opening_cop'],
        ]);

        return [
            'account' => ['code' => $account->code, 'name' => $account->name, 'type' => $account->type->value],
            'opening' => self::perCurrency($totals, 'opening'),
            'movement' => self::perCurrency($totals, 'movement'),
            'closing' => self::perCurrency($totals, 'closing'),
            'entries' => array_values(array_map(function (object $row): array {
                $row = (array) $row;

                return [
                    'id' => (string) $row['id'],
                    'entry_date' => (string) $row['entry_date'],
                    'description' => (string) $row['description'],
                    'origin' => (string) $row['origin_type'],
                    'is_reversal' => (bool) $row['is_reversal'],
                    'is_reversed' => (bool) $row['is_reversed'],
                    'amounts' => ['VES' => (string) $row['ves'], 'USD' => (string) $row['usd'], 'COP' => (string) $row['cop']],
                    'balances' => ['VES' => (string) $row['balance_ves'], 'USD' => (string) $row['balance_usd'], 'COP' => (string) $row['balance_cop']],
                ];
            }, $rows)),
        ];
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, string>
     */
    private static function perCurrency(array $totals, string $prefix): array
    {
        return [
            'VES' => (string) $totals["{$prefix}_ves"],
            'USD' => (string) $totals["{$prefix}_usd"],
            'COP' => (string) $totals["{$prefix}_cop"],
        ];
    }
}
