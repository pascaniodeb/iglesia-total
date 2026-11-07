<?php

namespace App\Modules\Accounting\Application\Cash;

use App\Modules\Accounting\Application\ClosedOfferingCash;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\ChurchCalendar;
use Illuminate\Support\Facades\DB;

/**
 * What can be spent (D4: no overdraft, ever), per currency.
 *
 *   cash(day) = CAJA movements of the journal up to that day, without the monthly offering
 *               entries + offerings of closed weeks up to that day, each on its own date
 *
 * An expense dated on a day is possible only if cash never goes below its amount from that day
 * to today: the money must be there on its date and must not be needed afterwards by what was
 * already spent. Offerings of weeks still open do not count.
 *
 * Callers that write must hold lock() for the currency, so two expenses at the same time cannot
 * spend the same money.
 */
final readonly class AvailableCash
{
    public function __construct(private ClosedOfferingCash $offerings) {}

    public static function lock(Currency $currency): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["available_cash:{$currency->value}"]);
    }

    /**
     * The most that can leave the cash on that day (zero or more): the lowest the running
     * balance gets from that day (inclusive) to today.
     */
    public function on(Currency $currency, string $day): Money
    {
        $lowest = $this->lowestFrom($currency, $day);

        return $lowest->isNegative() ? Money::zero($currency) : $lowest;
    }

    /**
     * The lowest running balance from that day to today, possibly negative: what a write checks
     * after it is done, so a correction (reverse and record again) is judged by its result.
     */
    public function lowestFrom(Currency $currency, string $day): Money
    {
        $today = ChurchCalendar::today()->format('Y-m-d');
        $running = Money::zero($currency);
        $lowest = null;

        foreach ($this->deltas($currency, max($day, $today)) as $date => $delta) {
            if ($date > $day && $lowest === null) {
                $lowest = $running;
            }

            $running = $running->plus($delta);

            if ($date >= $day) {
                $lowest = $lowest === null || $lowest->isGreaterThan($running) ? $running : $lowest;
            }
        }

        return $lowest ?? $running;
    }

    /**
     * Net change of the cash per day, oldest first.
     *
     * @return array<string, Money>
     */
    private function deltas(Currency $currency, string $until): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT e.entry_date::text AS day, sum(l.debit - l.credit)::numeric(18,2)::text AS delta
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounting_accounts a ON a.id = l.account_id
            WHERE a.system_function = 'cash'
              AND l.currency = ?
              AND e.entry_date <= ?
              AND e.origin_type <> 'monthly_offering_closure'
            GROUP BY e.entry_date
        SQL, [$currency->value, $until]);

        $deltas = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $deltas[(string) $row['day']] = Money::of((string) $row['delta'], $currency);
        }

        foreach ($this->offerings->dailyTotals($currency, $until) as $day => $total) {
            $deltas[$day] = ($deltas[$day] ?? Money::zero($currency))->plus(Money::of($total, $currency));
        }

        ksort($deltas);

        return $deltas;
    }
}
