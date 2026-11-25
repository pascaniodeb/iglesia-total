<?php

namespace App\Console\Reconciliation;

use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Application\Actions\VerifyLedgerChain;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\Month;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Compares the new books with the old system, figure by figure, to the cent and with no tolerance.
 *
 * Each figure is computed by every path that can compute it and all of them must agree: the old
 * system's own rows, the new books through PostgreSQL (the same queries the screens and the seal
 * use) and the new books again through a fold of the individual rows in PHP with exact decimals,
 * plus the sealed copies (the old chain's payloads and the new chain's). A bug in one path cannot
 * hide behind the same bug in another. Read-only; it can be run as many times as wanted.
 *
 * The checks (section 4.3 of the plan):
 *  1. record by record: every live old offering and manual entry is in the new books, equal;
 *  2. per week: count and totals, old closure = old seal = new closure = new seal = the offerings;
 *  3. per month and account code: income and expense per currency;
 *  4. per month: opening + income − expense = closing, the closing is the next opening, and the
 *     offerings are what was posted;
 *  5. global: debits = credits, and the cash balance is what the old books add up to;
 *  6. the sealed monthly payloads of the old chain against the new books;
 *  7. both chains intact.
 */
final class LegacyReconciliation
{
    private const array CURRENCIES = ['ves' => Currency::VES, 'usd' => Currency::USD, 'cop' => Currency::COP];

    private const string OPENING = 'I-001';

    private ReconciliationReport $report;

    private Connection $legacy;

    public function __construct(private readonly VerifyLedgerChain $chains) {}

    public function __invoke(string $connection): ReconciliationReport
    {
        $this->report = new ReconciliationReport;
        $this->legacy = DB::connection($connection);

        $codes = $this->legacy->table('account_codes')->get()->keyBy('id');
        $entries = $this->legacy->table('accounting_entries')->whereNull('deleted_at')->orderBy('entry_date')->orderBy('id')->get()
            ->each(function (stdClass $entry) use ($codes): void {
                $entry->code = (string) $codes[$entry->account_code_id]->code;
            });
        $trashedEntries = array_values(array_map(intval(...), $this->legacy->table('accounting_entries')->whereNotNull('deleted_at')->pluck('id')->all()));
        $months = $this->months($entries);

        $this->report->title('books', '0. Something to compare');
        $this->report->add('books', 'old system', 'live entries to compare', ['found' => (string) $entries->count(), 'expected' => $entries->isEmpty() ? 'at least 1' : (string) $entries->count()]);

        $this->offerings();
        $this->manualEntries($entries, $trashedEntries);
        $this->weeks();
        $this->monthsByCode($entries, $months);
        $this->monthEquations($entries, $months);
        $this->global($entries);
        $this->sealedMonths($entries, $months);
        $this->chains();

        return $this->report;
    }

