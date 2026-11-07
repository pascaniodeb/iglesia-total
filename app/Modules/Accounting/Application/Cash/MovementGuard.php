<?php

namespace App\Modules\Accounting\Application\Cash;

use App\Modules\Accounting\Application\Periods\PeriodSchedule;
use App\Modules\Accounting\Infrastructure\Models\AccountingPeriod;
use App\Modules\Shared\Money\Currency;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\ChurchCalendar;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The rules every hand-recorded movement passes, in plain words:
 * - its date is inside an open month (each month owns its movements; at most two are open) and
 *   never in the future;
 * - after it is written, the cash of each currency it reduces never goes below zero from its
 *   date to today (D4).
 */
final readonly class MovementGuard
{
    public function __construct(private PeriodSchedule $schedule, private AvailableCash $cash) {}

    /**
     * @throws ValidationException
     */
    public function assertOpenDate(string $date, string $field = 'entry_date'): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([$field => __('Choose a valid date.')]);
        }

        if ($date > ChurchCalendar::today()->format('Y-m-d')) {
            throw ValidationException::withMessages([$field => __('A movement cannot be dated in the future.')]);
        }

        $month = substr($date, 0, 7);
        $window = $this->schedule->openWindow();

        if (AccountingPeriod::isClosed($month) || ($window['from'] !== null && $month < $window['from']) || $month > $window['to']) {
            throw ValidationException::withMessages([$field => $window['from'] === null
                ? __('The month :month is not open for movements.', ['month' => $month])
                : __('Only :from and :to are open for movements.', ['from' => $window['from'], 'to' => $window['to']])]);
        }
    }

    /**
     * Called before writing: what is available on that day (for the message).
     */
    public function availableBefore(Currency $currency, string $date): Money
    {
        return $this->cash->on($currency, $date);
    }

    /**
     * Called after writing, inside the same transaction: the cash never went below zero.
     *
     * @throws ValidationException
     */
    public function assertNoOverdraft(Currency $currency, string $date, Money $availableBefore, Money $leaving, string $field = 'amount'): void
    {
        if (! $this->cash->lowestFrom($currency, $date)->isNegative()) {
            return;
        }

        $missing = $leaving->minus($availableBefore);

        throw ValidationException::withMessages([$field => __('There is not enough :currency: available :available, missing :missing.', [
            'currency' => $currency->value,
            'available' => self::format($availableBefore),
            'missing' => self::format($missing->isNegative() ? Money::zero($currency) : $missing),
        ])]);
    }

    /**
     * "141000.50" → "141.000,50", on the decimal text (never through a float).
     */
    private static function format(Money $money): string
    {
        [$integer, $fraction] = explode('.', ltrim($money->amount(), '-'));
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return ($money->isNegative() ? '-' : '').$grouped.','.$fraction;
    }
}
