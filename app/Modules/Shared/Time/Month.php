<?php

namespace App\Modules\Shared\Time;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A calendar month ("2026-11"): the unit of the monthly offering closure and of accounting.
 */
final readonly class Month
{
    private function __construct(public DateTimeImmutable $firstDay) {}

    public static function fromKey(string $key): self
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $key) !== 1) {
            throw new InvalidArgumentException("Not a month: \"{$key}\".");
        }

        return new self(new DateTimeImmutable("{$key}-01"));
    }

    public static function containing(DateTimeImmutable $date): self
    {
        return self::fromKey($date->format('Y-m'));
    }

    public function key(): string
    {
        return $this->firstDay->format('Y-m');
    }

    public function lastDay(): DateTimeImmutable
    {
        return $this->firstDay->modify('last day of this month');
    }

    public function previous(): self
    {
        return new self($this->firstDay->modify('-1 month'));
    }

    public function next(): self
    {
        return new self($this->firstDay->modify('+1 month'));
    }

    /**
     * Every ISO week with at least one day in the month, in order (the first and the last can be
     * shared with the neighbouring months).
     *
     * @return list<IsoWeek>
     */
    public function weeks(): array
    {
        $weeks = [];

        for ($week = IsoWeek::containing($this->firstDay); $week->start <= $this->lastDay(); $week = $week->next()) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