    /**
     * 1a. Every live offering of the old system is in the new books, equal in every field; none of
     * the trash came in; the totals per currency agree.
     */
    private function offerings(): void
    {
        $this->report->title('offerings', '1. Offerings, one by one');

        $old = $this->legacy->table('offerings')->whereNull('deleted_at')->orderBy('id')->get();
        $trashed = $this->legacy->table('offerings')->whereNotNull('deleted_at')->pluck('id')->all();

        /** @var array<int, string> $members */
        $members = DB::table('members')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        /** @var array<int, string> $categories */
        $categories = DB::table('offering_categories')->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();
        $new = DB::table('offerings')->whereNotNull('legacy_id')->whereNull('deleted_at')->get()->keyBy('legacy_id');

        $matched = 0;

        foreach ($old as $row) {
            $current = $new->get((int) $row->id);
            $differs = $current === null ? ['missing'] : array_keys(array_filter([
                'date' => (string) $current->offering_date !== (string) $row->date,
                'member' => $current->member_id !== ($row->member_id === null ? null : ($members[(int) $row->member_id] ?? 'unmapped')),
                'category' => $current->offering_category_id !== ($categories[(int) $row->offering_category_id] ?? 'unmapped'),
                'concept' => $current->concept !== $row->concept,
                'VES' => $this->m($current->amount_ves, Currency::VES) !== $this->m($row->amount_ves, Currency::VES),
                'USD' => $this->m($current->amount_usd, Currency::USD) !== $this->m($row->amount_usd, Currency::USD),
                'COP' => $this->m($current->amount_cop, Currency::COP) !== $this->m($row->amount_cop, Currency::COP),
            ]));

            if ($differs === []) {
                $matched++;

                continue;
            }

            $this->report->add('offerings', "#{$row->id} of {$row->date}", 'record', ['old system' => 'present', 'new books' => 'differs: '.implode(', ', $differs)]);
        }

        $this->report->add('offerings', 'all', 'live offerings', [
            'old system' => (string) $old->count(),
            'new SQL' => (string) DB::table('offerings')->whereNotNull('legacy_id')->whereNull('deleted_at')->count(),
            'new PHP' => (string) $matched,
        ]);
        $this->report->add('offerings', 'all', 'offerings of the old trash that came in', [
            'old system' => '0',
            'new SQL' => (string) DB::table('offerings')->whereIn('legacy_id', $trashed === [] ? [0] : $trashed)->count(),
        ]);

        $sql = DB::table('offerings')->whereNotNull('legacy_id')->whereNull('deleted_at')
            ->selectRaw('coalesce(sum(amount_ves), 0)::numeric(18,2)::text AS ves, coalesce(sum(amount_usd), 0)::numeric(18,2)::text AS usd, coalesce(sum(amount_cop), 0)::numeric(18,2)::text AS cop')->first();

        foreach (self::CURRENCIES as $column => $currency) {
            $this->report->add('offerings', 'all', "total {$currency->value}", [
                'old system' => $this->fold($old->pluck("amount_{$column}")->all(), $currency),
                'new SQL' => $this->m($sql->{$column}, $currency),
                'new PHP' => $this->fold($new->pluck("amount_{$column}")->all(), $currency),
            ]);
        }
    }

