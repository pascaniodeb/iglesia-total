<?php

namespace App\Modules\Shared\Time;

use DateTimeImmutable;
use IntlDateFormatter;
use InvalidArgumentException;

/**
 * The span of a report: a month, a quarter, a semester, a year or a range of whole months.
 *
 * Its key is a string because it travels in URLs people share, with the same shapes as the old
 * system: "2026-06", "2026-Q2", "2026-S1", "2026", and "2026-01..2026-05" for a range. A period is
 * always made of whole months, so a report never takes half a month by a boundary mistake.
 */
final readonly class ReportPeriod
{
    public const string MONTH = 'month';

    public const string QUARTER = 'quarter';

    public const string SEMESTER = 'semester';

    public const string YEAR = 'year';

    public const string RANGE = 'range';

    public const array GRANULARITIES = [self::MONTH, self::QUARTER, self::SEMESTER, self::YEAR, self::RANGE];

    private const array SPAN = [self::MONTH => 1, self::QUARTER => 3, self::SEMESTER => 6, self::YEAR => 12];

    private function __construct(
        public string $granularity,
        public Month $first,
        public Month $last,
    ) {}

    public static function fromKey(string $granularity, string $key): self
    {
        $invalid = new InvalidArgumentException("Not a {$granularity} period: \"{$key}\".");

        return match ($granularity) {
            self::MONTH => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) === 1
                ? new self(self::MONTH, Month::fromKey($key), Month::fromKey($key))
                : throw $invalid,
            self::QUARTER => preg_match('/^(\d{4})-Q([1-4])$/', $key, $match) === 1
                ? self::spanning(self::QUARTER, (int) $match[1], (int) $match[2])
                : throw $invalid,
            self::SEMESTER => preg_match('/^(\d{4})-S([12])$/', $key, $match) === 1
                ? self::spanning(self::SEMESTER, (int) $match[1], (int) $match[2])
                : throw $invalid,
            self::YEAR => preg_match('/^\d{4}$/', $key) === 1
                ? self::spanning(self::YEAR, (int) $key, 1)
                : throw $invalid,
            self::RANGE => preg_match('/^(\d{4}-(?:0[1-9]|1[0-2]))\.\.(\d{4}-(?:0[1-9]|1[0-2]))$/', $key, $match) === 1 && $match[1] <= $match[2]
                ? new self(self::RANGE, Month::fromKey($match[1]), Month::fromKey($match[2]))
                : throw $invalid,
            default => throw new InvalidArgumentException("Not a period granularity: \"{$granularity}\"."),
        };
    }

    /**
     * The period of a granularity that contains a month (a range becomes that single month).
     */
    public static function containing(string $granularity, Month $month): self
    {
        $year = (int) $month->firstDay->format('Y');
        $index = intdiv((int) $month->firstDay->format('n') - 1, self::SPAN[$granularity] ?? 1) + 1;

        return match ($granularity) {
            self::QUARTER, self::SEMESTER, self::YEAR => self::spanning($granularity, $year, $index),
            default => new self($granularity === self::RANGE ? self::RANGE : self::MONTH, $month, $month),
        };
    }

    private static function spanning(string $granularity, int $year, int $index): self
    {
        $span = self::SPAN[$granularity];
        $first = Month::fromKey(sprintf('%04d-%02d', $year, ($index - 1) * $span + 1));

        return new self($granularity, $first, Month::fromKey(sprintf('%04d-%02d', $year, $index * $span)));
    }

    public function key(): string
    {
        $year = $this->first->firstDay->format('Y');
        $month = (int) $this->first->firstDay->format('n');

        return match ($this->granularity) {
            self::MONTH => $this->first->key(),
            self::QUARTER => "{$year}-Q".intdiv($month - 1, 3) + 1,
            self::SEMESTER => "{$year}-S".intdiv($month - 1, 6) + 1,
            self::YEAR => $year,
            default => "{$this->first->key()}..{$this->last->key()}",
        };
    }

    public function firstDay(): DateTimeImmutable
    {
        return $this->first->firstDay;
    }

    public function lastDay(): DateTimeImmutable
    {
        return $this->last->lastDay();
    }

    /**
     * Every month of the period, in order.
     *
     * @return list<Month>
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->first; $month->key() <= $this->last->key(); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * How a report heading names the period. Periods longer than a month name their first and last
     * months: "2.º trimestre de 2026 (abril – junio)" does not ask the reader to know them by heart.
     */
    public function label(): string
    {
        $year = $this->first->firstDay->format('Y');
        $first = self::monthName($this->first);
        $last = self::monthName($this->last);
        $index = match ($this->granularity) {
            self::QUARTER => intdiv((int) $this->first->firstDay->format('n') - 1, 3) + 1,
            self::SEMESTER => intdiv((int) $this->first->firstDay->format('n') - 1, 6) + 1,
            default => 0,
        };

        $label = match ($this->granularity) {
            self::MONTH => "{$first} de {$year}",
            self::QUARTER => "{$index}.º trimestre de {$year} ({$first} – {$last})",
            self::SEMESTER => "{$index}.º semestre de {$year} ({$first} – {$last})",
            self::YEAR => "Año {$year}",
            default => $this->first->equals($this->last)
                ? "{$first} de {$year}"
                : ($year === $this->last->firstDay->format('Y')
                    ? "{$first} – {$last} de {$year}"
                    : "{$first} de {$year} – {$last} de {$this->last->firstDay->format('Y')}"),
        };

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    /**
     * How a selector or a title names the period, without its months: "4.º trimestre de 2026".
     */
    public function shortLabel(): string
    {
        return match ($this->granularity) {
            self::QUARTER, self::SEMESTER => preg_replace('/ \(.*\)$/u', '', $this->label()) ?? $this->label(),
            default => $this->label(),
        };
    }

    private static function monthName(Month $month): string
    {
        return (string) (new IntlDateFormatter('es', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'LLLL'))->format($month->firstDay);
    }
}
