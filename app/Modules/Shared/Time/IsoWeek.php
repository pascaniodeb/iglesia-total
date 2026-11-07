<?php

namespace App\Modules\Shared\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An ISO week (Monday to Sunday, "2026-W45"), the unit of a weekly closure. The key is the same
 * the database generates for each offering (iso_week_key()).
 */
final readonly class IsoWeek
{
    private function __construct(public DateTimeImmutable $start) {}

    public static function fromKey(string $key): self
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $key, $parts) !== 1) {
            throw new InvalidArgumentException("Not an ISO week: \"{$key}\".");
        }

        $week = new self((new DateTimeImmutable)->setISODate((int) $parts[1], (int) $parts[2])->setTime(0, 0));

        if ($week->key() !== $key) {
            throw new InvalidArgumentException("Not an ISO week: \"{$key}\".");
        }

        return $week;
    }

    public static function containing(DateTimeImmutable $date): self
    {
        $monday = $date->setTime(0, 0)->modify('-'.((int) $date->format('N') - 1).' days');

        return new self($monday);
    }

    public function key(): string
    {
        return $this->start->format('o-\WW');
    }

    public function end(): DateTimeImmutable
    {
        return $this->start->modify('+6 days');
    }

    public function previous(): self
    {
        return new self($this->start->modify('-7 days'));
    }

    public function next(): self
    {
        return new self($this->start->modify('+7 days'));
    }

    public function hasEnded(DateTimeImmutable $today): bool
    {
        return $this->end()->format('Y-m-d') <= $today->format('Y-m-d');
    }

    /**
     * The months ("2026-10", "2026-11") with at least one day in this week.
     *
     * @return list<string>
     */
    public function months(): array
    {
        return array_values(array_unique([$this->start->format('Y-m'), $this->end()->format('Y-m')]));
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