    /**
     * 1b. Every live manual entry of the old system is one journal entry that moves CAJA and its
     * account by exactly its amounts, balanced in each currency; the opening capital is the first
     * month's I-001; nothing of the trash came in.
     *
     * @param  Collection<int, stdClass>  $entries  live legacy entries with their code
     * @param  list<int>  $trashedEntries
     */
    private function manualEntries(Collection $entries, array $trashedEntries): void
    {
        $this->report->title('entries', '2. Manual entries, one by one');

        $manual = $entries->whereNull('monthly_closure_id')->where('code', '!=', self::OPENING);
        $first = (string) $entries->min('month_key');
        $opening = $entries->whereNull('monthly_closure_id')->where('code', self::OPENING)->firstWhere('month_key', $first);

        $lines = DB::select(<<<'SQL'
            SELECT e.origin_id, e.origin_purpose, a.code, a.system_function, l.currency, l.debit::text AS debit, l.credit::text AS credit
            FROM journal_entries e
            JOIN journal_entry_lines l ON l.journal_entry_id = e.id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE e.origin_type = 'legacy_entry'
        SQL);

        $byOrigin = [];

        foreach ($lines as $line) {
            $byOrigin[(string) $line->origin_id][] = $line;
        }

        $matched = 0;
        $income = [];
        $expense = [];

        foreach ($manual as $entry) {
            $found = $byOrigin[(string) $entry->id] ?? [];
            $problems = $found === [] ? ['missing'] : $this->entryProblems($entry, $found);

            if ($problems === []) {
                $matched++;
            } else {
                $this->report->add('entries', "#{$entry->id} of {$entry->entry_date}", 'record', ['old system' => "{$entry->code}", 'new books' => 'differs: '.implode(', ', $problems)]);
            }
        }

        $this->report->add('entries', 'all', 'live manual entries', [
            'old system' => (string) $manual->count(),
            'new SQL' => (string) DB::table('journal_entries')->where('origin_type', 'legacy_entry')->where('origin_purpose', '!=', 'opening')->count(),
            'new PHP' => (string) $matched,
        ]);
        $this->report->add('entries', 'all', 'entries of the old trash that came in', [
            'old system' => '0',
            'new SQL' => (string) DB::table('journal_entries')->where('origin_type', 'legacy_entry')->whereIn('origin_id', array_map(strval(...), $trashedEntries === [] ? [0] : $trashedEntries))->count(),
        ]);

        foreach (self::CURRENCIES as $column => $currency) {
            $oldIncome = $this->fold($manual->where('type', 'income')->pluck("amount_{$column}")->all(), $currency);
            $oldExpense = $this->fold($manual->where('type', 'expense')->pluck("amount_{$column}")->all(), $currency);

            foreach ($lines as $line) {
                if ($line->origin_purpose === 'opening' || $line->currency !== $currency->value || $line->system_function !== null) {
                    continue;
                }

                $line->origin_purpose === 'income' ? $income[$column][] = $line->credit : $expense[$column][] = $line->debit;
            }

            $this->report->add('entries', 'all', "manual income {$currency->value}", ['old system' => $oldIncome, 'new PHP' => $this->fold($income[$column] ?? [], $currency)]);
            $this->report->add('entries', 'all', "manual expense {$currency->value}", ['old system' => $oldExpense, 'new PHP' => $this->fold($expense[$column] ?? [], $currency)]);

            $capital = collect($byOrigin[(string) ($opening->id ?? 0)] ?? [])->filter(fn (stdClass $line): bool => $line->code === self::OPENING && $line->currency === $currency->value)->pluck('credit')->all();
            $this->report->add('entries', $first, "opening capital {$currency->value}", [
                'old system' => $opening === null ? '0.00' : $this->m($opening->{"amount_{$column}"}, $currency),
                'new PHP' => $this->fold($capital, $currency),
            ]);
        }
    }

    /**
     * What is wrong with the journal entry of a legacy entry, if anything.
     *
     * @param  list<stdClass>  $lines
     * @return list<string>
     */
    private function entryProblems(stdClass $entry, array $lines): array
    {
        $problems = [];
        $isIncome = $entry->type === 'income';

        foreach (self::CURRENCIES as $column => $currency) {
            $amount = $this->m($entry->{"amount_{$column}"}, $currency);
            $of = collect($lines)->where('currency', $currency->value);
            $cash = $of->filter(fn (stdClass $line): bool => $line->system_function === 'cash');
            $account = $of->filter(fn (stdClass $line): bool => $line->code === $entry->code);

            $cashMoved = $this->fold($cash->pluck($isIncome ? 'debit' : 'credit')->all(), $currency);
            $cashOpposite = $this->fold($cash->pluck($isIncome ? 'credit' : 'debit')->all(), $currency);
            $accountMoved = $this->fold($account->pluck($isIncome ? 'credit' : 'debit')->all(), $currency);
            $debits = $this->fold($of->pluck('debit')->all(), $currency);
            $credits = $this->fold($of->pluck('credit')->all(), $currency);

            if ($cashMoved !== $amount || $cashOpposite !== $this->m('0', $currency)) {
                $problems[] = "CAJA {$currency->value}";
            }

            if ($accountMoved !== $amount) {
                $problems[] = "{$entry->code} {$currency->value}";
            }

            if ($debits !== $credits) {
                $problems[] = "unbalanced {$currency->value}";
            }
        }

        return $problems;
    }

