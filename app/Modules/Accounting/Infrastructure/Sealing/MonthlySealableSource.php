<?php

namespace App\Modules\Accounting\Infrastructure\Sealing;

use App\Modules\Accounting\Application\OfferingClosures;
use App\Modules\Accounting\Application\Queries\MonthSummary;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Integrity\Domain\Ledger\Canonical;
use App\Modules\Integrity\Domain\Ledger\SealablePayload;
use App\Modules\Integrity\Domain\Ledger\SealableSource;
use App\Modules\Shared\Time\Month;
use Illuminate\Support\Facades\DB;

/**
 * What a closed accounting month seals: its cash statement per currency (opening, income,
 * expense, capital, exchange, closing: the equation that ties a month to the next), the net of
 * every income and expense code, the month's closed offerings, and one Merkle leaf per journal
 * line of the month, ordered by entry and line. Rebuilt on every verification: an entry or line
 * that moves behind the database guards shows up as drift.
 */
final readonly class MonthlySealableSource implements SealableSource
{
    public function __construct(private OfferingClosures $offerings) {}

    public function kind(): string
    {
        return 'monthly';
    }

    public function build(string $periodKey): ?SealablePayload
    {
        if (! AccountingPeriod::isClosed($periodKey)) {
            return null;
        }

        $month = Month::fromKey($periodKey);
        $summary = MonthSummary::of($month);

        $lines = DB::select(<<<'SQL'
            SELECT e.id::text AS entry, l.line_number AS line, a.code, l.currency,
                   l.debit::text AS debit, l.credit::text AS credit, e.reversal_of::text AS reversal_of
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE e.month = ?
            ORDER BY e.id, l.line_number
        SQL, [$periodKey]);

        $leaves = [];

        foreach ($lines as $line) {
            $line = (array) $line;
            $leaves[] = Canonical::hash([
                'entry' => (string) $line['entry'],
                'line' => (int) $line['line'],
                'code' => (string) $line['code'],
                'currency' => trim((string) $line['currency']),
                'debit' => (string) $line['debit'],
                'credit' => (string) $line['credit'],
                'reversalOf' => $line['reversal_of'] === null ? null : (string) $line['reversal_of'],
            ]);
        }

        $offerings = $this->offerings->totals($periodKey);

        return new SealablePayload(
            [
                'kind' => 'monthly',
                'month' => $periodKey,
                'currencies' => $summary['currencies'],
                'byCode' => array_map(fn (array $code) => [
                    'code' => $code['code'],
                    'entries' => $code['entries'],
                    'ves' => $code['ves'],
                    'usd' => $code['usd'],
                    'cop' => $code['cop'],
                ], $summary['by_code']),
                'offerings' => $offerings,
            ],
            $leaves,
        );
    }

    public function closedPeriods(): array
    {
        return array_values(array_map(strval(...), AccountingPeriod::query()->orderBy('month')->pluck('month')->all()));
    }
}