    /**
     * 2. Per week: the old closure, the old seal, the new closure, the new seal and the offerings
     * themselves (summed by PostgreSQL and folded in PHP) must give the same count and totals.
     */
    private function weeks(): void
    {
        $this->report->title('weeks', '3. Weeks: count and totals');

        $closures = $this->legacy->table('weekly_closures')->whereNull('deleted_at')->where('status', 'closed')->orderBy('week_start')->get();
        $oldSeals = $this->legacy->table('ledger_blocks')->where('kind', 'weekly')->get()->keyBy('period_key');
        $newClosures = DB::table('weekly_closures')->get()->keyBy('week_key');
        $newSeals = DB::table('ledger_blocks')->where('kind', 'weekly')->get()->keyBy('period_key');

        $sql = DB::table('offerings')->whereNull('deleted_at')->groupBy('week_key')
            ->selectRaw('week_key, count(*)::text AS n, sum(amount_ves)::numeric(18,2)::text AS ves, sum(amount_usd)::numeric(18,2)::text AS usd, sum(amount_cop)::numeric(18,2)::text AS cop')->get()->keyBy('week_key');
        $rows = DB::table('offerings')->whereNull('deleted_at')->get(['week_key', 'amount_ves', 'amount_usd', 'amount_cop'])->groupBy('week_key');

        foreach ($closures as $closure) {
            $key = (string) $closure->week_key;
            $oldSeal = isset($oldSeals[$key]) ? $this->payload($oldSeals[$key]->payload) : null;
            $newSeal = isset($newSeals[$key]) ? $this->payload($newSeals[$key]->payload) : null;
            $newClosure = $newClosures->get($key);
            $week = $rows->get($key, collect());

            $this->report->add('weeks', $key, 'offerings', [
                'old closure' => (string) $closure->offerings_count,
                'old seal' => $oldSeal === null ? null : $this->pick($oldSeal, ['offeringsCount']),
                'new closure' => $newClosure === null ? '' : (string) $newClosure->offerings_count,
                'new seal' => $newSeal === null ? '' : $this->pick($newSeal, ['offeringsCount']),
                'new SQL' => (string) ($sql->get($key)->n ?? '0'),
                'new PHP' => (string) $week->count(),
            ]);

            foreach (self::CURRENCIES as $column => $currency) {
                $this->report->add('weeks', $key, $currency->value, [
                    'old closure' => $this->m($closure->{"total_{$column}"}, $currency),
                    'old seal' => $oldSeal === null ? null : $this->m($this->pick($oldSeal, ['totals', $column]), $currency),
                    'new closure' => $newClosure === null ? '' : $this->m($newClosure->{"total_{$column}"}, $currency),
                    'new seal' => $newSeal === null ? '' : $this->m($this->pick($newSeal, ['totals', $column]), $currency),
                    'new SQL' => $this->m($sql->get($key)->{$column} ?? '0', $currency),
                    'new PHP' => $this->fold($week->pluck("amount_{$column}")->all(), $currency),
                ]);
            }
        }
    }

    /**
     * 3. Per month, account code and currency: income and expense of the old entries (the
     * offering consolidations included) against the new journal.
     *
     * @param  Collection<int, stdClass>  $entries
     * @param  list<string>  $months
     */
    private function monthsByCode(Collection $entries, array $months): void
    {
        $this->report->title('codes', '4. Income and expense per month and account code');

        $old = [];

        foreach ($entries->where('code', '!=', self::OPENING) as $entry) {
            foreach (self::CURRENCIES as $column => $currency) {
                $old[$entry->month_key][$entry->code][$column][] = $entry->{"amount_{$column}"};
            }
        }

        $lines = DB::select(<<<'SQL'
            SELECT e.month, a.code, a.type, l.currency, l.debit::text AS debit, l.credit::text AS credit
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE a.type IN ('income', 'expense')
        SQL);

        $php = [];

        foreach ($lines as $line) {
            $value = $line->type === 'income' ? $this->m($line->credit, Currency::from($line->currency)) : $this->m($line->debit, Currency::from($line->currency));
            $other = $line->type === 'income' ? $this->m($line->debit, Currency::from($line->currency)) : $this->m($line->credit, Currency::from($line->currency));
            $php[$line->month][$line->code][strtolower($line->currency)][] = $value;
            $php[$line->month][$line->code][strtolower($line->currency)][] = '-'.$other;
        }

        foreach ($months as $key) {
            $sql = [];

            foreach (MonthSummary::of(Month::fromKey($key))['by_code'] as $row) {
                foreach (self::CURRENCIES as $column => $currency) {
                    $sql[$row['code']][$column] = $this->m($row[$column], $currency);
                }
            }

            foreach (array_unique([...array_keys($old[$key] ?? []), ...array_keys($sql), ...array_keys($php[$key] ?? [])]) as $code) {
                foreach (self::CURRENCIES as $column => $currency) {
                    $values = [
                        'old system' => $this->fold($old[$key][$code][$column] ?? [], $currency),
                        'new SQL' => $sql[$code][$column] ?? $this->m('0', $currency),
                        'new PHP' => $this->fold($php[$key][$code][$column] ?? [], $currency),
                    ];

                    if (count(array_unique($values)) === 1 && $values['old system'] === $this->m('0', $currency)) {
                        continue;
                    }

                    $this->report->add('codes', "{$key} {$code}", $currency->value, $values);
                }
            }
        }
    }

    /**
     * 4. Per month and currency: the old closing (its opening plus income minus expense), the new
     * closing computed by PostgreSQL and the one rebuilt from CAJA's lines must agree; the
     * equation of the statement must hold; the closing is the next month's opening (also the old
     * system's I-001); and the offerings are what was posted to the journal.
     *
     * @param  Collection<int, stdClass>  $entries
     * @param  list<string>  $months
     */
    private function monthEquations(Collection $entries, array $months): void
    {
        $this->report->title('months', '5. Each month: the equation, the carry-forward and the offerings posted');

        $cash = DB::select(<<<'SQL'
            SELECT e.month, l.currency, l.debit::text AS debit, l.credit::text AS credit
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE a.system_function = 'cash'
        SQL);

        $posted = DB::select(<<<'SQL'
            SELECT e.month, l.currency, l.credit::text AS credit
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE e.origin_type = 'monthly_offering_closure' AND a.type = 'income'
        SQL);

        $summaries = [];

        foreach ($months as $key) {
            $summaries[$key] = MonthSummary::of(Month::fromKey($key))['currencies'];
        }

        foreach ($months as $index => $key) {
            foreach (self::CURRENCIES as $column => $currency) {
                $code = $currency->value;
                $rows = $entries->where('month_key', $key);
                $opening = $this->fold($rows->where('code', self::OPENING)->pluck("amount_{$column}")->all(), $currency);
                $income = $this->fold($rows->where('code', '!=', self::OPENING)->where('type', 'income')->pluck("amount_{$column}")->all(), $currency);
                $expense = $this->fold($rows->where('code', '!=', self::OPENING)->where('type', 'expense')->pluck("amount_{$column}")->all(), $currency);
                $oldClosing = Money::of($opening, $currency)->plus(Money::of($income, $currency))->minus(Money::of($expense, $currency))->amount();

                $fromLines = [];

                foreach ($cash as $line) {
                    if ($line->currency === $code && $line->month <= $key) {
                        $fromLines[] = $this->m($line->debit, $currency);
                        $fromLines[] = '-'.$this->m($line->credit, $currency);
                    }
                }

                $statement = $summaries[$key][$code];
                $equation = Money::of($statement['opening'], $currency)->plus(Money::of($statement['income'], $currency))->minus(Money::of($statement['expense'], $currency))
                    ->plus(Money::of($statement['capital'], $currency))->plus(Money::of($statement['exchange'], $currency))->amount();

                $this->report->add('months', $key, "closing {$code}", [
                    'old system' => $oldClosing,
                    'new SQL' => $statement['closing'],
                    'new PHP' => $this->fold($fromLines, $currency),
                    'statement equation' => $equation,
                ]);

                $next = $months[$index + 1] ?? null;

                if ($next !== null) {
                    $oldNextOpening = $this->fold($entries->where('month_key', $next)->where('code', self::OPENING)->pluck("amount_{$column}")->all(), $currency);
                    $this->report->add('months', "{$key} → {$next}", "closing = next opening {$code}", [
                        'old opening of next month (I-001)' => $oldNextOpening,
                        'new SQL closing' => $statement['closing'],
                        'new SQL next opening' => $summaries[$next][$code]['opening'],
                        'new PHP closing from CAJA lines' => $this->fold($fromLines, $currency),
                    ]);
                }

                $oldConsolidated = $this->fold($entries->where('month_key', $key)->whereNotNull('monthly_closure_id')->pluck("amount_{$column}")->all(), $currency);
                $sqlOfferings = DB::table('offerings')->whereNull('deleted_at')->where('month_key', $key)->selectRaw("coalesce(sum(amount_{$column}), 0)::numeric(18,2)::text AS total")->value('total');
                $postedAmounts = collect($posted)->filter(fn (stdClass $line): bool => $line->month === $key && $line->currency === $code)->pluck('credit')->all();

                if (AccountingPeriod::isClosed($key)) {
                    $this->report->add('months', $key, "offerings posted {$code}", [
                        'old system' => $oldConsolidated,
                        'new SQL' => $this->m((string) $sqlOfferings, $currency),
                        'new PHP' => $this->fold($postedAmounts, $currency),
                    ]);
                }
            }
        }
    }

    /**
     * 5. Global: every currency balances, and the cash is what the old books add up to.
     *
     * @param  Collection<int, stdClass>  $entries
     */
    private function global(Collection $entries): void
    {
        $this->report->title('global', '6. The whole books: balanced, and the cash');

        $first = (string) $entries->min('month_key');
        $last = (string) $entries->max('month_key');
        $lines = DB::select(<<<'SQL'
            SELECT l.currency, a.system_function, l.debit::text AS debit, l.credit::text AS credit
            FROM journal_entry_lines l JOIN accounting_accounts a ON a.id = l.account_id
        SQL);
        $sql = DB::select(<<<'SQL'
            SELECT l.currency, sum(l.debit)::numeric(18,2)::text AS debit, sum(l.credit)::numeric(18,2)::text AS credit,
                   coalesce(sum(l.debit - l.credit) FILTER (WHERE a.system_function = 'cash'), 0)::numeric(18,2)::text AS cash
            FROM journal_entry_lines l JOIN accounting_accounts a ON a.id = l.account_id
            GROUP BY l.currency
        SQL);
        $byCurrency = collect($sql)->keyBy('currency');

        foreach (self::CURRENCIES as $column => $currency) {
            $code = $currency->value;
            $mine = collect($lines)->where('currency', $code);
            $row = $byCurrency->get($code);

            $this->report->add('global', 'all', "debits = credits {$code}", [
                'debits SQL' => $this->m($row->debit ?? '0', $currency),
                'credits SQL' => $this->m($row->credit ?? '0', $currency),
                'debits PHP' => $this->fold($mine->pluck('debit')->all(), $currency),
                'credits PHP' => $this->fold($mine->pluck('credit')->all(), $currency),
            ]);

            $firstOpening = $this->fold($entries->where('month_key', $first)->where('code', self::OPENING)->pluck("amount_{$column}")->all(), $currency);
            $rows = $entries->where('code', '!=', self::OPENING);
            $oldCash = Money::of($firstOpening, $currency)
                ->plus(Money::of($this->fold($rows->where('type', 'income')->pluck("amount_{$column}")->all(), $currency), $currency))
                ->minus(Money::of($this->fold($rows->where('type', 'expense')->pluck("amount_{$column}")->all(), $currency), $currency))->amount();

            $this->report->add('global', 'all', "cash balance {$code}", [
                'old system' => $oldCash,
                'new SQL' => $this->m($row->cash ?? '0', $currency),
                'new PHP' => $this->fold($mine->where('system_function', 'cash')->flatMap(fn (stdClass $line): array => [$line->debit, '-'.$line->credit])->all(), $currency),
                'old last opening (I-001)' => $this->fold($entries->where('month_key', $last)->where('code', self::OPENING)->pluck("amount_{$column}")->all(), $currency),
            ]);
        }
    }

    /**
     * 6. The monthly payloads the old chain sealed, against the old books they were sealed from,
     * the new books and the new seal. The old seal is advisory: the old system let entries be
     * created in a month after it was sealed, so a stale old seal is a finding about the old
     * system. What must agree is the old books (the entries) with the new books.
     *
     * @param  Collection<int, stdClass>  $entries
     * @param  list<string>  $months
     */
    private function sealedMonths(Collection $entries, array $months): void
    {
        $this->report->title('seals', '7. Monthly seals: the old chain, the old books and the new books');

        $oldSeals = $this->legacy->table('ledger_blocks')->where('kind', 'monthly')->orderBy('period_key')->get();
        $newSeals = DB::table('ledger_blocks')->where('kind', 'monthly')->get()->keyBy('period_key');

        foreach ($oldSeals as $block) {
            $key = (string) $block->period_key;

            if (! in_array($key, $months, true)) {
                continue;
            }

            $old = $this->payload($block->payload);
            $new = isset($newSeals[$key]) ? $this->payload($newSeals[$key]->payload) : null;
            $summary = MonthSummary::of(Month::fromKey($key));

            foreach (self::CURRENCIES as $column => $currency) {
                $code = $currency->value;

                $rows = $entries->where('month_key', $key);
                $books = [
                    'opening' => $this->fold($rows->where('code', self::OPENING)->pluck("amount_{$column}")->all(), $currency),
                    'income' => $this->fold($rows->where('code', '!=', self::OPENING)->where('type', 'income')->pluck("amount_{$column}")->all(), $currency),
                    'expense' => $this->fold($rows->where('code', '!=', self::OPENING)->where('type', 'expense')->pluck("amount_{$column}")->all(), $currency),
                ];
                $books['closing'] = Money::of($books['opening'], $currency)->plus(Money::of($books['income'], $currency))->minus(Money::of($books['expense'], $currency))->amount();

                foreach (['opening', 'income', 'expense', 'closing'] as $figure) {
                    $this->report->add('seals', $key, "{$figure} {$code}", [
                        'old seal' => $this->m($this->pick($old, [$figure, $column]), $currency),
                        'old books' => $books[$figure],
                        'new SQL' => $this->withCapital($figure, $summary['currencies'][$code], $currency),
                        'new seal' => $new === null ? '' : $this->withCapital($figure, $this->arrayAt($new, ['currencies', $code]), $currency),
                    ], ['old seal']);
                }
            }

            $oldByCode = $this->byCode($old);
            $newByCode = $new === null ? [] : $this->byCode($new);
            $sqlByCode = [];

            foreach ($summary['by_code'] as $row) {
                $sqlByCode[$row['code']] = $row;
            }

            $bookCodes = $entries->where('month_key', $key)->where('code', '!=', self::OPENING)->pluck('code')->unique()->all();

            foreach (array_unique([...array_keys($oldByCode), ...array_keys($sqlByCode), ...$bookCodes]) as $code) {
                foreach (self::CURRENCIES as $column => $currency) {
                    $this->report->add('seals', "{$key} {$code}", $currency->value, [
                        'old seal' => $this->m($this->pick($oldByCode[$code] ?? [], [$column]), $currency),
                        'old books' => $this->fold($entries->where('month_key', $key)->where('code', $code)->pluck("amount_{$column}")->all(), $currency),
                        'new SQL' => $this->m($sqlByCode[$code][$column] ?? '0', $currency),
                        'new seal' => $new === null ? '' : $this->m($this->pick($newByCode[$code] ?? [], [$column]), $currency),
                    ], ['old seal']);
                }
            }
        }
    }

    /**
     * 7. Both chains intact, and the new one matching the books it sealed.
     */
    private function chains(): void
    {
        $this->report->title('chains', '8. The chains');

        $result = ($this->chains)();

        $this->report->add('chains', 'new chain', 'blocks verified', [
            'expected' => 'intact and matching the books',
            'found' => $result['matches_books'] ? 'intact and matching the books' : count($result['issues']).' issues',
        ]);
        $this->report->add('chains', 'old chain (archived)', 'blocks verified', [
            'expected' => 'intact',
            'found' => $result['legacy']['intact'] ? 'intact' : count($result['legacy']['issues']).' issues',
        ]);
        $this->report->add('chains', 'new chain', 'seals pending', [
            'expected' => '0',
            'found' => (string) count($result['unsealed']),
        ]);
    }

    /**
     * Every month from the first old entry to the last.
     *
     * @param  Collection<int, stdClass>  $entries
     * @return list<string>
     */
    private function months(Collection $entries): array
    {
        $months = [];

        if ($entries->isEmpty()) {
            return [];
        }

        for ($month = Month::fromKey((string) $entries->min('month_key')); $month->key() <= (string) $entries->max('month_key'); $month = $month->next()) {
            $months[] = $month->key();
        }

        return $months;
    }

    /**
     * A figure of a new statement as the old system counted it: the old system's opening balance is
     * what the new books call opening plus the capital posted at the start of the history.
     *
     * @param  array<array-key, mixed>  $statement
     */
    private function withCapital(string $figure, array $statement, Currency $currency): string
    {
        $value = $this->m($statement[$figure] ?? '0', $currency);

        return $figure === 'opening' ? Money::of($value, $currency)->plus(Money::of($this->m($statement['capital'] ?? '0', $currency), $currency))->amount() : $value;
    }

    /**
     * The array at a path of a payload.
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $payload, array $path): array
    {
        $value = $payload;

        foreach ($path as $key) {
            $value = is_array($value[$key] ?? null) ? $value[$key] : [];
        }

        return $value;
    }

    private function m(mixed $value, Currency $currency): string
    {
        $text = trim((string) ($value ?? ''));

        return Money::of($text === '' ? '0' : $text, $currency)->amount();
    }

    /**
     * A sealed payload as an array.
     *
     * @return array<string, mixed>
     */
    private function payload(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The value at a path of a payload, as text ("0" when it is not there).
     *
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $path
     */
    private function pick(array $payload, array $path): string
    {
        $value = $payload;

        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return '0';
            }

            $value = $value[$key];
        }

        return is_scalar($value) ? (string) $value : '0';
    }

    /**
     * The rows of the "byCode" list of a monthly payload, by code, without the opening balance.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<array-key, mixed>>
     */
    private function byCode(array $payload): array
    {
        $rows = [];

        foreach (is_array($payload['byCode'] ?? null) ? $payload['byCode'] : [] as $row) {
            if (is_array($row) && isset($row['code']) && $row['code'] !== self::OPENING) {
                $rows[(string) $row['code']] = $row;
            }
        }

        return $rows;
    }

    /**
     * An exact sum of decimal text (a "-" in front of a value subtracts it).
     *
     * @param  array<array-key, mixed>  $values
     */
    private function fold(array $values, Currency $currency): string
    {
        $sum = Money::zero($currency);

        foreach ($values as $value) {
            $text = (string) $value;
            $sum = str_starts_with($text, '-') ? $sum->minus(Money::of(substr($text, 1), $currency)) : $sum->plus(Money::of($text === '' ? '0' : $text, $currency));
        }

        return $sum->amount();
    }
}
